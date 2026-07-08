<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\RegisterDTO;
use App\Entity\Resource;
use App\Entity\ScrapedResource;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\ResourceStatus;
use App\Enum\ScrapedResourceStatus;
use App\Repository\OrganizationProfileRepository;
use App\Repository\ResourceAlertRepository;
use App\Repository\ResourceRepository;
use App\Repository\ResourceTypeRepository;
use App\Repository\ScrapedResourceRepository;
use App\Repository\UserRepository;
use App\Repository\DisciplineRepository;
use App\Service\AuthService;
use App\Service\DeadlineParserService;
use App\Service\DisciplineMapperService;
use App\Service\HtmlSanitizerService;
use App\Service\NotificationService;
use App\Service\OpportunityToSourcePromoter;
use App\Service\ResourceTypeMapper;
use App\Service\StructureService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Interface d'administration de BazaArt.
 *
 * Protégée par ROLE_ADMIN (doublement : via IsGranted ici + access_control dans security.yaml).
 * Permet de modérer les ressources soumises et de gérer les utilisateurs.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'app_admin_')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResourceRepository $resourceRepository,
        private readonly UserRepository $userRepository,
        private readonly OrganizationProfileRepository $orgRepository,
        private readonly AuthService $authService,
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
        private readonly ResourceTypeRepository $resourceTypeRepository,
        private readonly StructureService $structureService,
        // NotificationService : crée les notifications in-app (ResourceValidated, ResourceMatch)
        private readonly NotificationService $notificationService,
        // ResourceAlertRepository : trouve les alertes correspondant à une ressource publiée
        private readonly ResourceAlertRepository $resourceAlertRepository,
        // KernelInterface : nécessaire pour instancier l'Application Console
        // et exécuter la commande de scraping dans le même processus PHP.
        private readonly KernelInterface $kernel,
        // DisciplineMapperService : mappe les libellés disciplines (texte libre) vers
        // les entités Discipline BDD lors de la propagation ScrapedResource → Resource.
        // ADR-0016 Lot 1.
        private readonly DisciplineMapperService $disciplineMapper,
        // OpportunityToSourcePromoter : orchestre la transformation d'une opportunité
        // scrapée en source de scraping (action "En faire une source").
        private readonly OpportunityToSourcePromoter $toSourcePromoter,
        // ResourceTypeMapper : convertit un libellé de type texte libre (ScrapedResource::$type,
        // rempli par le LLM) vers l'entité ResourceType canonique correspondante. Remplace
        // l'ancien fallback findOneBy(...) ?? findOneBy('Autre') ?? findAll()[0] qui pouvait
        // retomber sur un type totalement arbitraire (voir docblock de ResourceTypeMapper).
        private readonly ResourceTypeMapper $resourceTypeMapper,
        // DeadlineParserService : centralise le parsing "deadline texte → DateTime",
        // avec borne de plausibilité (rejette les années aberrantes, ex: 2020).
        // Remplace les 3 blocs dupliqués de DateTime::createFromFormat('d/m/Y'...) ?: ...
        // qui existaient dans verifyScrapedOpportunity(), editResource() et
        // documentationScrapedOpportunity().
        private readonly DeadlineParserService $deadlineParser,
        // HtmlSanitizerService::sanitizeRichText() : même correctif XSS que dans
        // ResourceService — ici appliqué aux descriptions écrites par CE contrôleur
        // (conversion ScrapedResource → Resource par un admin, et édition manuelle
        // d'une Resource déjà publiée), qui alimentent le même champ affiché en
        // `|raw` dans resource/show.html.twig.
        private readonly HtmlSanitizerService $htmlSanitizer,
    ) {}

    /**
     * Tableau de bord admin — vue d'ensemble des chiffres clés.
     */
    #[Route('', name: 'dashboard')]
    public function dashboard(): Response
    {
        // Compteurs pour les statistiques
        $pendingCount      = count($this->resourceRepository->findPending());
        // A3 : countPublished() à la place de count(findPublished()).
        // findPublished() sans pagination charge TOUTES les entités Resource en mémoire
        // juste pour compter — O(n) objets Doctrine hydratés inutilement.
        // countPublished() fait un SELECT COUNT(r.id) SQL → un seul scalaire, O(1).
        // hideExpired: false → l'admin voit TOUTES les publiées, même celles expirées
        // (seul le catalogue public masque les deadlines passées via hideExpired: true).
        $publishedCount    = $this->resourceRepository->countPublished(hideExpired: false);
        $totalUsers        = count($this->userRepository->findAll());
        $totalOrgs         = count($this->orgRepository->findAll());
        $verifiedOrgs      = count($this->orgRepository->findVerified());
        $pendingResources  = $this->resourceRepository->findPending();

        return $this->render('admin/dashboard.html.twig', [
            'pendingCount'   => $pendingCount,
            'publishedCount' => $publishedCount,
            'totalUsers'     => $totalUsers,
            'totalOrgs'      => $totalOrgs,
            'verifiedOrgs'   => $verifiedOrgs,
            // On affiche les 5 premières ressources en attente directement sur le dashboard
            'pendingResources' => array_slice($pendingResources, 0, 5),
            // Widget scraping : nombre d'opportunités en attente + date du dernier scraping
            // Utilisés dans le shortcut "Scraping Sheets" du dashboard pour enrichir l'affichage
            'scrapingPendingCount' => $this->scrapedResourceRepository->countPending(),
            'latestScrapedAt'      => $this->scrapedResourceRepository->findLatestScrapedAt(),
            // Opportunites a deadline passee qui n'ont pas encore ete archivees/rejetees.
            // Affiche un avertissement sur le dashboard pour inciter l'admin au nettoyage.
            // COUNT SQL pur : pas de chargement d'entites.
            'expiredScrapedCount'  => $this->scrapedResourceRepository->countExpired(),
        ]);
    }

    /**
     * Liste complète des ressources en attente de modération.
     */
    #[Route('/resources/pending', name: 'resources_pending')]
    public function resourcesPending(): Response
    {
        $resources = $this->resourceRepository->findPending();

        return $this->render('admin/resources_pending.html.twig', [
            'resources' => $resources,
        ]);
    }

    /**
     * Publie une ressource (statut pending → published).
     * Utilise un token CSRF pour sécuriser l'action POST.
     */
    #[Route('/resources/{id}/publish', name: 'resource_publish', methods: ['POST'])]
    public function publishResource(int $id, Request $request): Response
    {
        // Vérification du token CSRF pour éviter les attaques CSRF
        if (!$this->isCsrfTokenValid('resource_action_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_resources_pending');
        }

        $resource = $this->resourceRepository->find($id);
        if ($resource === null) {
            throw $this->createNotFoundException('Ressource introuvable.');
        }

        $resource->setStatus(ResourceStatus::Published);

        // Renseigne les métadonnées de validation manuelle (CDC §5.2) :
        // - validatedAt : date à laquelle l'admin a validé
        // - validatedBy : qui a validé (l'admin connecté)
        // - publishedAt : date de première publication (si pas encore renseignée)
        //
        // On distingue publishedAt (première mise en ligne) de validatedAt (décision admin).
        // Pour les ressources auto-publiées, publishedAt est déjà renseigné à la création.
        // Ici on ne l'écrase que s'il est null.
        /** @var User $admin */
        $admin = $this->getUser();
        $resource->setValidatedAt(new \DateTime());
        $resource->setValidatedBy($admin);
        if ($resource->getPublishedAt() === null) {
            $resource->setPublishedAt(new \DateTime());
        }

        $this->em->flush();

        // ── Notification ResourceValidated → auteur de la ressource ──────────
        //
        // On notifie le soumetteur (artiste ou structure) que sa ressource a été validée.
        // getSubmittedBy() retourne toujours un User (non-nullable sur l'entité Resource).
        $submitter = $resource->getSubmittedBy();
        $this->notificationService->create(
            recipient: $submitter,
            type: NotificationType::ResourceValidated,
            relatedEntityType: 'resource',
            relatedEntityId: $resource->getId(),
            data: [
                'resourceTitle' => $resource->getTitle(),
                // On précise le statut pour que le template Twig puisse afficher
                // "Votre ressource X a été validée" ou "... refusée" selon le cas
                'status' => 'validée',
            ],
        );

        // ── Notifications ResourceMatch → utilisateurs avec alertes correspondantes ──
        //
        // Maintenant que la ressource est publiée, on notifie in-app les utilisateurs
        // dont les préférences d'alertes correspondent à cette ressource.
        //
        // Note : ResourceAlertService gère aussi les emails batch (cron quotidien).
        // Ici on envoie uniquement la notification in-app immédiate.
        // Les deux canaux (in-app + email) sont complémentaires et indépendants.
        $matchingAlerts = $this->resourceAlertRepository->findMatchingForResource($resource);
        foreach ($matchingAlerts as $alert) {
            // Exclure le soumetteur : il reçoit déjà ResourceValidated ci-dessus.
            // Lui envoyer aussi ResourceMatch serait redondant et confus.
            if ($alert->getUser()->getId() === $submitter->getId()) {
                continue;
            }
            $this->notificationService->create(
                recipient: $alert->getUser(),
                type: NotificationType::ResourceMatch,
                relatedEntityType: 'resource',
                relatedEntityId: $resource->getId(),
                data: [
                    'resourceTitle' => $resource->getTitle(),
                ],
            );
        }

        $this->addFlash('success', sprintf('La ressource "%s" a été publiée.', $resource->getTitle()));
        return $this->redirectToRoute('app_admin_resources_pending');
    }

    /**
     * Rejette une ressource (statut pending → rejected).
     */
    #[Route('/resources/{id}/reject', name: 'resource_reject', methods: ['POST'])]
    public function rejectResource(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('resource_action_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_resources_pending');
        }

        $resource = $this->resourceRepository->find($id);
        if ($resource === null) {
            throw $this->createNotFoundException('Ressource introuvable.');
        }

        $resource->setStatus(ResourceStatus::Rejected);
        $this->em->flush();

        // ── Notification ResourceValidated (refus) → auteur de la ressource ──
        //
        // Même type de notification que pour la publication, mais avec status = 'refusée'.
        // L'enum NotificationType::ResourceValidated couvre les deux cas (validation + rejet) :
        // le champ data['status'] permet au template Twig de distinguer les deux situations.
        $submitter = $resource->getSubmittedBy();
        $this->notificationService->create(
            recipient: $submitter,
            type: NotificationType::ResourceValidated,
            relatedEntityType: 'resource',
            relatedEntityId: $resource->getId(),
            data: [
                'resourceTitle' => $resource->getTitle(),
                'status'        => 'refusée',
            ],
        );

        $this->addFlash('success', sprintf('La ressource "%s" a été rejetée.', $resource->getTitle()));
        return $this->redirectToRoute('app_admin_resources_pending');
    }

    /**
     * Remet une ressource rejetée ou publiée en attente (utile pour corriger une erreur).
     */
    #[Route('/resources/{id}/reset', name: 'resource_reset', methods: ['POST'])]
    public function resetResource(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('resource_action_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_resources_all');
        }

        $resource = $this->resourceRepository->find($id);
        if ($resource === null) {
            throw $this->createNotFoundException('Ressource introuvable.');
        }

        $resource->setStatus(ResourceStatus::PendingValidation);
        $this->em->flush();

        $this->addFlash('success', sprintf('La ressource "%s" a été remise en attente.', $resource->getTitle()));
        return $this->redirectToRoute('app_admin_resources_all');
    }

    /**
     * Vue de toutes les ressources (tous statuts confondus) avec filtre.
     */
    #[Route('/resources', name: 'resources_all')]
    public function resourcesAll(Request $request): Response
    {
        $statusFilter = $request->query->get('status', 'all');

        // Récupère les ressources selon le filtre de statut.
        // Le filtre arrive de l'URL en tant que string ('pending', 'published', 'rejected'...).
        // On le compare donc à la valeur backed de l'enum (->value).
        //
        // IMPORTANT : on applique une limite de 100 sur les requêtes findBy() sans critère
        // réduit (statut "all" ou "rejected"), pour éviter tout problème mémoire si la table
        // grossit. findPending() et findPublished() ont leur propre logique dans le repository.
        if ($statusFilter === ResourceStatus::PendingValidation->value) {
            // Onglet "En attente" — délègue au repository qui charge déjà les relations (évite N+1)
            $resources = $this->resourceRepository->findPending();
        } elseif ($statusFilter === ResourceStatus::Published->value) {
            // Onglet "Publiées" — même logique, le repository gère l'eager loading
            $resources = $this->resourceRepository->findPublished();
        } elseif ($statusFilter === ResourceStatus::Rejected->value) {
            // Onglet "Rejetées" — précédemment non géré, le cas tombait dans le else "toutes"
            // On utilise findBy() avec une limite de sécurité : les rejetées sont rarement
            // des centaines, mais on protège quand même contre une table volumineuse.
            $resources = $this->resourceRepository->findBy(
                ['status' => ResourceStatus::Rejected],
                ['createdAt' => 'DESC'],
                100  // Limite de sécurité mémoire
            );
        } else {
            // Onglet "Toutes" (valeur par défaut ou valeur inconnue) — toutes les ressources,
            // triées par date décroissante. Limite de 100 pour protéger la mémoire PHP.
            $resources = $this->resourceRepository->findBy(
                [],
                ['createdAt' => 'DESC'],
                100  // Limite de sécurité mémoire
            );
        }

        return $this->render('admin/resources_all.html.twig', [
            'resources'    => $resources,
            'statusFilter' => $statusFilter,
        ]);
    }

    /**
     * Liste de tous les utilisateurs + formulaire de création.
     */
    #[Route('/users', name: 'users', methods: ['GET', 'POST'])]
    public function users(Request $request): Response
    {
        // Traitement du formulaire de création d'utilisateur
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_create', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide.');
                return $this->redirectToRoute('app_admin_users');
            }

            $data = $request->request->all();
            $dto  = RegisterDTO::fromArray($data);

            if ($dto === null) {
                $this->addFlash('error', 'Email et mot de passe sont obligatoires.');
                return $this->redirectToRoute('app_admin_users');
            }

            if (!$dto->isEmailValid()) {
                $this->addFlash('error', 'L\'adresse email n\'est pas valide.');
                return $this->redirectToRoute('app_admin_users');
            }

            if (!$dto->isPasswordStrong()) {
                $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères.');
                return $this->redirectToRoute('app_admin_users');
            }

            // AuthService::register() retourne null si l'email est déjà pris
            $user = $this->authService->register($dto);

            if ($user === null) {
                $this->addFlash('error', sprintf('Un compte existe déjà avec l\'email "%s".', $dto->email));
                return $this->redirectToRoute('app_admin_users');
            }

            // Si la case "admin" est cochée, on lui ajoute ROLE_ADMIN.
            // On CONSERVE ROLE_ARTIST (posé par AuthService::register, ADR-0029) pour
            // ne pas recréer un compte "user seul" si l'admin est rétrogradé plus tard.
            if ($request->request->get('is_admin')) {
                $user->setRoles(['ROLE_ARTIST', 'ROLE_ADMIN']);
                $this->em->flush();
            }

            $this->addFlash('success', sprintf('Utilisateur "%s" créé avec succès.', $user->getEmail()));
            return $this->redirectToRoute('app_admin_users');
        }

        $users = $this->userRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    /**
     * Supprime un utilisateur et toutes ses données associées.
     * Protégé par CSRF. On interdit la suppression de son propre compte.
     */
    #[Route('/users/{id}/delete', name: 'user_delete', methods: ['POST'])]
    public function deleteUser(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_users');
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        // Sécurité : un admin ne peut pas supprimer son propre compte via cette interface
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte depuis l\'administration.');
            return $this->redirectToRoute('app_admin_users');
        }

        $email = $user->getEmail();

        // Doctrine supprime en cascade : profil artiste, profil organisation, ressources soumises
        // grâce aux cascade:['remove'] définis dans les relations de User
        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', sprintf('L\'utilisateur "%s" a été supprimé.', $email));
        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * Bascule le rôle admin d'un utilisateur (ajoute ou retire ROLE_ADMIN).
     */
    #[Route('/users/{id}/toggle-admin', name: 'user_toggle_admin', methods: ['POST'])]
    public function toggleAdmin(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user_toggle_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_users');
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        // Empêche l'admin de se retirer lui-même son propre rôle admin
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas modifier votre propre rôle admin.');
            return $this->redirectToRoute('app_admin_users');
        }

        $roles = $user->getRoles();

        if (in_array('ROLE_ADMIN', $roles, true)) {
            // Retire ROLE_ADMIN
            $roles = array_values(array_filter($roles, fn($r) => $r !== 'ROLE_ADMIN' && $r !== 'ROLE_USER'));
            // ADR-0029 : on garantit qu'un compte rétrogradé reste au moins artiste
            // (jamais "user seul"). Si ROLE_ARTIST manquait, on le rajoute.
            if (!in_array('ROLE_ARTIST', $roles, true)) {
                $roles[] = 'ROLE_ARTIST';
            }
            $user->setRoles($roles);
            $this->addFlash('success', sprintf('"%s" n\'est plus administrateur.', $user->getEmail()));
        } else {
            // Ajoute ROLE_ADMIN
            $roles[] = 'ROLE_ADMIN';
            $user->setRoles(array_unique(array_filter($roles, fn($r) => $r !== 'ROLE_USER')));
            $this->addFlash('success', sprintf('"%s" est maintenant administrateur.', $user->getEmail()));
        }

        $this->em->flush();
        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * Liste les opportunités scrapées stockées en base de données.
     * Affiche les "A vérifier" en premier, puis les "Vérifié", "Rejeté", "Archivé"
     * et enfin le 5eme onglet "Deadline passée" (vue de nettoyage admin).
     *
     * Le 5eme onglet "Deadline passée" affiche toutes les opportunités en statut
     * pending ou verified dont la date structurée (deadlineDate) est passée.
     * C'est une vue de nettoyage : l'admin peut les rejeter ou les archiver
     * manuellement. Elles ne sont PAS automatiquement archivées ici
     * (archiveExpired() est deja appele par ScrapeOpportunitiesCommand).
     *
     * Note : le filtre ?filtre=expired dans l'URL n'est PAS necessaire ici
     * car le template gere deja les onglets via JS cote client.
     * On passe simplement la liste `expired` au template.
     */
    #[Route('/scraped-opportunities', name: 'scraped_opportunities')]
    public function scrapedOpportunities(): Response
    {
        // Recupere les opportunites par statut pour alimenter les 5 onglets
        $pending  = $this->scrapedResourceRepository->findPending();
        $verified = $this->scrapedResourceRepository->findVerified();
        // Onglet "Rejete" : opportunites jugees hors sujet par l'admin
        $rejected = $this->scrapedResourceRepository->findRejected();
        // Onglet "Archive" : opportunites expirées (deadline passée, archivage automatique)
        // ou archivées manuellement. Consultation uniquement, pas d'action disponible.
        $archived        = $this->scrapedResourceRepository->findArchived();
        $latestScrapedAt = $this->scrapedResourceRepository->findLatestScrapedAt();

        // Onglet 5 : "Deadline passée" — vue de nettoyage admin.
        // Opportunites en statut pending ou verified dont la deadlineDate est depassee.
        // Ces items ne seront pas rescrapés (URL connue) mais l'admin doit nettoyer
        // le backlog pour eviter de valider par erreur une opportunite perimee.
        // Note : distinct de "Archive" (qui a status=archived) ; ici on voit des
        // pending/verified qui auraient du etre archives mais ne le sont pas encore
        // (ex : deadlineDate remplie APRES le dernier run de ScrapeOpportunitiesCommand).
        $expired = $this->scrapedResourceRepository->findExpired();

        return $this->render('admin/scraped_opportunities.html.twig', [
            'pending'         => $pending,
            'verified'        => $verified,
            'rejected'        => $rejected,
            'archived'        => $archived,
            'expired'         => $expired,
            'latestScrapedAt' => $latestScrapedAt,
        ]);
    }

    /**
     * Valide une opportunité scrapée : change son statut à "verified"
     * et crée une Resource publiée dans le tableau des Opportunités.
     *
     * L'admin connecté est défini comme auteur de l'import.
     */
    #[Route('/scraped-opportunities/{id}/verify', name: 'scraped_opportunity_verify', methods: ['POST'])]
    public function verifyScrapedOpportunity(int $id, Request $request): Response
    {
        // Vérification CSRF
        if (!$this->isCsrfTokenValid('verify_scraped_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Récupère l'opportunité scrapée
        $scraped = $this->scrapedResourceRepository->find($id);
        if ($scraped === null) {
            throw $this->createNotFoundException('Opportunité introuvable.');
        }

        // Mappe le libellé libre du scraper/LLM vers le ResourceType canonique.
        // ResourceTypeMapper ne retourne jamais null : repli garanti sur "Autre"
        // (voir son docblock pour l'explication complète du bug corrigé ici).
        $resourceType = $this->resourceTypeMapper->mapLabelToType($scraped->getType());

        // Convertit la date limite texte en objet DateTime si possible.
        // DeadlineParserService::parse() gère les 3 formats (ISO, FR court, FR long)
        // ET rejette les années hors bornes de plausibilité (ex: deadlines "2020").
        $parsedDeadline = $scraped->getDeadline() !== null
            ? $this->deadlineParser->parse($scraped->getDeadline())
            : null;
        // Resource::deadline est un champ Doctrine 'date' (DateTime MUTABLE). Or
        // DeadlineParserService::parse() renvoie un DateTimeImmutable → il faut le
        // convertir, sinon Doctrine lève "Could not convert DateTimeImmutable to
        // DateType" au flush (500 sur "Vérifier"). createFromInterface() préserve la date.
        $deadline = $parsedDeadline instanceof \DateTimeImmutable
            ? \DateTime::createFromInterface($parsedDeadline)
            : $parsedDeadline;

        // Crée la Resource publiée dans le tableau Opportunités
        //
        // Sécurité (audit XSS) : $scraped->getDescription() contient du HTML
        // produit par le LLM d'enrichissement (balises <p><ul><li><strong>
        // attendues d'après le prompt — cf. OpportunityEnrichmentService).
        // On passe quand même par sanitizeRichText() ici : le prompt LLM n'est
        // PAS une garantie de sécurité (prompt injection possible côté source
        // scrapée), et cette Resource est affichée avec `|raw` dans
        // resource/show.html.twig. Les balises légitimes du pipeline IA sont
        // dans la même liste blanche (p/ul/li/strong) donc aucune perte de
        // contenu attendue dans le cas nominal.
        $resource = new Resource();
        $resource->setTitle($scraped->getTitle());
        $resource->setDescription(
            $this->htmlSanitizer->sanitizeRichText($scraped->getDescription() ?: 'Description non disponible.')
        );
        $resource->setExternalUrl($scraped->getUrl());
        $resource->setDeadline($deadline);
        $resource->setResourceType($resourceType);
        $resource->setOrganization(null);

        /** @var User $admin */
        $admin = $this->getUser();
        $resource->setSubmittedBy($admin);

        // Publiée directement : l'admin a validé depuis la page scraping.
        // On renseigne tous les champs de traçabilité CDC §5.2 pour la cohérence
        // (même logique que publishResource() pour les soumissions manuelles).
        $now = new \DateTime();
        $resource->setStatus(ResourceStatus::Published);
        $resource->setSubmitterRole(\App\Enum\SubmitterRole::Admin);
        $resource->setAutoPublished(true);
        $resource->setPublishedAt($now);
        $resource->setValidatedAt($now);
        $resource->setValidatedBy($admin);

        // ── ADR-0016 Lot 1 : propagation des champs géo + niveau ─────────────
        //
        // On recopie city, country et experienceLevel depuis la ScrapedResource
        // vers la Resource publiée. Ces champs ont été remplis par le LLM
        // lors du scraping (LlmExtractorService → ScrapedResourcePersister).
        // Ils sont tous nullable : si vides (ancienne ScrapedResource sans LLM),
        // la Resource aura simplement ces champs à null.
        $resource->setCity($scraped->getCity());
        $resource->setCountry($scraped->getCountry());
        $resource->setExperienceLevel($scraped->getExperienceLevel());

        // ── ADR-0018 : propagation candidature + financement ─────────────────
        //
        // On recopie les 3 nouveaux champs (howToApply, fundingAmount, fundingType)
        // depuis la ScrapedResource vers la Resource publiée.
        // Tous nullable : les anciennes ScrapedResource sans LLM enrichi ADR-0018
        // auront ces champs à null — la Resource correspondante les aura aussi à null,
        // ce qui est cohérent (pas d'information à afficher).
        $resource->setHowToApply($scraped->getHowToApply());
        $resource->setFundingAmount($scraped->getFundingAmount());
        $resource->setFundingType($scraped->getFundingType());

        // ── ADR-0019 : propagation lien de candidature + logo ────────────────
        //
        // applicationUrl : URL du bouton "Candidater" distinct de l'URL source.
        //   Permet d'afficher un bouton CTA direct sur la page de détail de la Resource.
        //   Null = aucun lien distinct trouvé (le bouton n'est pas affiché en front).
        //
        // logoUrl : URL du logo de l'organisme émetteur, récupérée par LogoFetcherService.
        //   Null = aucun logo trouvé, le template affiche le badge "B" en fallback.
        //
        // Troncature défensive : la colonne BDD est limitée à 500 chars.
        // Même si ScrapedResourcePersister a déjà tronqué lors du scraping,
        // on applique la troncature ici aussi pour les cas où la valeur a pu être
        // mise à jour directement en back-office (admin qui édite manuellement).
        $rawApplicationUrl = $scraped->getApplicationUrl();
        $resource->setApplicationUrl(
            $rawApplicationUrl !== null ? mb_substr($rawApplicationUrl, 0, 500) : null
        );

        $rawLogoUrl = $scraped->getLogoUrl();
        $resource->setLogoUrl(
            $rawLogoUrl !== null ? mb_substr($rawLogoUrl, 0, 500) : null
        );

        // ── ADR-0016 Lot 1 : mapping disciplines texte → entités Discipline ──
        //
        // ScrapedResource::$disciplines est une chaîne CSV libre (ex: "Musique, Danse").
        // DisciplineMapperService la convertit en entités Discipline BDD pour la
        // relation ManyToMany Resource::$disciplines.
        //
        // On explose le CSV pour obtenir un tableau de libellés, puis on délègue
        // le matching au service (normalisation casse/accents, synonymes, sous-chaîne).
        // Les libellés sans correspondance sont ignorés — on ne crée pas de nouvelle discipline.
        if ($scraped->getDisciplines() !== null && $scraped->getDisciplines() !== '') {
            $disciplineLabels = array_map(
                'trim',
                explode(',', $scraped->getDisciplines())
            );
            $matchedDisciplines = $this->disciplineMapper->mapLabelsToEntities($disciplineLabels);
            foreach ($matchedDisciplines as $discipline) {
                $resource->addDiscipline($discipline);
            }
        }

        $this->em->persist($resource);

        // Marque l'opportunité scrapée comme vérifiée (elle reste visible dans le tableau)
        $scraped->setStatus(ScrapedResourceStatus::Verified);

        $this->em->flush();

        $this->addFlash('success', sprintf('"%s" vérifiée et ajoutée aux Opportunités.', $scraped->getTitle()));
        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }

    /**
     * Valide le profil d'une organisation (isVerified → true).
     */
    #[Route('/organizations/{id}/verify', name: 'org_verify', methods: ['POST'])]
    public function verifyOrganization(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('org_verify_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_users');
        }

        $org = $this->orgRepository->find($id);
        if ($org === null) {
            throw $this->createNotFoundException('Organisation introuvable.');
        }

        $org->setIsVerified(true);
        $this->em->flush();

        $this->addFlash('success', sprintf('L\'organisation "%s" a été vérifiée.', $org->getName()));
        return $this->redirectToRoute('app_admin_users');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GESTION DES STRUCTURES PARTENAIRES (CDC V3 §5.8)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Liste les candidatures Structure en attente de traitement.
     *
     * Critère : structureApplicationAt IS NOT NULL AND isStructurePartner = false.
     * Triées par date de candidature croissante (FIFO — les plus anciens d'abord).
     */
    #[Route('/structures/pending', name: 'structures_pending')]
    public function structuresPending(): Response
    {
        // Délègue la requête au repository — aucune logique de filtrage ici
        $pendingApplications = $this->orgRepository->findPendingStructureApplications();

        return $this->render('admin/structures_pending.html.twig', [
            'pendingApplications' => $pendingApplications,
        ]);
    }

    /**
     * Active un compte Structure — approuve la candidature d'une organisation.
     *
     * Ce que fait cette route :
     *   1. Vérifie le token CSRF (protection formulaire)
     *   2. Récupère l'OrganizationProfile par son $id
     *   3. Appelle StructureService::activateStructure() qui :
     *        - Passe isStructurePartner → true
     *        - Enregistre la date et le validateur
     *        - Ajoute ROLE_STRUCTURE au User
     *   4. Flash success + redirect
     *
     * Méthode POST uniquement (une action destructive ne doit jamais être en GET).
     * Le token CSRF 'structure_activate_{id}' est généré côté Twig.
     */
    #[Route('/structures/{id}/activate', name: 'structure_activate', methods: ['POST'])]
    public function activateStructure(int $id, Request $request): Response
    {
        // Vérification CSRF — le token doit correspondre à celui du formulaire Twig
        if (!$this->isCsrfTokenValid('structure_activate_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_structures_pending');
        }

        // Récupère le profil organisation ciblé
        $orgProfile = $this->orgRepository->find($id);
        if ($orgProfile === null) {
            throw $this->createNotFoundException('Profil organisation introuvable.');
        }

        // Récupère l'admin connecté (garanti non-null par @IsGranted('ROLE_ADMIN'))
        /** @var User $admin */
        $admin = $this->getUser();

        // Délègue l'activation au service (logique métier : roles, timestamps, flush)
        $this->structureService->activateStructure($orgProfile, $admin);

        $this->addFlash(
            'success',
            sprintf(
                'Le compte Structure "%s" a été activé. L\'organisation peut désormais publier ses opportunités.',
                $orgProfile->getName()
            )
        );

        return $this->redirectToRoute('app_admin_structures_pending');
    }

    /**
     * Rejette une candidature Structure — refuse la demande d'une organisation.
     *
     * Ce que fait cette route :
     *   1. Vérifie le token CSRF
     *   2. Récupère l'OrganizationProfile par son $id
     *   3. Appelle StructureService::rejectStructureApplication() qui :
     *        - Remet structureApplicationAt → null (l'org peut re-candidater)
     *        - isStructurePartner reste false
     *   4. Flash info + redirect
     *
     * Méthode POST uniquement (cohérence avec activate et sécurité CSRF).
     */
    #[Route('/structures/{id}/reject', name: 'structure_reject', methods: ['POST'])]
    public function rejectStructureApplication(int $id, Request $request): Response
    {
        // Vérification CSRF — token distinct de activate pour plus de granularité
        if (!$this->isCsrfTokenValid('structure_reject_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_structures_pending');
        }

        $orgProfile = $this->orgRepository->find($id);
        if ($orgProfile === null) {
            throw $this->createNotFoundException('Profil organisation introuvable.');
        }

        // Délègue le rejet au service
        $this->structureService->rejectStructureApplication($orgProfile);

        $this->addFlash(
            'info',
            sprintf(
                'La candidature de "%s" a été refusée. L\'organisation pourra re-candidater.',
                $orgProfile->getName()
            )
        );

        return $this->redirectToRoute('app_admin_structures_pending');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉDITION DES OPPORTUNITÉS SCRAPÉES ET DES RESSOURCES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Formulaire d'édition d'une opportunité scrapée.
     *
     * GET  → affiche le formulaire pré-rempli avec les valeurs actuelles.
     * POST → valide, met à jour l'entité, puis redirige vers la liste.
     *
     * Champs éditables : title, description, type, deadline, disciplines, url.
     * Champs NON éditables : status (géré par Vérifier/Rejeter), relevanceScore,
     * sourceSite (métadonnée scraping), scrapedAt (timestamp système).
     *
     * Sécurité : token CSRF 'edit_scraped_{id}' généré et vérifié ici.
     */
    #[Route('/scraped-opportunities/{id}/edit', name: 'scraped_opportunity_edit', methods: ['GET', 'POST'])]
    public function editScrapedOpportunity(int $id, Request $request): Response
    {
        // ── Chargement de l'entité (404 si inexistante) ──────────────────────
        $scraped = $this->scrapedResourceRepository->find($id);
        if ($scraped === null) {
            throw $this->createNotFoundException('Opportunité scrapée introuvable.');
        }

        // ── GET → on affiche le formulaire pré-rempli ──────────────────────
        if ($request->isMethod('GET')) {
            return $this->render('admin/scraped_opportunity_edit.html.twig', [
                'scraped' => $scraped,
            ]);
        }

        // ── POST → validation du token CSRF ─────────────────────────────────
        // Le token est propre à cet enregistrement ('edit_scraped_42' pour l'id=42)
        // pour qu'un token valide sur une page ne soit pas rejouable sur une autre.
        if (!$this->isCsrfTokenValid('edit_scraped_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
        }

        // ── Récupération et nettoyage des champs envoyés ────────────────────
        // trim() supprime les espaces parasites souvent introduits par les navigateurs.
        // null coalescing '' → null pour ne pas stocker des chaînes vides en BDD.
        $title       = trim((string) $request->request->get('title', ''));
        $description = trim((string) $request->request->get('description', ''));
        $type        = trim((string) $request->request->get('type', ''));
        $deadline    = trim((string) $request->request->get('deadline', ''));
        $disciplines = trim((string) $request->request->get('disciplines', ''));
        $url         = trim((string) $request->request->get('url', ''));

        // Convertit les chaînes vides en null (on ne veut pas stocker '')
        $description = $description !== '' ? $description : null;
        $type        = $type !== '' ? $type : null;
        $deadline    = $deadline !== '' ? $deadline : null;
        $disciplines = $disciplines !== '' ? $disciplines : null;
        $url         = $url !== '' ? $url : null;

        // ── Validation côté serveur ─────────────────────────────────────────
        // On valide manuellement sans Symfony Form Component pour rester
        // cohérent avec le style du contrôleur existant (pas de FormType ici).

        // Titre obligatoire (champ non nullable en BDD)
        if ($title === '') {
            $this->addFlash('error', 'Le titre est obligatoire.');
            return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
        }

        // Longueur max 255 (contrainte Doctrine : @Column length=255)
        if (mb_strlen($title) > 255) {
            $this->addFlash('error', 'Le titre ne peut pas dépasser 255 caractères.');
            return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
        }

        // Type : max 100 caractères (le select Twig filtre les valeurs connues, mais
        // un POST forgé pourrait soumettre n'importe quelle chaîne)
        if ($type !== null && mb_strlen($type) > 100) {
            $this->addFlash('error', 'Le type ne peut pas dépasser 100 caractères.');
            return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
        }

        // Deadline : max 150 caractères (free-text, mais on évite les abus)
        if ($deadline !== null && mb_strlen($deadline) > 150) {
            $this->addFlash('error', 'La deadline ne peut pas dépasser 150 caractères.');
            return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
        }

        // Disciplines : max 255 caractères
        if ($disciplines !== null && mb_strlen($disciplines) > 255) {
            $this->addFlash('error', 'Les disciplines ne peuvent pas dépasser 255 caractères.');
            return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
        }

        // URL : si renseignée, doit être une URL valide et ne pas dépasser 500 chars
        // (contrainte de longueur BDD + unicité sur ce champ)
        if ($url !== null) {
            if (mb_strlen($url) > 500) {
                $this->addFlash('error', 'L\'URL ne peut pas dépasser 500 caractères.');
                return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->addFlash('error', 'L\'URL renseignée n\'est pas valide (doit commencer par http:// ou https://).');
                return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
            }
        }

        // ── Mise à jour de l'entité ──────────────────────────────────────────
        $scraped->setTitle($title);
        $scraped->setDescription($description);
        $scraped->setType($type);
        $scraped->setDeadline($deadline);
        $scraped->setDisciplines($disciplines);
        $scraped->setUrl($url);

        // Pas besoin de persist() : l'entité est déjà gérée par Doctrine (managed).
        // flush() suffit — mais l'URL a une contrainte UNIQUE en BDD : on attrape
        // l'exception si l'admin entre une URL déjà utilisée par une autre opportunité.
        try {
            $this->em->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->em->clear(); // Remet Doctrine dans un état propre
            $this->addFlash('error', 'Cette URL est déjà utilisée par une autre opportunité scrapée.');
            return $this->redirectToRoute('app_admin_scraped_opportunity_edit', ['id' => $id]);
        }

        $this->addFlash('success', sprintf('L\'opportunité "%s" a été mise à jour.', $scraped->getTitle()));
        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }

    /**
     * Formulaire d'édition d'une Resource (opportunité de la Ressourcerie).
     *
     * GET  → affiche le formulaire pré-rempli.
     * POST → valide, met à jour, redirige vers la liste complète.
     *
     * Champs éditables : title, description, externalUrl, deadline, location.
     * Champs NON éditables : status, resourceType, submittedBy, organization,
     * disciplines, submitterRole (informations de soumission, gérées séparément).
     *
     * Sécurité : token CSRF 'edit_resource_{id}'.
     */
    #[Route('/resources/{id}/edit', name: 'resource_edit', methods: ['GET', 'POST'])]
    public function editResource(int $id, Request $request): Response
    {
        // ── Chargement de l'entité (404 si inexistante) ──────────────────────
        $resource = $this->resourceRepository->find($id);
        if ($resource === null) {
            throw $this->createNotFoundException('Ressource introuvable.');
        }

        // ── GET → on affiche le formulaire pré-rempli ──────────────────────
        if ($request->isMethod('GET')) {
            return $this->render('admin/resource_edit.html.twig', [
                'resource' => $resource,
            ]);
        }

        // ── POST → validation du token CSRF ─────────────────────────────────
        if (!$this->isCsrfTokenValid('edit_resource_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
        }

        // ── Récupération et nettoyage des champs ────────────────────────────
        $title       = trim((string) $request->request->get('title', ''));
        $description = trim((string) $request->request->get('description', ''));
        $externalUrl = trim((string) $request->request->get('externalUrl', ''));
        $deadlineStr = trim((string) $request->request->get('deadline', ''));
        $location    = trim((string) $request->request->get('location', ''));

        // Convertit les chaînes vides en null pour les champs nullable
        $externalUrl = $externalUrl !== '' ? $externalUrl : null;
        $deadlineStr = $deadlineStr !== '' ? $deadlineStr : null;
        $location    = $location !== '' ? $location : null;

        // ── Validation côté serveur ─────────────────────────────────────────

        // Titre : obligatoire, max 255 chars
        if ($title === '') {
            $this->addFlash('error', 'Le titre est obligatoire.');
            return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
        }
        if (mb_strlen($title) > 255) {
            $this->addFlash('error', 'Le titre ne peut pas dépasser 255 caractères.');
            return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
        }

        // Description : obligatoire (non nullable en BDD sur Resource)
        if ($description === '') {
            $this->addFlash('error', 'La description est obligatoire.');
            return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
        }

        // Sanitisation XSS AVANT la suite (voir commentaire détaillé plus bas, au
        // moment du setDescription()). On re-vérifie l'absence de contenu ICI car
        // sanitizeRichText() peut réduire une description à une chaîne vide si elle
        // ne contenait que des balises dangereuses (ex: uniquement un <script>).
        $description = $this->htmlSanitizer->sanitizeRichText($description);
        if ($description === '') {
            $this->addFlash('error', 'La description est obligatoire.');
            return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
        }

        // URL externe : si renseignée, longueur max 500 chars + format valide
        if ($externalUrl !== null) {
            if (mb_strlen($externalUrl) > 500) {
                $this->addFlash('error', 'L\'URL externe ne peut pas dépasser 500 caractères.');
                return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
            }
            if (filter_var($externalUrl, FILTER_VALIDATE_URL) === false) {
                $this->addFlash('error', 'L\'URL externe renseignée n\'est pas valide (doit commencer par http:// ou https://).');
                return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
            }
        }

        // Localisation : max 150 chars
        if ($location !== null && mb_strlen($location) > 150) {
            $this->addFlash('error', 'La localisation ne peut pas dépasser 150 caractères.');
            return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
        }

        // Deadline : si renseignée, doit être parseable en date stricte (format YYYY-MM-DD).
        // Délègue à DeadlineParserService::parse(), qui valide désormais le calendrier
        // via checkdate() (rejette "2026-02-30" au lieu de le décaler silencieusement
        // au 2 mars) ET la borne de plausibilité (rejette les années aberrantes).
        // Ces deux garde-fous couvrent le cas documenté historiquement ici pour
        // createFromFormat() seul.
        $deadlineDate = null;
        if ($deadlineStr !== null) {
            $parsed = $this->deadlineParser->parse($deadlineStr);
            if ($parsed === null) {
                $this->addFlash(
                    'error',
                    'La date limite n\'est pas valide (format attendu : YYYY-MM-DD, ex: 2026-06-15) '
                    . 'ou trop éloignée dans le passé/futur.'
                );
                return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
            }
            // Resource::deadline est un champ Doctrine 'date' (DateTime MUTABLE) ; le
            // parser renvoie un DateTimeImmutable → conversion obligatoire avant setDeadline
            // (sinon InvalidType au flush). $parsed est garanti non-null ici (voir check ci-dessus).
            $deadlineDate = \DateTime::createFromInterface($parsed);
        }

        // ── Mise à jour de l'entité ──────────────────────────────────────────
        // PreUpdate lifecycle callback de Resource mettra à jour $updatedAt automatiquement.
        // $description a déjà été passé par sanitizeRichText() plus haut (sécurité
        // XSS — même champ que ResourceService::createResource() et
        // verifyScrapedOpportunity(), rendu avec `|raw` dans resource/show.html.twig).
        $resource->setTitle($title);
        $resource->setDescription($description);
        $resource->setExternalUrl($externalUrl);
        $resource->setDeadline($deadlineDate);
        $resource->setLocation($location);

        $this->em->flush();

        $this->addFlash('success', sprintf('La ressource "%s" a été mise à jour.', $resource->getTitle()));
        return $this->redirectToRoute('app_admin_resources_all');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODÉRATION DES OPPORTUNITÉS SCRAPÉES — Rejet + Scraping à la demande
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Rejette une opportunité scrapée — la marque comme "rejected".
     *
     * Pourquoi un statut dédié plutôt qu'une suppression ?
     *   - On garde une trace des URL rejetées pour éviter de les rescaper.
     *   - L'admin peut consulter l'historique des rejets dans l'onglet "Rejeté".
     *
     * Contrainte : une opportunité déjà vérifiée (promue en Resource) ne peut pas
     * être rejetée — elle a déjà produit une Resource publiée.
     */
    #[Route('/scraped-opportunities/{id}/reject', name: 'scraped_opportunity_reject', methods: ['POST'])]
    public function rejectScrapedOpportunity(int $id, Request $request): Response
    {
        // Vérification CSRF — token spécifique à l'opportunité et à l'action reject
        if (!$this->isCsrfTokenValid('reject_scraped_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Récupère l'opportunité scrapée (404 si inexistante)
        $scraped = $this->scrapedResourceRepository->find($id);
        if ($scraped === null) {
            throw $this->createNotFoundException('Opportunité scrapée introuvable.');
        }

        // Garde-fou : une opportunité déjà vérifiée a déjà généré une Resource publiée.
        // La rejeter à ce stade serait incohérent avec la Resource existante.
        if ($scraped->isVerified()) {
            $this->addFlash('error', 'Impossible de rejeter une opportunité déjà validée.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Change le statut vers "rejected" et persiste en base
        $scraped->setStatus(ScrapedResourceStatus::Rejected);
        $this->em->flush();

        $this->addFlash('success', sprintf('"%s" a été rejetée.', $scraped->getTitle()));
        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }

    /**
     * Lance le scraping des opportunités de manière BLOQUANTE via symfony/process.
     *
     * Pourquoi bloquant (Process::run()) et non asynchrone ?
     *   - L'admin veut voir le résultat immédiatement dans le flash message.
     *   - Le scraping dure en général 20 à 60 secondes (requêtes HTTP externes).
     *   - Un timeout de 120 secondes couvre largement les cas normaux.
     *   - start() (non-bloquant) ne permettait pas d'afficher le bilan réel.
     *
     * Compromis accepté :
     *   - La page "se charge" pendant 20-60 s (pas de 504 car Nginx est configuré
     *     avec fastcgi_read_timeout élevé dans le container dev).
     *   - Pour la planification automatique, le cron reste préférable (cf. ScrapeOpportunitiesCommand).
     */
    /**
     * Lance la commande de scraping directement dans le processus PHP courant.
     *
     * Pourquoi cette approche (Application Console inline) plutôt que Process::run() ?
     *
     * Process::run() crée un SOUS-PROCESSUS PHP qui hérite de l'environnement
     * de PHP-FPM. Dans Docker, PHP-FPM n'a pas accès aux variables déclarées dans
     * .env.local (DATABASE_URL, APP_SECRET, etc.) — elles sont chargées par le
     * composant Dotenv uniquement lors du bootstrap de l'application.
     * Résultat : la commande plantait au démarrage (connexion BDD impossible)
     * et retournait exit code 1 → faux message d'erreur.
     *
     * La solution : instancier Application (la couche Console de Symfony) ici même
     * et appeler run() dans le processus PHP actuel. Toutes les variables sont déjà
     * chargées, tous les services sont déjà disponibles → pas d'ambiguïté d'env.
     *
     * Contrepartie : la requête HTTP reste ouverte pendant toute la durée du scraping
     * (20-90 secondes selon le nombre de scrapers actifs). C'est acceptable pour une
     * action admin manuelle et ponctuelle. Pour la planification automatique, le cron
     * reste préférable (cf. docs/scraping-cron.md).
     */
    #[Route('/scraping/run', name: 'scraping_run', methods: ['POST'])]
    public function runScraping(Request $request): Response
    {
        // Vérification CSRF — protège contre les requêtes forgées
        if (!$this->isCsrfTokenValid('run_scraping', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // ── Instanciation de l'application Console Symfony ───────────────────
        // Application est le point d'entrée de la couche Console (comme bin/console).
        // setAutoExit(false) : on veut récupérer le code de sortie nous-mêmes,
        // pas laisser l'Application appeler exit() et couper la réponse HTTP.
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        // ── Entrée : nom de la commande à exécuter ───────────────────────────
        // ArrayInput remplace argv : pas de sous-processus, pas de shell.
        $input = new ArrayInput(['command' => 'app:scrape-opportunities']);

        // ── Sortie : tampon en mémoire pour capturer le bilan ────────────────
        // BufferedOutput collecte tout ce que SymfonyStyle écrit (io->success, io->note…).
        // On parsera ensuite les compteurs avec des regex.
        $output = new BufferedOutput();

        // ── Exécution ─────────────────────────────────────────────────────────
        // run() est synchrone : on attend la fin complète avant de continuer.
        // Pas de timeout PHP ici (set_time_limit est géré par php-fpm, 300s par défaut).
        try {
            $exitCode = $application->run($input, $output);
        } catch (\Throwable $e) {
            // Erreur inattendue (ex : service non disponible) — on la logue et
            // on affiche un message d'erreur clair plutôt que de planter silencieusement.
            $this->addFlash('error', 'Erreur inattendue lors du scraping : ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // ── Extraction du bilan depuis la sortie texte ───────────────────────
        // SymfonyStyle formate ses messages avec des caractères ANSI et des espaces.
        // strip_tags() supprime les balises Twig/HTML potentielles ; le décodage
        // ANSI n'est pas nécessaire car BufferedOutput désactive les couleurs.
        $content     = $output->fetch();
        $inserted    = 0;
        $reactivated = 0;
        $updated     = 0;
        $skipped     = 0;

        // Extraction des compteurs depuis la sortie texte de la commande.
        // Le format du message est : "X nouvelle(s) | Y réactivée(s) (archives) | Z mise(s) à jour | W ignorée(s)"
        if (preg_match('/(\d+) nouvelle\(s\)/', $content, $m)) {
            $inserted = (int) $m[1];
        }
        if (preg_match('/(\d+) réactivée\(s\)/', $content, $m)) {
            $reactivated = (int) $m[1];
        }
        if (preg_match('/(\d+) mise\(s\) à jour/', $content, $m)) {
            $updated = (int) $m[1];
        }
        if (preg_match('/(\d+) ignorée\(s\)/', $content, $m)) {
            $skipped = (int) $m[1];
        }

        // ── Réponse JSON si appel AJAX (fetch depuis la card de statut) ──────
        // Le header 'X-Requested-With: XMLHttpRequest' est envoyé par le JS du template.
        // On retourne du JSON pour que le JS puisse afficher le bilan sans rechargement complet.
        // Le comportement classique (redirect + flash) est conservé en fallback non-AJAX.
        if ($request->isXmlHttpRequest()) {
            if ($exitCode !== 0) {
                return new JsonResponse([
                    'success' => false,
                    'error'   => 'Erreur (code ' . $exitCode . '). Vérifiez les logs Symfony.',
                ], 500);
            }

            return new JsonResponse([
                'success'     => true,
                'inserted'    => $inserted,
                'reactivated' => $reactivated,
                'updated'     => $updated,
                'skipped'     => $skipped,
            ]);
        }

        // ── Flash message selon le résultat (appel classique sans JS) ────────
        // exit code 0 = Command::SUCCESS → tout s'est bien passé
        // exit code 1 = Command::FAILURE → erreur dans la commande
        if ($exitCode !== 0) {
            $this->addFlash('error',
                'Le scraping a rencontré une erreur (code ' . $exitCode . '). Vérifiez les logs Symfony.'
            );
        } else {
            $this->addFlash('success', sprintf(
                'Scraping terminé : %d nouvelle(s), %d réactivée(s), %d mise(s) à jour, %d ignorée(s).',
                $inserted,
                $reactivated,
                $updated,
                $skipped
            ));
        }

        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACTIONS DE RECLASSEMENT DES OPPORTUNITES SCRAPEES
    //
    // Ces trois actions permettent à l'admin de reclasser une opportunité scrapée
    // sans la valider en tant qu'opportunité Bazaart standard.
    //
    //   1. archive     : marque l'opportunité comme "Archivée" (offre passée ou hors sujet)
    //   2. documentation : la publie comme Resource de type "Documentation" (articles, guides…)
    //   3. to-source   : promeut l'organisme émetteur en source de scraping
    //
    // Toutes les trois sont en POST avec vérification CSRF.
    // La logique métier est dans les services (OpportunityToSourcePromoter, etc.),
    // le controller orchestre uniquement.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Reclassement 1 : Archive une opportunité scrapée ("offre passée").
     *
     * Différent du rejet (Rejected) : l'opportunité était valide mais son moment est révolu.
     * L'archivage conserve l'URL en BDD (pas de re-scraping) sans polluer la file "À vérifier".
     *
     * Peut s'appliquer à une opportunité en n'importe quel statut sauf Archived.
     */
    #[Route('/scraped-opportunities/{id}/archive', name: 'scraped_opportunity_archive', methods: ['POST'])]
    public function archiveScrapedOpportunity(int $id, Request $request): Response
    {
        // Vérification CSRF — token spécifique à l'action et à l'ID
        if (!$this->isCsrfTokenValid('archive_scraped_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Récupère l'opportunité scrapée (404 si inexistante)
        $scraped = $this->scrapedResourceRepository->find($id);
        if ($scraped === null) {
            throw $this->createNotFoundException('Opportunité introuvable.');
        }

        // Garde-fou : ne pas ré-archiver ce qui l'est déjà
        if ($scraped->isArchived()) {
            $this->addFlash('warning', 'Cette opportunité est déjà archivée.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Change le statut et persiste
        $scraped->setStatus(ScrapedResourceStatus::Archived);
        $this->em->flush();

        $this->addFlash('success', sprintf('"%s" archivée (offre passée).', $scraped->getTitle()));
        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }

    /**
     * Reclassement 2 : Publie l'opportunité scrapée comme Resource de type "Documentation".
     *
     * Utilisé quand l'opportunité est en réalité un article, un guide ou une ressource
     * documentaire plutôt qu'un appel à candidatures classique.
     *
     * Logique identique à verifyScrapedOpportunity() MAIS :
     *   - Le ResourceType est forcé à "Documentation" (créé idempotentement si absent)
     *   - La deadline reste optionnelle (pas d'urgence temporelle pour un article)
     *   - La ScrapedResource passe à Verified (comme une validation normale)
     *
     * Pourquoi ne pas réutiliser verifyScrapedOpportunity() directement ?
     *   Pour éviter de passer un paramètre "forceType" dans une route déjà complexe.
     *   Les deux actions ont une intention différente visible dans le flash message et les logs.
     */
    #[Route('/scraped-opportunities/{id}/documentation', name: 'scraped_opportunity_documentation', methods: ['POST'])]
    public function documentationScrapedOpportunity(int $id, Request $request): Response
    {
        // Vérification CSRF
        if (!$this->isCsrfTokenValid('doc_scraped_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Récupère l'opportunité scrapée (404 si inexistante)
        $scraped = $this->scrapedResourceRepository->find($id);
        if ($scraped === null) {
            throw $this->createNotFoundException('Opportunité introuvable.');
        }

        // Garde-fou : une opportunité déjà vérifiée a déjà produit une Resource
        if ($scraped->isVerified()) {
            $this->addFlash('error', 'Cette opportunité a déjà été vérifiée et publiée.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // ── Récupération ou création idempotente du ResourceType "Documentation" ─
        //
        // On cherche d'abord le type existant pour éviter tout doublon.
        // Si absent (première fois), on le crée à la volée : findOrCreate idempotent.
        // Ce n'est pas une migration de schéma (ResourceType est une donnée applicative,
        // pas une contrainte BDD) — la création à la volée est donc préférable à une migration.
        $docType = $this->resourceTypeRepository->findOneBy(['name' => 'Documentation']);

        if ($docType === null) {
            // Création du type manquant — idempotent si la route est appelée plusieurs fois
            // en concurrence (peu probable mais la contrainte unique BDD protège contre les doublons)
            $docType = new \App\Entity\ResourceType();
            $docType->setName('Documentation');
            $docType->setIcon('📄');
            $this->em->persist($docType);
            // flush partiel ici pour obtenir l'ID avant de l'assigner à Resource
            $this->em->flush();
        }

        // ── Conversion deadline texte → DateTime ────────────────────────────────
        // Pour la documentation, la deadline est optionnelle : un article n'expire pas.
        // On tente quand même le parsing — si présent, on l'utilise.
        // DeadlineParserService centralise les 3 formats + la borne de plausibilité
        // (même logique que verifyScrapedOpportunity()).
        $parsedDeadline = ($scraped->getDeadline() !== null && $scraped->getDeadline() !== '')
            ? $this->deadlineParser->parse($scraped->getDeadline())
            : null;
        // Resource::deadline = champ Doctrine 'date' (DateTime MUTABLE) ; le parser renvoie
        // un DateTimeImmutable → conversion obligatoire, sinon InvalidType au flush.
        $deadline = $parsedDeadline instanceof \DateTimeImmutable
            ? \DateTime::createFromInterface($parsedDeadline)
            : $parsedDeadline;

        // ── Création de la Resource publiée de type Documentation ───────────────
        // Même logique de propagation que verifyScrapedOpportunity() :
        // tous les champs enrichis (ADR-0016, ADR-0018, ADR-0019) sont propagés.
        // Sécurité (audit XSS) : sanitizeRichText() ici aussi, voir le commentaire
        // détaillé dans verifyScrapedOpportunity() ci-dessus.
        $resource = new Resource();
        $resource->setTitle($scraped->getTitle());
        $resource->setDescription(
            $this->htmlSanitizer->sanitizeRichText($scraped->getDescription() ?: 'Description non disponible.')
        );
        $resource->setExternalUrl($scraped->getUrl());
        $resource->setDeadline($deadline);
        $resource->setResourceType($docType);
        $resource->setOrganization(null);

        /** @var User $admin */
        $admin = $this->getUser();
        $resource->setSubmittedBy($admin);

        // Traçabilité CDC §5.2 : publiée immédiatement, validée par l'admin connecté
        $now = new \DateTime();
        $resource->setStatus(ResourceStatus::Published);
        $resource->setSubmitterRole(\App\Enum\SubmitterRole::Admin);
        $resource->setAutoPublished(true);
        $resource->setPublishedAt($now);
        $resource->setValidatedAt($now);
        $resource->setValidatedBy($admin);

        // ── Propagation des champs enrichis (ADR-0016 Lot 1) ───────────────────
        $resource->setCity($scraped->getCity());
        $resource->setCountry($scraped->getCountry());
        $resource->setExperienceLevel($scraped->getExperienceLevel());

        // ── Propagation ADR-0018 (candidature + financement) ───────────────────
        $resource->setHowToApply($scraped->getHowToApply());
        $resource->setFundingAmount($scraped->getFundingAmount());
        $resource->setFundingType($scraped->getFundingType());

        // ── Propagation ADR-0019 (lien candidature + logo) ─────────────────────
        $rawApplicationUrl = $scraped->getApplicationUrl();
        $resource->setApplicationUrl(
            $rawApplicationUrl !== null ? mb_substr($rawApplicationUrl, 0, 500) : null
        );

        $rawLogoUrl = $scraped->getLogoUrl();
        $resource->setLogoUrl(
            $rawLogoUrl !== null ? mb_substr($rawLogoUrl, 0, 500) : null
        );

        // ── Propagation des disciplines (ADR-0016 Lot 1) ───────────────────────
        if ($scraped->getDisciplines() !== null && $scraped->getDisciplines() !== '') {
            $disciplineLabels = array_map('trim', explode(',', $scraped->getDisciplines()));
            $matchedDisciplines = $this->disciplineMapper->mapLabelsToEntities($disciplineLabels);
            foreach ($matchedDisciplines as $discipline) {
                $resource->addDiscipline($discipline);
            }
        }

        $this->em->persist($resource);

        // Marque la ScrapedResource comme vérifiée (même comportement que la vérification normale)
        $scraped->setStatus(ScrapedResourceStatus::Verified);

        $this->em->flush();

        $this->addFlash(
            'success',
            sprintf('"%s" publiée comme Documentation.', $scraped->getTitle())
        );
        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }

    /**
     * Reclassement 3 : Promeut l'organisme émetteur en source de scraping.
     *
     * Flux :
     *   1. Délègue à OpportunityToSourcePromoter::promote() toute la logique métier :
     *      - Découverte de la page-liste via ListingUrlDiscoverer (heuristique + LLM)
     *      - Fallback : création d'une source depuis l'URL directe si découverte échoue
     *      - Archivage de la ScrapedResource dans tous les cas
     *   2. Construit le flash message depuis le PromotionResult retourné
     *
     * Note : l'action peut prendre quelques secondes (requêtes HTTP + éventuel LLM).
     * Acceptable pour une action admin manuelle et ponctuelle.
     */
    #[Route('/scraped-opportunities/{id}/to-source', name: 'scraped_opportunity_to_source', methods: ['POST'])]
    public function opportunityToSource(int $id, Request $request): Response
    {
        // Vérification CSRF — token spécifique à l'action et à l'ID
        if (!$this->isCsrfTokenValid('to_source_scraped_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Récupère l'opportunité scrapée (404 si inexistante)
        $scraped = $this->scrapedResourceRepository->find($id);
        if ($scraped === null) {
            throw $this->createNotFoundException('Opportunité introuvable.');
        }

        // Délègue toute la logique au service — le controller reste mince
        $result = $this->toSourcePromoter->promote($scraped);

        // Construit le flash selon le résultat du service
        if ($result->success) {
            $this->addFlash('success', $result->message);
        } else {
            $this->addFlash('error', $result->message);
        }

        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }
}

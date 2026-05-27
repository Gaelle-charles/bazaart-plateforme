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
use App\Service\AuthService;
use App\Service\NotificationService;
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
    ) {}

    /**
     * Tableau de bord admin — vue d'ensemble des chiffres clés.
     */
    #[Route('', name: 'dashboard')]
    public function dashboard(): Response
    {
        // Compteurs pour les statistiques
        $pendingCount      = count($this->resourceRepository->findPending());
        $publishedCount    = count($this->resourceRepository->findPublished());
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

            // Si la case "admin" est cochée, on lui donne le rôle directement
            if ($request->request->get('is_admin')) {
                $user->setRoles(['ROLE_ADMIN']);
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
            $user->setRoles(array_values(array_filter($roles, fn($r) => $r !== 'ROLE_ADMIN' && $r !== 'ROLE_USER')));
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
     * Affiche les "À vérifier" en premier, puis les "Vérifié".
     */
    #[Route('/scraped-opportunities', name: 'scraped_opportunities')]
    public function scrapedOpportunities(): Response
    {
        // Récupère les opportunités par statut pour alimenter les 4 onglets
        $pending  = $this->scrapedResourceRepository->findPending();
        $verified = $this->scrapedResourceRepository->findVerified();
        // Onglet "Rejeté" : opportunités jugées hors sujet par l'admin
        $rejected = $this->scrapedResourceRepository->findRejected();
        // Onglet "Archivé" : opportunités expirées (deadline passée, archivage automatique)
        // ou archivées manuellement. Consultation uniquement, pas d'action disponible.
        $archived        = $this->scrapedResourceRepository->findArchived();
        $latestScrapedAt = $this->scrapedResourceRepository->findLatestScrapedAt();

        return $this->render('admin/scraped_opportunities.html.twig', [
            'pending'         => $pending,
            'verified'        => $verified,
            'rejected'        => $rejected,
            'archived'        => $archived,
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

        // Cherche le ResourceType correspondant, sinon prend le premier disponible
        $resourceType = $this->resourceTypeRepository->findOneBy(['name' => $scraped->getType()])
                     ?? $this->resourceTypeRepository->findOneBy(['name' => 'Autre'])
                     ?? $this->resourceTypeRepository->findAll()[0]
                     ?? null;

        if ($resourceType === null) {
            $this->addFlash('error', 'Aucun type de ressource trouvé en base. Crée d\'abord des types via les fixtures.');
            return $this->redirectToRoute('app_admin_scraped_opportunities');
        }

        // Convertit la date limite texte en objet DateTime si possible
        $deadline = null;
        if ($scraped->getDeadline()) {
            $deadline = \DateTime::createFromFormat('d/m/Y', $scraped->getDeadline())
                     ?: \DateTime::createFromFormat('Y-m-d', $scraped->getDeadline())
                     ?: null;
        }

        // Crée la Resource publiée dans le tableau Opportunités
        $resource = new Resource();
        $resource->setTitle($scraped->getTitle());
        $resource->setDescription($scraped->getDescription() ?: 'Description non disponible.');
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

        // Deadline : si renseignée, doit être parseable en date stricte (format YYYY-MM-DD)
        // Attention : createFromFormat() NE retourne PAS false pour une date invalide
        // comme "2026-02-30" — il décale silencieusement au 2 mars. Il faut vérifier
        // getLastErrors() pour détecter ce cas.
        $deadlineDate = null;
        if ($deadlineStr !== null) {
            $parsed = \DateTime::createFromFormat('Y-m-d', $deadlineStr);
            $errors = \DateTime::getLastErrors();
            if ($parsed === false || ($errors !== false && $errors['warning_count'] > 0)) {
                $this->addFlash('error', 'La date limite n\'est pas valide (format attendu : YYYY-MM-DD, ex: 2026-06-15).');
                return $this->redirectToRoute('app_admin_resource_edit', ['id' => $id]);
            }
            $deadlineDate = $parsed;
        }

        // ── Mise à jour de l'entité ──────────────────────────────────────────
        // PreUpdate lifecycle callback de Resource mettra à jour $updatedAt automatiquement.
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
        $content  = $output->fetch();
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        if (preg_match('/(\d+) nouvelle\(s\)/', $content, $m)) {
            $inserted = (int) $m[1];
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
                'success'  => true,
                'inserted' => $inserted,
                'updated'  => $updated,
                'skipped'  => $skipped,
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
                'Scraping terminé : %d nouvelle(s) opportunité(s), %d mise(s) à jour, %d ignorée(s).',
                $inserted,
                $updated,
                $skipped
            ));
        }

        return $this->redirectToRoute('app_admin_scraped_opportunities');
    }
}

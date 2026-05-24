<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\RegisterDTO;
use App\Entity\Resource;
use App\Entity\ScrapedResource;
use App\Entity\User;
use App\Enum\ResourceStatus;
use App\Enum\ScrapedResourceStatus;
use App\Repository\OrganizationProfileRepository;
use App\Repository\ResourceRepository;
use App\Repository\ResourceTypeRepository;
use App\Repository\ScrapedResourceRepository;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\StructureService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        // Récupère d'abord les "pending" (À vérifier) triés par score, puis les "verified"
        $pending  = $this->scrapedResourceRepository->findPending();
        $verified = $this->scrapedResourceRepository->findVerified();

        return $this->render('admin/scraped_opportunities.html.twig', [
            'pending'  => $pending,
            'verified' => $verified,
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
}

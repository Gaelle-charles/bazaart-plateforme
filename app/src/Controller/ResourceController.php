<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ResourceFavorite;
use App\Entity\User;
use App\Enum\ResourceStatus;
use App\Repository\DisciplineRepository;
use App\Repository\OrganizationProfileRepository;
use App\Repository\ResourceAlertRepository;
use App\Repository\ResourceFavoriteRepository;
use App\Repository\ResourceRepository;
use App\Repository\ResourceTypeRepository;
use App\Service\ResourceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/resources', name: 'app_resource_')]
class ResourceController extends AbstractController
{
    public function __construct(
        private readonly ResourceRepository $resourceRepository,
        private readonly ResourceTypeRepository $typeRepository,
        private readonly DisciplineRepository $disciplineRepository,
        private readonly OrganizationProfileRepository $orgRepository,
        private readonly ResourceService $resourceService,
        // Injecté pour gérer les favoris (toggle + liste + état bouton)
        private readonly ResourceFavoriteRepository $favoriteRepository,
        // Injecté pour persister / supprimer les favoris
        private readonly EntityManagerInterface $em,
        // Injecté pour les préférences d'alertes
        private readonly ResourceAlertRepository $alertRepository,
    ) {}

    /**
     * Page principale : liste de toutes les ressources publiées.
     * Accessible à tous les utilisateurs connectés.
     * Supporte les filtres par type, discipline et recherche textuelle.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        // Récupération des filtres depuis l'URL (?type=1&discipline=2&q=musique)
        $typeId       = $request->query->get('type') ? (int) $request->query->get('type') : null;
        $disciplineId = $request->query->get('discipline') ? (int) $request->query->get('discipline') : null;
        $search       = $request->query->get('q');

        $resources   = $this->resourceRepository->findPublished($typeId, $disciplineId, $search);
        $types       = $this->typeRepository->findAllOrdered();
        $disciplines = $this->disciplineRepository->findAllOrdered();

        return $this->render('resource/index.html.twig', [
            'resources'          => $resources,
            'types'              => $types,
            'disciplines'        => $disciplines,
            // On repasse les filtres actifs au template pour pré-sélectionner les <select>
            'currentTypeId'      => $typeId,
            'currentDisciplineId' => $disciplineId,
            'currentSearch'      => $search ?? '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ROUTES STATIQUES — déclarées AVANT /{id} pour éviter les conflits de matching
    //
    // ⚠️ RÈGLE SYMFONY : les routes avec des segments fixes (/favorites, /alerts,
    // /submit, /my) doivent être définies AVANT les routes avec paramètres dynamiques
    // (/{id}) dans un même controller.
    //
    // Avec `requirements: ['id' => '\d+']`, Symfony ne matche pas "favorites" ou
    // "alerts" comme un {id}. Mais garder les routes statiques en premier reste
    // une bonne pratique de lisibilité et de robustesse.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Page "Mes favoris" : liste des ressources mises en favori par l'utilisateur.
     *
     * Route : GET /resources/favorites
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/favorites', name: 'favorites')]
    public function favorites(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // findFavoritesByUser() charge resource + resourceType + organization en une requête
        // pour éviter le problème N+1 dans le template Twig.
        $favorites = $this->favoriteRepository->findFavoritesByUser($user);

        return $this->render('resource/favorites.html.twig', [
            'favorites' => $favorites,
        ]);
    }

    /**
     * Formulaire de soumission d'une nouvelle ressource.
     * Accessible à tous les utilisateurs connectés en V1 (artistes sans org compris).
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/submit', name: 'submit', methods: ['GET', 'POST'])]
    public function submit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Charge le profil organisation si l'utilisateur en a un.
        // En V1, le profil organisation n'est plus obligatoire pour soumettre.
        // Les artistes sans org peuvent soumettre ; la logique d'auto-publication
        // est déterminée dans ResourceService::createResource() selon le rôle.
        $organization = $this->orgRepository->findByUser($user);

        if ($request->isMethod('POST')) {
            // ── Validation CSRF ────────────────────────────────────────────────────
            // Le token est généré dans le template via {{ csrf_token('resource_submit') }}
            // et envoyé dans un champ caché nommé "_token".
            // Si le token ne correspond pas (requête forgée, session expirée...),
            // on rejette immédiatement AVANT tout traitement des données POST.
            // isCsrfTokenValid() est fourni par AbstractController — pas d'import requis.
            if (!$this->isCsrfTokenValid('resource_submit', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_resource_submit');
            }
            // ──────────────────────────────────────────────────────────────────────

            $result = $this->resourceService->createResource($user, $request->request->all());

            if (is_string($result)) {
                // Erreur de validation — on réaffiche le formulaire avec les données saisies
                return $this->render('resource/submit.html.twig', [
                    'types'        => $this->typeRepository->findAllOrdered(),
                    'disciplines'  => $this->disciplineRepository->findAllOrdered(),
                    'organization' => $organization,
                    'error'        => $result,
                    'formData'     => $request->request->all(),
                ]);
            }

            // Le message de confirmation dépend du statut final de la ressource.
            // Les admins et structures voient leur ressource publiée directement.
            // Les artistes voient un message d'attente de validation.
            if ($result->isPublished()) {
                $this->addFlash('success', 'Votre ressource a été publiée directement.');
            } else {
                $this->addFlash('success', 'Votre ressource a été soumise et est en attente de validation par notre équipe.');
            }
            return $this->redirectToRoute('app_resource_my');
        }

        return $this->render('resource/submit.html.twig', [
            'types'        => $this->typeRepository->findAllOrdered(),
            'disciplines'  => $this->disciplineRepository->findAllOrdered(),
            'organization' => $organization,
            'error'        => null,
            'formData'     => [],
        ]);
    }

    /**
     * Page "Mes ressources" : liste des ressources soumises par l'utilisateur connecté.
     * Affiche le statut de chaque soumission (en attente, publiée, rejetée).
     *
     * Supporte le filtre par statut via le paramètre query ?status=
     * Valeurs autorisées : draft, pending, published, rejected, archived
     * (correspondant aux cases de l'enum ResourceStatus).
     * Toute valeur invalide est silencieusement ignorée → on affiche toutes les ressources.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/my', name: 'my')]
    public function my(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // ── Lecture et validation du filtre de statut ──────────────────────────
        // On lit ?status= depuis l'URL (ex: /resources/my?status=published).
        // ResourceStatus::from() lève ValueError si la valeur est inconnue,
        // donc on utilise un try/catch pour traiter les valeurs arbitraires sans crash.
        // En cas de valeur invalide, $statusEnum = null → pas de filtre = toutes les ressources.
        $rawStatus = $request->query->get('status');
        try {
            // ResourceStatus::from() valide que la valeur appartient bien à l'enum.
            // C'est la manière sécurisée d'éviter les injections de valeurs inattendues.
            $statusEnum = $rawStatus !== null ? ResourceStatus::from($rawStatus) : null;
            // On conserve la valeur string d'origine pour renvoyer au template (pré-sélection onglet actif).
            $statusFilter = $statusEnum?->value;
        } catch (\ValueError) {
            // Valeur reçue non reconnue par l'enum → on ignore et on affiche tout.
            $statusEnum   = null;
            $statusFilter = null;
        }
        // ──────────────────────────────────────────────────────────────────────

        // On délègue la requête filtrée au repository.
        // findByUserWithStatusFilter() retourne toutes les ressources si $statusEnum est null.
        $resources = $this->resourceRepository->findByUserWithStatusFilter($user, $statusEnum);

        return $this->render('resource/my.html.twig', [
            'resources'    => $resources,
            // On repasse le filtre actif au template pour que les onglets
            // puissent afficher l'onglet sélectionné (class CSS active, aria-selected, etc.)
            'statusFilter' => $statusFilter,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALERTES EMAIL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Préférences d'alertes email pour les nouvelles ressources.
     *
     * Route : GET/POST /resources/alerts
     *
     * GET  : affiche le formulaire de préférences (pré-rempli si déjà configuré)
     * POST : crée ou met à jour le profil d'alertes ResourceAlert
     *
     * Fonctionnement :
     * - Un seul profil d'alertes par utilisateur (OneToOne dans ResourceAlert)
     * - S'il existe déjà, on le met à jour ; sinon on en crée un nouveau
     * - L'utilisateur choisit la fréquence et les filtres (disciplines, types)
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/alerts', name: 'alerts', methods: ['GET', 'POST'])]
    public function alerts(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Charge le profil d'alertes existant (null si jamais configuré)
        $alert = $this->alertRepository->findByUser($user);

        if ($request->isMethod('POST')) {
            // Vérification CSRF — token spécifique aux alertes
            if (!$this->isCsrfTokenValid('resource_alerts', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_resource_alerts');
            }

            // Délègue la sauvegarde au repository d'alertes (via l'EntityManager)
            // On passe les données POST brutes ; la logique de création/mise à jour
            // est encapsulée dans le service ResourceAlertService.
            // Pour V1 on gère directement ici pour rester simple.
            if ($alert === null) {
                // Premier enregistrement : on crée une nouvelle entité ResourceAlert
                $alert = new \App\Entity\ResourceAlert();
                $alert->setUser($user);
                $this->em->persist($alert);
            }

            // Mise à jour des préférences depuis les données POST
            $alert->setNotifyOnNewResource((bool) $request->request->get('notifyOnNewResource', false));

            // Fréquence d'envoi (IMMEDIATE, DAILY, WEEKLY)
            $frequencyValue = $request->request->get('frequency', \App\Enum\AlertFrequency::Daily->value);
            $frequency = \App\Enum\AlertFrequency::tryFrom($frequencyValue) ?? \App\Enum\AlertFrequency::Daily;
            $alert->setFrequency($frequency);

            // Disciplines filtrées (checkboxes multi-sélection)
            // On reçoit un tableau d'IDs, on efface l'ancienne sélection, on remet
            $alert->getFilterDisciplines()->clear();
            $disciplineIds = $request->request->all('filterDisciplines') ?? [];
            foreach ($disciplineIds as $disciplineId) {
                $discipline = $this->disciplineRepository->find((int) $disciplineId);
                if ($discipline !== null) {
                    $alert->addFilterDiscipline($discipline);
                }
            }

            // Types de ressources filtrés (checkboxes multi-sélection)
            $alert->getFilterResourceTypes()->clear();
            $typeIds = $request->request->all('filterResourceTypes') ?? [];
            foreach ($typeIds as $typeId) {
                $type = $this->typeRepository->find((int) $typeId);
                if ($type !== null) {
                    $alert->addFilterResourceType($type);
                }
            }

            $this->em->flush();

            $this->addFlash('success', 'Vos préférences d\'alertes ont été enregistrées.');
            return $this->redirectToRoute('app_resource_alerts');
        }

        // Charge toutes les disciplines et types pour les checkboxes du formulaire
        $disciplines = $this->disciplineRepository->findAllOrdered();
        $types       = $this->typeRepository->findAllOrdered();

        return $this->render('resource/alerts.html.twig', [
            'alert'       => $alert,
            'disciplines' => $disciplines,
            'types'       => $types,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ROUTES DYNAMIQUES (/{id}) — après les routes statiques
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Page de détail d'une ressource.
     * Accessible uniquement si la ressource est publiée (ou si admin).
     *
     * Passe `isFavorited` au template pour afficher le bouton coeur dans le bon état.
     * Passe `favoriteCount` pour afficher le nombre total de favoris sur cette ressource.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $resource = $this->resourceRepository->find($id);

        // 404 si la ressource n'existe pas
        if ($resource === null) {
            throw $this->createNotFoundException('Ressource introuvable.');
        }

        // Un utilisateur normal ne peut voir que les ressources publiées
        if (!$resource->isPublished() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Cette ressource n\'est pas encore publiée.');
        }

        // Détermine si l'utilisateur connecté a mis cette ressource en favori.
        /** @var User $user */
        $user = $this->getUser();
        $isFavorited   = $this->favoriteRepository->findByUserAndResource($user, $resource) !== null;
        $favoriteCount = $this->favoriteRepository->countByResource($resource);

        return $this->render('resource/show.html.twig', [
            'resource'      => $resource,
            'isFavorited'   => $isFavorited,
            'favoriteCount' => $favoriteCount,
        ]);
    }

    /**
     * Toggle favori : ajoute ou supprime la ressource des favoris de l'utilisateur.
     *
     * Route : POST /resources/{id}/favorite
     * Réponse JSON : { "favorited": true|false, "count": X }
     *
     * Cette route est conçue pour être appelée en AJAX depuis un composant Stimulus.
     * Le token CSRF est spécifique à chaque ressource (resource_favorite_{id}).
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/favorite', name: 'favorite_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFavorite(int $id, Request $request): JsonResponse
    {
        // Vérification du token CSRF — protège contre les requêtes forgées
        if (!$this->isCsrfTokenValid('resource_favorite_' . $id, $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Token CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        $resource = $this->resourceRepository->find($id);
        if ($resource === null) {
            return new JsonResponse(['error' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Sécurité métier : on ne peut mettre en favori qu'une ressource publiée.
        // Cela évite qu'un utilisateur infère l'existence d'une ressource non publiée
        // via la différence de réponse (200 vs 403) — et reste cohérent avec l'UI.
        if (!$resource->isPublished() && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['error' => 'Ressource non disponible.'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Cherche un favori existant pour ce couple (user, resource)
        $existing = $this->favoriteRepository->findByUserAndResource($user, $resource);

        if ($existing !== null) {
            // Le favori existe → on le supprime (toggle off)
            $this->em->remove($existing);
            $this->em->flush();
            $favorited = false;
        } else {
            // Pas encore en favori → on crée l'entrée (toggle on)
            $favorite = new ResourceFavorite();
            $favorite->setUser($user);
            $favorite->setResource($resource);
            $this->em->persist($favorite);
            $this->em->flush();
            $favorited = true;
        }

        // Recompte après l'action pour retourner le total à jour
        $count = $this->favoriteRepository->countByResource($resource);

        return new JsonResponse([
            'favorited' => $favorited,
            'count'     => $count,
        ]);
    }
}

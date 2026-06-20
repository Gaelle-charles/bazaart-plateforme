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
use App\Security\Voter\FreemiumVoter;
use App\Service\ResourceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
     * Page principale : catalogue des ressources publiées, vue grille uniquement.
     * Accessible à tous les utilisateurs connectés.
     *
     * Filtres supportés :
     *   ?type=ID        — filtre par type de ressource
     *   ?discipline=ID  — filtre par discipline artistique
     *   ?q=texte        — recherche plein texte (titre + description)
     *   ?year=2026      — filtre sur l'année de la deadline
     *   ?country=France — filtre sur le pays
     *   ?sort=deadline  — tri par deadline croissant (défaut : récemment publiées)
     *   ?page=N         — pagination
     *
     * URL exemple : /resources?type=2&discipline=4&q=musique&year=2026&sort=deadline&page=3
     *
     * Logique de pagination :
     *   1. On lit ?page= depuis la query string (défaut : 1)
     *   2. On compte le total via countPublished() (requête COUNT, pas de chargement d'entités)
     *   3. On calcule totalPages et on borne la page courante entre 1 et totalPages
     *   4. On charge UNIQUEMENT la page courante via findPublished(..., $page, 12)
     *   5. On passe currentPage + totalPages au template pour générer les liens de pagination
     */
    #[IsGranted('ROLE_USER')]
    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        // ── Vérification du paywall freemium (ADR-0022, Lot D) ───────────────────
        // Le catalogue complet est réservé aux abonnés (et aux admins).
        // Si l'utilisateur gratuit essaie d'accéder à /resources, on le redirige
        // vers la page tarifs avec un flash message d'incitation.
        //
        // POURQUOI ici et pas avec #[IsGranted] ou un voter sur la méthode ?
        //   Le #[IsGranted] lancerait une AccessDeniedException qui affiche une
        //   page d'erreur 403 générique. On préfère une redirection vers /tarifs
        //   avec un message clair — meilleure UX.
        //
        // NOTE : on ne bloque PAS les ROLE_ADMIN (FreemiumVoter::voteOnAttribute
        //   retourne true pour les admins via SubscriptionChecker::isSubscribed).
        if (!$this->isGranted(FreemiumVoter::FEATURE_CATALOGUE)) {
            // Message expliquant le contexte et invitant à s'abonner.
            // "info" plutôt que "warning" : ton bienveillant, pas punitif.
            $this->addFlash(
                'info',
                'Le catalogue complet des ressources est réservé aux abonnés Bazaart. Découvrez nos offres ci-dessous.'
            );
            return $this->redirectToRoute('app_pricing');
        }

        // ── Récupération des filtres depuis l'URL ─────────────────────────────────
        // On valide chaque paramètre avant de l'utiliser pour éviter les injections
        // de valeurs arbitraires dans les requêtes Doctrine.
        $typeId       = $request->query->get('type') ? (int) $request->query->get('type') : null;
        $disciplineId = $request->query->get('discipline') ? (int) $request->query->get('discipline') : null;
        $search       = $request->query->get('q');

        // Filtre année : on accepte uniquement une année entière à 4 chiffres valide.
        // Si la valeur ne correspond pas (ex: "abc", "99"), on l'ignore silencieusement.
        $rawYear = $request->query->get('year');
        $year    = ($rawYear !== null && ctype_digit($rawYear) && strlen($rawYear) === 4)
            ? (int) $rawYear
            : null;

        // Filtre pays : on lit la valeur brute (les noms de pays contiennent des lettres,
        // accents, espaces). On la nettoie avec trim() pour éviter les espaces parasites.
        $country = $request->query->get('country');
        $country = ($country !== null && trim($country) !== '') ? trim($country) : null;

        // Tri : 'recent' (défaut) ou 'deadline'. Toute valeur inconnue → tri par défaut.
        // On blanchit les valeurs autorisées pour éviter toute injection dans l'ORDER BY.
        $rawSort = $request->query->get('sort', 'recent');
        $sortBy  = in_array($rawSort, ['recent', 'deadline'], strict: true) ? $rawSort : 'recent';

        // ── Nombre de ressources par page (constante métier) ──────────────────────
        // 12 = 4 lignes × 3 colonnes sur desktop, joliment divisible pour mobile (6 × 2 ou 12 × 1)
        $limit = 12;

        // ── Lecture de la page courante depuis l'URL (?page=N) ───────────────────
        // max(1, ...) borne à 1 minimum pour éviter un OFFSET négatif si quelqu'un
        // saisit manuellement ?page=0 ou ?page=-5 dans la barre d'adresse.
        $page = max(1, (int) ($request->query->get('page') ?? 1));

        // ── Comptage du total de ressources correspondant aux filtres ─────────────
        // On compte AVANT de charger la page pour calculer totalPages.
        // countPublished() fait un SELECT COUNT — pas de chargement d'entités en mémoire.
        //
        // ⚠️ TOUS les filtres (y compris year et country) doivent être identiques
        // dans countPublished() ET findPublished() — sinon la pagination sera incohérente
        // (le compteur et la liste ne couvriraient pas le même périmètre).
        $total = $this->resourceRepository->countPublished(
            $typeId,
            $disciplineId,
            $search,
            hideExpired: true,
            year: $year,
            country: $country,
        );

        // ── Calcul du nombre total de pages ───────────────────────────────────────
        // ceil() arrondit au supérieur : 13 résultats / 12 par page = ceil(1.08) = 2 pages.
        // max(1, ...) garantit au moins 1 page même si le catalogue est vide (total = 0).
        $totalPages = max(1, (int) ceil($total / $limit));

        // ── Sécurité : borne la page courante entre 1 et totalPages ──────────────
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        // ── Chargement de la page courante uniquement ─────────────────────────────
        $resources = $this->resourceRepository->findPublished(
            $typeId,
            $disciplineId,
            $search,
            $page,
            $limit,
            hideExpired: true,
            year: $year,
            country: $country,
            sortBy: $sortBy,
        );

        // ── Listes pour les menus déroulants ─────────────────────────────────────
        $types       = $this->typeRepository->findAllOrdered();
        $disciplines = $this->disciplineRepository->findAllOrdered();

        // Années disponibles : requête DISTINCT sur la colonne deadline des ressources publiées.
        // Sert à peupler le select "Année" avec uniquement les années réellement présentes en BDD.
        $availableYears = $this->resourceRepository->findAvailableDeadlineYears();

        // Pays disponibles : requête DISTINCT sur la colonne country (non nulle, non vide).
        // Sert à peupler le select "Pays/Territoire".
        $availableCountries = $this->resourceRepository->findAvailableCountries();

        return $this->render('resource/index.html.twig', [
            'resources'           => $resources,
            'types'               => $types,
            'disciplines'         => $disciplines,
            'availableYears'      => $availableYears,
            'availableCountries'  => $availableCountries,
            // ── Filtres actifs (renvoyés au template pour pré-sélectionner les champs) ──
            'currentTypeId'       => $typeId,
            'currentDisciplineId' => $disciplineId,
            'currentSearch'       => $search ?? '',
            'currentYear'         => $year,
            'currentCountry'      => $country ?? '',
            'currentSort'         => $sortBy,
            // ── Données de pagination ──────────────────────────────────────────────
            'currentPage'         => $page,
            'totalPages'          => $totalPages,
            'total'               => $total,
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
     *
     * Décision produit V1 : seules les STRUCTURES (et les admins par héritage de rôles)
     * peuvent soumettre des ressources / opportunités.
     * Les artistes et membres standard consultent, mettent en favori et candidatent
     * via le lien externe de la structure — mais ne soumettent plus.
     *
     * Changement : ROLE_USER → ROLE_STRUCTURE
     * ROLE_ADMIN hérite de tous les rôles (security.yaml) et passe donc naturellement.
     */
    #[IsGranted('ROLE_STRUCTURE')]
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
     * Décision produit V1 : seules les STRUCTURES (et admins) soumettent des ressources,
     * donc cette page de suivi n'a de sens que pour elles.
     * Les artistes n'ont plus accès à cette page (ils voient "Mes favoris" à la place).
     *
     * Changement : ROLE_USER → ROLE_STRUCTURE
     *
     * Supporte le filtre par statut via le paramètre query ?status=
     * Valeurs autorisées : draft, pending, published, rejected, archived
     * (correspondant aux cases de l'enum ResourceStatus).
     * Toute valeur invalide est silencieusement ignorée → on affiche toutes les ressources.
     */
    #[IsGranted('ROLE_STRUCTURE')]
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
        // ── Vérification du paywall freemium : alertes réservées aux abonnés ─────
        // Les alertes personnalisées sont une fonctionnalité premium (ADR-0022).
        // On redirige les utilisateurs gratuits vers /tarifs avec un message clair.
        if (!$this->isGranted(FreemiumVoter::FEATURE_ALERTS)) {
            $this->addFlash(
                'info',
                'Les alertes personnalisées sont réservées aux abonnés Bazaart. Découvrez nos offres ci-dessous.'
            );
            return $this->redirectToRoute('app_pricing');
        }

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
    // ROUTE /mes-alertes — alias URL propre vers la page de gestion des alertes
    //
    // La logique métier vit dans alerts() ci-dessus (/resources/alerts).
    // Cette route fournit une URL plus courte et plus mémorisable pour l'accès
    // depuis le bouton "Créer une alerte" du catalogue public.
    //
    // On s'appuie sur le même nom de route (app_resource_alerts) pour que le
    // bouton du header et la navigation utilisent toujours la même destination.
    // Alternativement, on peut créer une route distincte qui rend le même template.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Page "Mes alertes" — URL mémorisable vers les préférences d'alertes.
     *
     * Route : GET/POST /mes-alertes (name: app_resource_alert_edit)
     *
     * Cette route est un point d'entrée secondaire vers la fonctionnalité d'alertes.
     * Elle utilise la même logique que /resources/alerts (app_resource_alerts)
     * et rend le même template, mais depuis un chemin plus court et plus parlant.
     *
     * Pourquoi une route distincte plutôt qu'une redirection ?
     *   - On évite une requête HTTP supplémentaire (301 → nouvelle requête)
     *   - L'URL /mes-alertes est persistante dans la barre d'adresse du navigateur
     *   - Le bouton "Créer une alerte" peut pointer directement ici sans rebond
     *
     * ⚠️ IMPORTANT : ce chemin est HORS du préfixe /resources (défini par #[Route('/resources')]
     * au niveau de la classe). La définition explicite de 'path' ici écrase le préfixe.
     * Symfony applique le préfixe de classe UNIQUEMENT si le chemin de la méthode est relatif.
     * Avec un chemin absolu commençant par '/', le préfixe est ignoré.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/mes-alertes', name: 'alert_edit', methods: ['GET', 'POST'])]
    public function alertEdit(Request $request): Response
    {
        // On réutilise exactement la même logique que alerts().
        // En appelant $this->alerts($request), on évite toute duplication de code.
        // Une seule source de vérité = plus facile à maintenir.
        return $this->alerts($request);
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
     *
     * Deux comportements selon le contexte de l'appel :
     *
     *   ① Appel AJAX (en-tête X-Requested-With: XMLHttpRequest, envoyé par le JS de show.html.twig)
     *      → Réponse JSON : { "favorited": true|false, "count": int }
     *        Le JS met à jour le bouton sans rechargement de page.
     *
     *   ② Appel non-AJAX (formulaire soumis normalement, sans JS — fallback navigabilité)
     *      → Redirige vers la page précédente (Referer) ou vers la fiche resource.
     *        Ajoute un flash message optionnel pour informer l'utilisateur.
     *
     * Le token CSRF est spécifique à chaque ressource (resource_favorite_{id}).
     *
     * @return Response (JsonResponse en AJAX, RedirectResponse en non-AJAX)
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/favorite', name: 'favorite_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFavorite(int $id, Request $request): Response
    {
        // ── Détection AJAX ────────────────────────────────────────────────────
        // isXmlHttpRequest() détecte l'en-tête "X-Requested-With: XMLHttpRequest"
        // envoyé par le fetch() dans show.html.twig. Symfony lit cet en-tête
        // sur toutes les requêtes entrantes via la classe Request.
        $isAjax = $request->isXmlHttpRequest();

        // ── Validation du token CSRF ──────────────────────────────────────────
        // On valide en premier pour éviter tout traitement inutile si forgé.
        if (!$this->isCsrfTokenValid('resource_favorite_' . $id, $request->request->get('_token'))) {
            if ($isAjax) {
                // En AJAX : réponse JSON avec code 403 (le JS logge l'erreur discrètement)
                return new JsonResponse(['error' => 'Token CSRF invalide.'], Response::HTTP_FORBIDDEN);
            }

            // En non-AJAX : flash d'erreur + redirection (pas de JSON brut affiché)
            $this->addFlash('error', 'Action non autorisée (token CSRF invalide).');

            return $this->redirectToFallback($request, $id);
        }

        // ── Récupération de la ressource ──────────────────────────────────────
        $resource = $this->resourceRepository->find($id);
        if ($resource === null) {
            if ($isAjax) {
                return new JsonResponse(['error' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
            }

            $this->addFlash('error', 'Ressource introuvable.');

            // Pas de ressource → on ne peut pas rediriger vers sa fiche, retour liste
            return $this->redirectToRoute('app_resource_index');
        }

        // ── Sécurité métier : ressource publiée uniquement ────────────────────
        // Evite qu'un utilisateur infère l'existence d'une ressource non publiée
        // via la différence de réponse.
        if (!$resource->isPublished() && !$this->isGranted('ROLE_ADMIN')) {
            if ($isAjax) {
                return new JsonResponse(['error' => 'Ressource non disponible.'], Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', 'Cette ressource n\'est pas disponible.');

            return $this->redirectToFallback($request, $id);
        }

        /** @var User $user */
        $user = $this->getUser();

        // ── Toggle favori ─────────────────────────────────────────────────────
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

        // ── Réponse selon le mode ─────────────────────────────────────────────
        if ($isAjax) {
            // Mode AJAX : JSON consommé par le JS de show.html.twig
            return new JsonResponse([
                'favorited' => $favorited,
                'count'     => $count,
            ]);
        }

        // Mode non-AJAX (fallback sans JS) : flash discret + redirection
        // Le message est court et informatif — sera affiché par base_app.html.twig
        // si les flash messages y sont rendus.
        $this->addFlash(
            'success',
            $favorited
                ? 'Ressource ajoutée à vos favoris.'
                : 'Ressource retirée de vos favoris.'
        );

        return $this->redirectToFallback($request, $id);
    }

    /**
     * Calcule la redirection après un toggle favori en mode non-AJAX.
     *
     * Priorité : page précédente (Referer HTTP) → fiche de la ressource.
     *
     * On n'utilise pas la valeur Referer aveuglément : on vérifie qu'elle
     * commence par le même host pour éviter les redirections vers des domaines
     * externes (open-redirect). Symfony ne fournit pas de helper natif pour
     * ça, donc on implémente la vérification manuellement.
     */
    private function redirectToFallback(Request $request, int $resourceId): RedirectResponse
    {
        $referer  = $request->headers->get('referer', '');
        $baseUrl  = $request->getSchemeAndHttpHost(); // ex: https://bazaart.fr

        // Redirection vers le Referer uniquement s'il appartient au même site
        // (sécurité anti open-redirect).
        if ($referer !== '' && str_starts_with($referer, $baseUrl)) {
            return new RedirectResponse($referer);
        }

        // Fallback sûr : fiche de la ressource concernée
        return $this->redirectToRoute('app_resource_show', ['id' => $resourceId]);
    }
}

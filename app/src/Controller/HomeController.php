<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Matching\MatchResult;
use App\Entity\User;
use App\Enum\ArtistLookingFor;
use App\Enum\LegalStatus;
use App\Repository\DisciplineRepository;
use App\Repository\ForumThreadRepository;
use App\Repository\ResourceAlertRepository;
use App\Repository\ResourceRepository;
use App\Service\MatchingFormSessionService;
use App\Service\MatchingService;
use App\Service\SubscriptionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Contrôleur de la page d'accueil publique.
 *
 * La vitrine (/) est accessible à TOUS, connectés ou non.
 * La navbar dans base.html.twig gère déjà l'affichage conditionnel
 * (bouton "Connexion" vs avatar utilisateur).
 *
 * AJOUT LOT C (ADR-0021) :
 *   Si l'utilisateur est connecté et a ROLE_ARTIST, on calcule ses matchs
 *   via MatchingService et on les passe au template pour la section swipe.
 *   Le Twig gère ensuite les 3 états :
 *     1. Artiste avec profil complet → section swipe avec cartes
 *     2. Artiste sans profil complet → encart invitation à compléter le profil
 *     3. Non connecté ou non artiste → encart invitation à s'inscrire/se connecter
 */
final class HomeController extends AbstractController
{
    /**
     * Nombre d'opportunités affichées dans la section "Ça se passe maintenant".
     * Correspond exactement au nombre de cartes statiques qu'il y avait avant
     * la dynamisation — on garde la même grille.
     */
    private const int OPPORTUNITIES_COUNT = 3;

    /**
     * Nombre de fils de discussion affichés dans la section "Communauté".
     * Correspond au nombre de .lp-thread codés en dur remplacés par la boucle Twig.
     */
    private const int THREADS_COUNT = 3;

    /**
     * Nombre maximum de matchs chargés pour la section swipe.
     * On ne charge pas tous les matchs (potentiellement des centaines) :
     * l'UI affiche les cartes une à une, 20 suffit pour un swipe fluide.
     * Si l'artiste a swipé tout, le JS affichera un état vide propre.
     */
    private const int SWIPE_MATCHES_LIMIT = 20;

    /**
     * Injection des repositories via le constructeur.
     *
     * Symfony résout automatiquement les dépendances grâce à l'autowiring :
     * le type-hint suffit, pas besoin de configuration dans services.yaml.
     *
     * On utilise readonly pour respecter les bonnes pratiques PHP 8.1+ :
     * une fois injectés, les repositories ne doivent pas être réaffectés.
     *
     * NOTE LOT C (correctif) : ResourceFavoriteRepository a été retiré.
     * La variable $favoriteIds était calculée et passée au template mais
     * n'était jamais lue dans le Twig — requête SQL inutile à chaque visite
     * de la home par un artiste. On ne marque pas les favoris dans le swipe
     * (décision Lot C : le bouton "Intéressé(e)" ajoute toujours en favori,
     * jamais de toggle depuis le swipe).
     */
    public function __construct(
        private readonly ResourceRepository        $resourceRepository,
        private readonly ForumThreadRepository     $forumThreadRepository,
        // MatchingService : calcul des matchs artiste <-> ressources (Lot B/C)
        private readonly MatchingService           $matchingService,
        // Pour savoir si l'utilisateur a déjà une alerte de matching active
        private readonly ResourceAlertRepository   $alertRepository,
        // SubscriptionChecker : gestion du paywall freemium (Lot D ADR-0022)
        private readonly SubscriptionChecker       $subscriptionChecker,
        // CsrfTokenManager : génération du token CSRF pour /swipe/record-view (Lot D)
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        // DisciplineRepository : pour passer les disciplines au formulaire matching home
        private readonly DisciplineRepository      $disciplineRepository,
        // MatchingFormSessionService : lecture des réponses sauvegardées en session
        private readonly MatchingFormSessionService $matchingFormSessionService,
    ) {}

    /**
     * Page d'accueil — route "/"
     *
     * La route est nommée "app_home" — on peut y faire référence
     * dans les templates avec : path('app_home')
     *
     * DONNÉES PASSÉES AU TEMPLATE :
     *   opportunities    Resource[]     — N dernières opportunités publiées
     *   recentThreads    ForumThread[]  — N derniers fils de discussion
     *   swipeMatches     array<array>   — Matchs sérialisés pour le swipe (ou [])
     *   swipeCount       int            — Nombre total de matchs positifs
     *   profileComplete  bool           — Le profil artiste est-il utilisable pour le matching ?
     *   hasAlert         bool           — L'artiste a-t-il déjà une alerte active ?
     *   swipeCsrfToken       string|null    — Token CSRF pour le toggle favori et l'alerte
     *   alertCsrfToken       string|null    — Token CSRF spécifique à la création d'alerte
     *   isArtist             bool           — L'utilisateur connecté est-il un artiste ?
     *   isSubscribed         bool           — L'utilisateur est-il abonné (ou admin) ? (Lot D)
     *   swipeRemainingViews  int            — Consultations de matchs restantes cette semaine (Lot D)
     *   swipeLimitReached    bool           — Limite hebdomadaire atteinte ? (Lot D)
     *   swipeRecordViewCsrf  string|null    — Token CSRF pour POST /swipe/record-view (Lot D)
     */
    #[Route('/', name: 'app_home')]
    public function index(Request $request): Response
    {
        // On affiche la vitrine pour tout le monde.
        // La navbar se charge d'adapter son contenu selon l'état de connexion
        // via {{ app.user }} dans base.html.twig.

        // ── Chargement des opportunités pour la section "Ça se passe maintenant" ──
        $opportunities = $this->resourceRepository->findPublished(
            typeId:       null,
            disciplineId: null,
            search:       null,
            page:         1,
            limit:        self::OPPORTUNITIES_COUNT,
            // hideExpired: true → la vitrine est une surface publique.
            hideExpired:  true,
        );

        // ── Chargement des fils de discussion pour le widget "Communauté" ─────────
        $recentThreads = $this->forumThreadRepository->findRecentForHome(self::THREADS_COUNT);

        // ── Données de matching (seulement pour les artistes connectés) ───────────
        //
        // On initialise les valeurs par défaut : ces variables seront renvoyées
        // telles quelles si l'utilisateur n'est pas connecté ou n'est pas artiste.
        $swipeMatches    = [];     // Cartes à afficher dans la section swipe
        $swipeCount      = 0;      // Compteur de matchs positifs
        $profileComplete = false;  // Le profil permet-il un matching pertinent ?
        $hasAlert        = false;  // L'artiste a-t-il déjà une alerte active ?
        $isArtist        = false;  // L'utilisateur est-il artiste ?
        // Tokens CSRF pour les actions POST depuis le swipe
        // Ils ne sont générés que si l'utilisateur est connecté.
        $swipeCsrfToken  = null;
        $alertCsrfToken  = null;

        // ── Variables du paywall freemium (Lot D ADR-0022) ──────────────────────
        // Initialisées avec les valeurs "accès libre" par défaut.
        // Seront recalculées si l'utilisateur est artiste connecté.
        $isSubscribed        = false; // L'utilisateur est-il abonné (ou admin) ?
        $swipeRemainingViews = 0;     // Consultations de matchs restantes cette semaine
        $swipeLimitReached   = false; // Limite hebdomadaire atteinte ?
        $swipeRecordViewCsrf = null;  // Token CSRF pour POST /swipe/record-view

        // getUser() retourne null si non connecté, sinon l'objet utilisateur.
        $user = $this->getUser();

        if ($user instanceof User && $this->isGranted('ROLE_ARTIST')) {
            // L'utilisateur est connecté ET artiste : on charge les données de swipe.
            $isArtist = true;

            // ── Vérification de l'abonnement (paywall Lot D) ─────────────────────
            // On calcule ici pour que le template puisse afficher le bon état.
            // isSubscribed() = true si ROLE_ADMIN OU abonnement Stripe actif.
            $isSubscribed = $this->subscriptionChecker->isSubscribed($user);

            // Nombre de consultations restantes cette semaine ISO.
            // Pour les abonnés/admins : PHP_INT_MAX (qu'on convertit en -1 pour le JS).
            // Pour les gratuits : entre 0 et FREE_WEEKLY_MATCH_LIMIT.
            $rawRemaining        = $this->subscriptionChecker->getRemainingMatchViews($user);
            // On borne à un entier raisonnable pour le Twig (PHP_INT_MAX serait absurde à afficher)
            $swipeRemainingViews = $isSubscribed ? PHP_INT_MAX : $rawRemaining;
            // La limite est atteinte si l'utilisateur n'est PAS abonné et n'a plus de consultation
            $swipeLimitReached   = !$isSubscribed && $rawRemaining <= 0;

            // ── Token CSRF pour l'endpoint record-view (Lot D) ───────────────────
            // Généré ici pour que swipe.js puisse l'utiliser sans lire le DOM.
            // On passe le token au template en data-attribute sur la section swipe.
            // CsrfTokenManagerInterface est injecté dans le constructeur (autowiring).
            $swipeRecordViewCsrf = $this->csrfTokenManager
                ->getToken('swipe_record_view')
                ->getValue();

            // ── Calcul de la complétude du profil ────────────────────────────────
            // On vérifie que le profil artiste existe et a au moins une discipline
            // et un objectif lookingFor renseigné. Sans ces données, le matching
            // retournera des résultats quasi aléatoires (les scores seront tous 0).
            $profile = $user->getArtistProfile();
            $profileComplete = (
                $profile !== null
                && !$profile->getDisciplines()->isEmpty()
                && !empty($user->getLookingFor())
            );

            // ── Calcul des matchs (uniquement si profil utilisable ET pas limite atteinte) ──
            // Si la limite est atteinte, on ne calcule pas les matchs : pas de cartes à afficher.
            // Le template affichera l'état "limite atteinte" à la place.
            if ($profileComplete && !$swipeLimitReached) {
                // getMatchesForUser() retourne TOUS les matchs triés par score desc.
                // On ne charge que les N premiers pour éviter de sérialiser des centaines
                // de MatchResult en mémoire — la section swipe en a besoin de peu.
                $allMatches = $this->matchingService->getMatchesForUser($user);

                // Filtre : on ne garde que les matchs positifs (score > 0)
                $positiveMatches = array_filter(
                    $allMatches,
                    fn(MatchResult $r) => $r->score > 0
                );

                // On compte le total AVANT de tronquer (pour le compteur affiché)
                $swipeCount = count($positiveMatches);

                // On tronque aux N premiers pour la section swipe
                $positiveMatches = array_slice(array_values($positiveMatches), 0, self::SWIPE_MATCHES_LIMIT);

                // On sérialise chaque MatchResult en tableau PHP simple
                // (MatchResult::toArray() expose tous les champs nécessaires au template)
                $swipeMatches = array_map(
                    fn(MatchResult $r) => $r->toArray(),
                    $positiveMatches
                );

                // ── État de l'alerte ──────────────────────────────────────────────
                // On vérifie si une alerte active existe pour cet utilisateur.
                // Cela permet au template de changer le libellé du bouton
                // ("Alerte activée" au lieu de "Créer une alerte") sans refresh.
                $existingAlert = $this->alertRepository->findByUser($user);
                $hasAlert = $existingAlert !== null && $existingAlert->isNotifyOnNewResource();
            }

            // ── Génération des tokens CSRF pour le JavaScript ─────────────────────
            // Les tokens sont générés UNIQUEMENT si l'utilisateur est artiste,
            // car les boutons POST sont absents du HTML pour les non-artistes.
            //
            // swipeCsrfToken : utilisé par le JS pour le POST toggle favori.
            //   Le nom 'resource_favorite_{id}' est construit dans le JS en
            //   concaténant ce préfixe avec l'ID de la ressource. Ici on passe
            //   un token "générique" : le vrai token par ressource est généré
            //   via Twig csrf_token() dans la boucle du template pour chaque carte.
            //   On passe donc null ici — le template génère les tokens par carte.
            //
            // alertCsrfToken : token unique pour POST /swipe/alert
            //   Généré une seule fois pour tout le formulaire d'alerte
            //   (il n'est pas lié à une ressource spécifique).
            //
            // Note : csrf_token() est disponible dans Twig via le service CSRF.
            // On ne le génère PAS en PHP dans le controller : Twig le fait directement.
            // La génération PHP ci-dessous serait redondante (le template utilise
            // {{ csrf_token('swipe_alert') }} directement).
            //
            // swipeRecordViewCsrf : déjà généré ci-dessus dans le bloc paywall Lot D.
        }

        // ── Données pour le formulaire matching home (visiteur / profil incomplet) ──
        //
        // Le formulaire multi-étapes est affiché quand :
        //   - L'utilisateur n'est pas connecté (visiteur)
        //   - L'utilisateur est artiste mais profil incomplet
        // Dans les autres états (swipe, paywall, non-artiste connecté), ces données
        // sont chargées mais non utilisées → coût SQL marginal (2 requêtes légères).
        //
        // On les charge dans tous les cas pour simplifier le code Twig
        // (pas de bloc conditionnel PHP pour décider de charger ou non).
        $matchingFormDisciplines = $this->disciplineRepository->findAll();
        $matchingFormSessionData = $this->matchingFormSessionService->getSessionData($request->getSession());

        // Token CSRF pour le formulaire matching multi-étapes
        // Le nom 'matching_form' est partagé avec MatchingFormController::saveStep()
        // et MatchingFormController::submit()
        $matchingFormCsrf = $this->csrfTokenManager->getToken('matching_form')->getValue();

        return $this->render('vitrine/index.html.twig', [
            // ── Section opportunités publiques ──────────────────────────────────
            'opportunities'   => $opportunities,
            // ── Section communauté / forum ──────────────────────────────────────
            'recentThreads'   => $recentThreads,

            // ── Formulaire multi-étapes matching (visiteur / profil incomplet) ──
            // Ces variables alimentent le partial matching/_matching_form.html.twig
            'matchingForm_disciplines'   => $matchingFormDisciplines,
            'matchingForm_lookingFor'    => ArtistLookingFor::cases(),
            'matchingForm_legalStatuses' => LegalStatus::cases(),
            'matchingForm_savedData'     => $matchingFormSessionData,
            'matchingForm_isLoggedIn'    => ($user instanceof User),
            'matchingForm_csrfToken'     => $matchingFormCsrf,

            // ── Section swipe matching (Lot C) ───────────────────────────────────
            // Chaque match est un tableau avec les clés : resource_id, title,
            // resource_type, score, breakdown, deadline, city, country, logo_url, external_url.
            // (Voir MatchResult::toArray() pour la structure complète.)
            'swipeMatches'    => $swipeMatches,
            // Nombre total de matchs positifs (peut dépasser SWIPE_MATCHES_LIMIT
            // si l'artiste a beaucoup de matchs — on l'affiche dans le compteur).
            'swipeCount'      => $swipeCount,
            // false → profil incomplet → le template affiche l'invitation à compléter
            // true  → profil utilisable → le template affiche les cartes de swipe
            'profileComplete' => $profileComplete,
            // false → pas d'alerte active → le bouton "Créer une alerte" est disponible
            // true  → alerte déjà active → on affiche "Alerte activée" à la place
            'hasAlert'        => $hasAlert,
            // true = l'utilisateur a ROLE_ARTIST (pour les blocs conditionnels Twig)
            'isArtist'        => $isArtist,

            // ── Variables paywall freemium (Lot D ADR-0022) ──────────────────────
            // isSubscribed : true si ROLE_ADMIN OU abonnement Stripe actif
            'isSubscribed'         => $isSubscribed,
            // swipeRemainingViews : nombre de consultations restantes (PHP_INT_MAX si abonné/admin)
            'swipeRemainingViews'  => $swipeRemainingViews,
            // swipeLimitReached : true si utilisateur gratuit ayant atteint sa limite hebdo
            'swipeLimitReached'    => $swipeLimitReached,
            // swipeRecordViewCsrf : token CSRF pour POST /swipe/record-view (null si non artiste)
            'swipeRecordViewCsrf'  => $swipeRecordViewCsrf,
            // swipeWeeklyLimit : la constante limite pour affichage dans l'UI
            'swipeWeeklyLimit'     => SubscriptionChecker::FREE_WEEKLY_MATCH_LIMIT,
        ]);
    }
}

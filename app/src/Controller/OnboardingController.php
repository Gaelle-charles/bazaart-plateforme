<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Onboarding\OnboardingStep2DTO;
use App\DTO\Onboarding\OnboardingStep3DTO;
use App\DTO\Onboarding\OnboardingStep4MatchingDTO;
use App\Entity\User;
use App\Enum\ArtistLookingFor;
use App\Enum\Country;
use App\Enum\LegalStatus;
use App\Repository\DisciplineRepository;
use App\Service\MatchingFormSessionService;
use App\Service\OnboardingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OnboardingController — Parcours d'onboarding matching pour les nouveaux artistes.
 *
 * Reformulé en Lot A (ADR-0021/0022) : l'onboarding n'est plus OBLIGATOIRE pour
 * naviguer le site. Il reste accessible et sera proposé à l'entrée du module
 * matching (Lot C).
 *
 * Les 4 étapes du parcours artiste (Phase 2 du matching) :
 *   Étape 1 : "Es-tu artiste ou structure ?" (aiguillage)
 *   Étape 2 : "Ton profil" (nom affiché + discipline(s) + localisation)
 *   Étape 3 : "Que recherches-tu ?" (objectifs — lookingFor)
 *   Étape 4 : "Ton statut juridique" (legalStatus — optionnel, pour le matching)
 *
 * L'ancienne étape 4 "alertes ressources" est supprimée de l'onboarding.
 * Les alertes deviendront un opt-in avec consentement dans le module matching (Lot C).
 *
 * Convention Symfony :
 *   - #[IsGranted('ROLE_USER')] sur toutes les routes → seuls les utilisateurs connectés
 *   - Toute la logique métier est dans OnboardingService
 *   - Le contrôleur ne fait qu'orchestrer : récupérer la requête, appeler le service, répondre
 *
 * IMPORTANT : ces routes sont dans la whitelist de OnboardingGatingListener.
 * Le préfixe "app_onboarding_" est exempté pour éviter une boucle de redirection.
 */
#[Route('/onboarding', name: 'app_onboarding_')]
#[IsGranted('ROLE_USER')]
class OnboardingController extends AbstractController
{
    public function __construct(
        private readonly OnboardingService         $onboardingService,
        private readonly DisciplineRepository      $disciplineRepository,
        // Service de session pour le carryover depuis le formulaire matching home.
        // Permet de pré-remplir l'onboarding si l'utilisateur avait déjà rempli
        // le formulaire home avant de s'inscrire (Flux A du matching form).
        private readonly MatchingFormSessionService $matchingFormSession,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 1 — Artiste ou Structure ?
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 1 : l'utilisateur choisit son type de compte.
     *
     * GET  → Affiche le formulaire de choix (artiste / structure)
     * POST → Traite le choix :
     *   - "artiste"   → redirect vers l'étape 2 (profil artiste)
     *   - "structure" → redirect vers app_structure_register
     *
     * Lot A : si l'utilisateur a déjà complété l'onboarding, on le renvoie
     * au dashboard (évite qu'il refasse l'onboarding inutilement).
     */
    #[Route('/etape-1', name: 'step1', methods: ['GET', 'POST'])]
    public function step1(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Garde : si l'onboarding est déjà complété, on redirige vers le dashboard
        if ($user->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            // Vérification CSRF obligatoire avant tout traitement
            if (!$this->isCsrfTokenValid('onboarding_step1', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de securite invalide. Merci de recharger la page.');
                return $this->redirectToRoute('app_onboarding_step1');
            }

            $type = $request->request->getString('account_type');

            if ($type === 'structure') {
                // L'utilisateur est une structure → parcours Lot 3
                return $this->redirectToRoute('app_structure_register');
            }

            if ($type === 'artiste') {
                // L'utilisateur est un artiste → on passe à l'étape 2
                return $this->redirectToRoute('app_onboarding_step2');
            }

            // Aucun choix valide → erreur + on reste à l'étape 1
            $this->addFlash('error', 'Choisis si tu es artiste ou structure pour continuer.');
        }

        return $this->render('onboarding/step1.html.twig');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 2 — Profil artiste (nom, discipline, localisation)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 2 : l'artiste remplit son profil de matching.
     *
     * GET  → Affiche le formulaire (pré-rempli si profil existant — retour en arrière)
     * POST → Valide + sauvegarde le profil via OnboardingService::saveStep2()
     *
     * Données collectées (essentielles pour le matching) :
     *   - Nom d'affichage (obligatoire)
     *   - Disciplines (au moins 1, obligatoire pour le matching)
     *   - Localisation (optionnelle)
     *   - Bio, liens (optionnels — conservés si déjà renseignés)
     */
    #[Route('/etape-2', name: 'step2', methods: ['GET', 'POST'])]
    public function step2(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Garde : onboarding déjà complété → dashboard
        if ($user->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            // Vérification CSRF
            if (!$this->isCsrfTokenValid('onboarding_step2', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de securite invalide. Merci de recharger la page.');
                return $this->redirectToRoute('app_onboarding_step2');
            }

            // Construction du DTO depuis les données POST
            $dto = OnboardingStep2DTO::fromRequest($request->request->all());

            // Délégation au service (retourne null si succès, ou un message d'erreur)
            $error = $this->onboardingService->saveStep2($user, $dto);

            if ($error === null) {
                // Succès → on passe à l'étape 3
                return $this->redirectToRoute('app_onboarding_step3');
            }
        }

        // Toutes les disciplines disponibles pour le formulaire
        $disciplines = $this->disciplineRepository->findAll();

        // Profil existant (si l'utilisateur revient à l'étape 2 — pré-remplissage)
        $existingProfile = $user->getArtistProfile();

        // ── Carryover depuis le formulaire matching home (Flux A) ─────────────
        // Si l'utilisateur avait rempli le formulaire home avant de s'inscrire,
        // on pré-sélectionne les disciplines de la session dans le template.
        // Les données de session sont passées au template pour que le partial
        // matching/_step_disciplines.html.twig puisse les afficher sélectionnées.
        $matchingSessionData = $this->matchingFormSession->getSessionData($request->getSession());

        return $this->render('onboarding/step2.html.twig', [
            'disciplines'          => $disciplines,
            'existing_profile'     => $existingProfile,
            'error'                => $error,
            // Données de carryover depuis la session matching (peut être vide si pas de session)
            // Le template les utilise pour pré-sélectionner les disciplines si le profil
            // n'existe pas encore (premier passage à l'étape 2).
            'matching_session_data' => $matchingSessionData,
            // Liste des pays regroupés par optgroup pour le <select> de localisation.
            // casesGroupedFr() retourne array<string, list<Country>> :
            //   'France et Outre-mer' → [France, Guadeloupe, Guyane, ...]
            //   'Europe'              → [Albanie, Allemagne, ...]
            // Le template onboarding/step2.html.twig itère sur ces groupes
            // pour rendre des <optgroup> dans le <select name="location">.
            'countries'            => Country::casesGroupedFr(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 3 — Que recherches-tu ?
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 3 : l'artiste indique ce qu'il recherche sur la plateforme.
     *
     * GET  → Affiche les options (cases à cocher) + champ libre optionnel
     * POST → Valide + sauvegarde via OnboardingService::saveStep3()
     *
     * Les choix correspondent aux valeurs de ArtistLookingFor.
     * La case "Autre" ouvre un champ libre (lookingForOther).
     *
     * Ces données alimentent le matching (Lot C) pour personnaliser les suggestions.
     */
    #[Route('/etape-3', name: 'step3', methods: ['GET', 'POST'])]
    public function step3(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Garde : onboarding déjà complété
        if ($user->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Garde : l'étape 2 doit être complétée avant
        // (l'artiste doit avoir un profil avant de définir ses objectifs)
        if ($user->getArtistProfile() === null) {
            return $this->redirectToRoute('app_onboarding_step2');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('onboarding_step3', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de securite invalide. Merci de recharger la page.');
                return $this->redirectToRoute('app_onboarding_step3');
            }

            $dto   = OnboardingStep3DTO::fromRequest($request->request->all());
            $error = $this->onboardingService->saveStep3($user, $dto);

            if ($error === null) {
                // Succès → on passe à l'étape 4 (statut juridique)
                return $this->redirectToRoute('app_onboarding_step4');
            }
        }

        // ── Carryover depuis la session matching pour le pré-remplissage ────
        $matchingSessionData = $this->matchingFormSession->getSessionData($request->getSession());

        // Priorité du pré-remplissage :
        //   1. Données déjà enregistrées sur l'utilisateur (lookingFor en BDD)
        //   2. Carryover depuis la session matching home
        $selectedValues = $user->getLookingFor() ?? [];
        $lookingForOther = $user->getLookingForOther();

        if (empty($selectedValues) && !empty($matchingSessionData['looking_for'])) {
            $selectedValues  = $matchingSessionData['looking_for'];
            $lookingForOther = $matchingSessionData['looking_for_other'];
        }

        return $this->render('onboarding/step3.html.twig', [
            // On passe tous les cas de l'enum pour les afficher dynamiquement
            'looking_for_options' => ArtistLookingFor::cases(),
            // Valeurs pré-remplies (BDD ou carryover session)
            'selected_values'     => $selectedValues,
            'looking_for_other'   => $lookingForOther,
            'error'               => $error,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 4 — Statut juridique (Lot A matching)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 4 (dernière) : statut juridique de l'artiste + finalisation.
     *
     * GET  → Affiche la liste des statuts (radio buttons)
     * POST → Sauvegarde legalStatus sur ArtistProfile + marque l'onboarding complété
     *         + envoie l'email de bienvenue → redirect vers le dashboard
     *
     * Lot A — Changement par rapport à l'ancienne étape 4 :
     *   L'ancienne étape "alertes ressources" est supprimée de l'onboarding.
     *   La configuration des alertes sera un opt-in avec consentement dans le
     *   module matching (Lot C).
     *
     *   Le statut juridique est OPTIONNEL : l'artiste peut finaliser sans le choisir.
     *   (Certains artistes sont en structuration ou ne connaissent pas leur statut.)
     */
    #[Route('/etape-4', name: 'step4', methods: ['GET', 'POST'])]
    public function step4(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Garde : onboarding déjà complété
        if ($user->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Garde : l'étape 2 doit être complétée (profil artiste requis)
        if ($user->getArtistProfile() === null) {
            return $this->redirectToRoute('app_onboarding_step2');
        }

        // Garde : l'étape 3 doit être complétée (lookingFor requis).
        // On utilise empty() plutôt que === null : un tableau vide [] signifie
        // que l'artiste n'a rien sélectionné et doit repasser par l'étape 3.
        if (empty($user->getLookingFor())) {
            return $this->redirectToRoute('app_onboarding_step3');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('onboarding_step4', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de securite invalide. Merci de recharger la page.');
                return $this->redirectToRoute('app_onboarding_step4');
            }

            $dto = OnboardingStep4MatchingDTO::fromRequest($request->request->all());

            // saveStep4AndComplete() finalise toujours l'onboarding (pas de validation bloquante).
            // Le statut juridique est optionnel — null est accepté.
            $this->onboardingService->saveStep4AndComplete($user, $dto);

            // ── Nettoyage de la session matching (carryover) ──────────────────
            // Si l'utilisateur venait du formulaire matching de la home (Flux A),
            // les données de session ont été utilisées pour pré-remplir l'onboarding.
            // On les vide maintenant : elles ne sont plus nécessaires.
            $this->matchingFormSession->clearSession($request->getSession());

            // Onboarding complété avec succès
            $this->addFlash('success', 'Bienvenue sur Bazaart ! Ton profil est pret.');

            // Redirige vers le dashboard
            return $this->redirectToRoute('app_dashboard');
        }

        // Données pour le formulaire : tous les cas de l'enum LegalStatus
        $legalStatusOptions = LegalStatus::cases();

        // À ce point, getArtistProfile() est garanti non-null :
        // le guard ci-dessus redirige si le profil est null.
        // PHPStan le sait aussi — on utilise -> (pas ?->) pour éviter l'avertissement.
        $selectedLegalStatus = $user->getArtistProfile()->getLegalStatus();

        // ── Carryover depuis la session matching pour le statut juridique ─────
        // Si le profil n'a pas encore de statut ET que la session a une valeur,
        // on la propose en pré-sélection.
        if ($selectedLegalStatus === null) {
            $matchingSessionData = $this->matchingFormSession->getSessionData($request->getSession());
            if (!empty($matchingSessionData['legal_status'])) {
                $selectedLegalStatus = LegalStatus::tryFrom($matchingSessionData['legal_status']);
            }
        }

        return $this->render('onboarding/step4.html.twig', [
            'legal_status_options'   => $legalStatusOptions,
            'selected_legal_status'  => $selectedLegalStatus,
        ]);
    }
}

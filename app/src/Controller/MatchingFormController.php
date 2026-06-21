<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Onboarding\OnboardingStep2DTO;
use App\DTO\Onboarding\OnboardingStep3DTO;
use App\DTO\Onboarding\OnboardingStep4MatchingDTO;
use App\Entity\User;
use App\Service\MatchingFormSessionService;
use App\Service\OnboardingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MatchingFormController — Gère le formulaire multi-étapes de matching affiché sur la home.
 *
 * CE CONTROLLER GÈRE DEUX FLUX DISTINCTS :
 *
 *   Flux A : Visiteur non connecté
 *     - L'utilisateur remplit les étapes discipline/lookingFor/legalStatus
 *     - Le JS enregistre chaque étape en session via POST /matching/step (saveStep)
 *     - A la soumission finale, les réponses sont persistées en session
 *     - L'utilisateur est redirigé vers /register?intent=artist
 *     - Après création de compte + confirmation email, verifyEmail() détecte
 *       l'intent "artist" et redirige vers /onboarding avec pré-remplissage
 *
 *   Flux B : Artiste connecté avec profil incomplet
 *     - Même formulaire (les partials sont identiques)
 *     - La soumission finale (POST /matching/form) sauvegarde directement le profil
 *       via OnboardingService (réutilise la logique d'onboarding)
 *     - Redirige vers la home (la section swipe sera maintenant visible)
 *
 * ENDPOINTS :
 *   POST /matching/step   → saveStep()   : sauvegarde une étape en session (AJAX + non-AJAX)
 *   POST /matching/form   → submit()     : soumission finale du formulaire complet
 *
 * SÉCURITÉ :
 *   - CSRF sur tous les POST (token "matching_form")
 *   - Validation serveur sur chaque étape (identique à l'onboarding)
 *   - Pas de dépendance à l'état connecté pour saveStep() (session uniquement)
 *
 * PHILOSOPHIE "THIN CONTROLLER" :
 *   Toute la logique de validation et de persistance est dans MatchingFormSessionService
 *   et OnboardingService. Ce controller orchestre uniquement.
 */
#[Route('/matching', name: 'app_matching_')]
final class MatchingFormController extends AbstractController
{
    public function __construct(
        // Service de gestion de la session pour le formulaire multi-étapes
        private readonly MatchingFormSessionService $sessionService,
        // Service d'onboarding : réutilisé pour sauvegarder le profil (Flux B)
        private readonly OnboardingService          $onboardingService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINT : Sauvegarde d'une étape en session (AJAX + progressive enhancement)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sauvegarde les données d'une étape du formulaire matching en session.
     *
     * Appelé par matching-form.js après chaque "Continuer".
     * Fonctionne aussi sans JS : la soumission du formulaire complet appelle
     * saveStep() implicitement dans submit().
     *
     * PARAMÈTRES POST attendus (selon l'étape) :
     *   _csrf_token      : token CSRF
     *   step             : numéro de l'étape (1, 2 ou 3)
     *   disciplines[]    : (étape 1) tableau d'IDs de disciplines
     *   looking_for[]    : (étape 2) tableau de valeurs ArtistLookingFor
     *   looking_for_other: (étape 2) texte libre si "autre" coché
     *   legal_status     : (étape 3) valeur de LegalStatus (optionnel)
     *
     * RÉPONSE :
     *   JSON si requête AJAX, redirect sinon (progressive enhancement).
     */
    #[Route('/step', name: 'form_step', methods: ['POST'])]
    public function saveStep(Request $request): Response
    {
        // ── Validation CSRF ────────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('matching_form', $request->request->get('_csrf_token'))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(
                    ['error' => 'Token de sécurité invalide. Rechargez la page.'],
                    Response::HTTP_FORBIDDEN
                );
            }
            $this->addFlash('error', 'Token de sécurité invalide. Merci de recharger la page.');

            return $this->redirectToRoute('app_home');
        }

        // ── Extraction des données de l'étape ─────────────────────────────────
        $step = (int) $request->request->get('step', 0);

        // Délègue la validation et la sauvegarde en session au service dédié.
        // Le service retourne null en cas de succès, ou un message d'erreur.
        $error = $this->sessionService->saveStepToSession(
            session: $request->getSession(),
            step:    $step,
            data:    $request->request->all(),
        );

        if ($request->isXmlHttpRequest()) {
            if ($error !== null) {
                return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json(['success' => true, 'step' => $step]);
        }

        // Fallback non-AJAX : on redirige vers la home (le formulaire multi-step n'a
        // pas de page "résultat" propre — l'état est géré par JS).
        if ($error !== null) {
            $this->addFlash('error', $error);
        }

        // '_fragment' est le paramètre réservé Symfony pour générer une ancre (#swipe-section).
        // (Utiliser '#' génère à tort une query string ?%23=swipe-section.)
        return $this->redirectToRoute('app_home', ['_fragment' => 'swipe-section']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINT : Soumission finale du formulaire complet
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Traite la soumission finale du formulaire multi-étapes de matching.
     *
     * FLUX A (visiteur non connecté) :
     *   - Sauvegarde les réponses complètes en session
     *   - Redirige vers /register?intent=artist
     *   - Après inscription + confirmation email, l'onboarding sera pré-rempli
     *
     * FLUX B (artiste connecté, profil incomplet) :
     *   - Sauvegarde immédiatement le profil via OnboardingService
     *     (discipline + lookingFor + legalStatus)
     *   - Redirige vers la home pour afficher la section swipe
     *
     * SÉCURITÉ :
     *   CSRF vérifié. Validation serveur via OnboardingService (Flux B).
     *   Pour le Flux A, la validation réelle se fait à l'onboarding (après inscription).
     */
    #[Route('/form', name: 'form_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        // ── Validation CSRF ────────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('matching_form', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Merci de recharger la page.');

            return $this->redirectToRoute('app_home');
        }

        // ── Sauvegarde de toutes les étapes en session (les 3 d'un coup) ─────
        // Cela couvre le cas "sans JS" où l'utilisateur envoie tout le formulaire
        // d'un seul coup, ainsi que le cas JS où les étapes ont été sauvées
        // progressivement (idempotent : on réécrit juste les mêmes données).
        $data = $request->request->all();

        // On sauvegarde les 3 étapes en session (le service ignore les étapes vides)
        $this->sessionService->saveAllStepsToSession($request->getSession(), $data);

        // ── Détermination du flux ──────────────────────────────────────────────
        $user = $this->getUser();

        if (!$user instanceof User || !$this->isGranted('ROLE_ARTIST')) {
            // FLUX A : visiteur ou utilisateur sans ROLE_ARTIST

            // Guard "sans JS" : si l'utilisateur a soumis le formulaire sans JS,
            // seule l'étape 1 était visible — les étapes 2 et 3 restent cachées
            // (attribut HTML [hidden]). On vérifie qu'au moins une discipline a été
            // sélectionnée : sans discipline, l'onboarding post-inscription serait
            // entièrement vide, ce qui serait déroutant.
            // Avec JS, cette vérification est également faite côté client avant
            // soumission (validateStep(1)), mais un attaquant peut contourner le JS.
            $savedData = $this->sessionService->getSessionData($request->getSession());
            if (empty($savedData['discipline_ids'])) {
                // Aucune discipline sélectionnée : on informe l'utilisateur et
                // on le renvoie vers le formulaire (ancre #swipe-section).
                $this->addFlash(
                    'error',
                    'Sélectionne au moins une discipline pour continuer.'
                );

                return $this->redirectToRoute('app_home', ['_fragment' => 'swipe-section']);
            }

            // Les réponses sont en session → l'onboarding post-inscription les récupérera.
            return $this->redirectToRoute('app_register', ['intent' => 'artist']);
        }

        // FLUX B : artiste connecté avec profil incomplet
        // On réutilise les DTOs et services de l'onboarding pour sauvegarder
        // le profil directement (même logique, même service — pas de duplication).

        $errors = [];

        // ── Étape 1 : Nom d'artiste + Localisation + Disciplines ──────────────
        //
        // ÉVOLUTION OPTION B :
        //   Le formulaire envoie désormais display_name et location dans l'étape 1.
        //   OnboardingStep2DTO::fromRequest() lit ces deux clés depuis $data.
        //   Contrairement à l'onboarding complet, location est REQUIS ici
        //   (critère de complétude du profil : MatchingProfileChecker).
        //   On N'utilise plus le fallback email comme placeholder — le nom
        //   est maintenant collecté dans le formulaire lui-même (champ requis).
        $step2Dto = OnboardingStep2DTO::fromRequest($data);

        // Validation explicite de display_name dans le Flux B.
        // (Normalement déjà validé par saveStep1 dans saveAllStepsToSession ci-dessus,
        //  mais on protège aussi contre le cas sans JS où le service aurait échoué.)
        if (trim($step2Dto->displayName) === '') {
            $this->addFlash('error', 'Indique ton nom d\'artiste pour continuer.');

            return $this->redirectToRoute('app_home', ['_fragment' => 'swipe-section']);
        }

        // Validation explicite de location : OnboardingService::saveStep2() accepte
        // location = null (champ optionnel dans l'onboarding général), mais ici on
        // EXIGE une localisation pour rendre le profil complet au sens de
        // MatchingProfileChecker. On bloque donc si location est vide.
        if ($step2Dto->location === null || trim($step2Dto->location) === '') {
            $this->addFlash('error', 'Indique ta localisation (ville, pays).');

            return $this->redirectToRoute('app_home', ['_fragment' => 'swipe-section']);
        }

        $error2 = $this->onboardingService->saveStep2($user, $step2Dto);
        if ($error2 !== null) {
            $errors[] = $error2;
        }

        // ── Étape 2 : lookingFor ──────────────────────────────────────────────
        if (empty($errors)) {
            $step3Dto = OnboardingStep3DTO::fromRequest($data);
            $error3   = $this->onboardingService->saveStep3($user, $step3Dto);
            if ($error3 !== null) {
                $errors[] = $error3;
            }
        }

        // ── Étape 3 : statut juridique (optionnel) ────────────────────────────
        if (empty($errors)) {
            $step4Dto = OnboardingStep4MatchingDTO::fromRequest($data);
            // saveStep4AndComplete() finalise l'onboarding et envoie l'email de bienvenue.
            // Elle n'a pas de validation bloquante (statut juridique optionnel).
            $this->onboardingService->saveStep4AndComplete($user, $step4Dto);
        }

        if (!empty($errors)) {
            // Erreur(s) de validation : on flash et on redirige vers la home.
            // La section matching affichera à nouveau le formulaire avec les
            // données en session pré-remplies.
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            // '_fragment' est le paramètre réservé Symfony pour générer une ancre (#swipe-section).
            // (Utiliser '#' génère à tort une query string ?%23=swipe-section.)
            return $this->redirectToRoute('app_home', ['_fragment' => 'swipe-section']);
        }

        // ── Succès : profil sauvegardé → section swipe disponible ─────────────
        $this->addFlash('success', 'Ton profil est prêt ! Voici tes opportunités personnalisées.');

        // On vide les données de session du formulaire matching (plus nécessaires).
        $this->sessionService->clearSession($request->getSession());

        // '_fragment' est le paramètre réservé Symfony pour générer une ancre (#swipe-section).
        // (Utiliser '#' génère à tort une query string ?%23=swipe-section.)
        return $this->redirectToRoute('app_home', ['_fragment' => 'swipe-section']);
    }
}

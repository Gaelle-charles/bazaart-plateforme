<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Onboarding\OnboardingStep2DTO;
use App\DTO\Onboarding\OnboardingStep3DTO;
use App\DTO\Onboarding\OnboardingStep4DTO;
use App\Entity\User;
use App\Enum\ArtistLookingFor;
use App\Repository\DisciplineRepository;
use App\Repository\ResourceTypeRepository;
use App\Service\OnboardingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OnboardingController — Parcours d'onboarding obligatoire pour les nouveaux utilisateurs.
 *
 * Ce contrôleur gère les 4 étapes du parcours artiste :
 *   Étape 1 : "Es-tu artiste ou structure ?" (aiguillage)
 *   Étape 2 : "Ton profil artiste" (nom, localisation, disciplines, bio, liens)
 *   Étape 3 : "Que recherches-tu ?" (objectifs — choix multiples + texte libre)
 *   Étape 4 : "Tes alertes ressources" (disciplines + types + fréquence)
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
        private readonly OnboardingService    $onboardingService,
        private readonly DisciplineRepository $disciplineRepository,
        private readonly ResourceTypeRepository $resourceTypeRepository,
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
     *   - "structure" → redirect vers app_structure_register (Lot 3)
     *
     * Le gating (OnboardingGatingListener) redirige ici dès qu'un compte
     * non onboardé tente d'accéder à une page protégée.
     *
     * Note : si l'utilisateur a déjà complété l'onboarding, on le renvoie
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
                // L'utilisateur est une structure → on le redirige vers le parcours Lot 3
                // La route app_structure_register gère le formulaire de candidature structure.
                return $this->redirectToRoute('app_structure_register');
            }

            if ($type === 'artiste') {
                // L'utilisateur est un artiste → on passe à l'étape 2
                return $this->redirectToRoute('app_onboarding_step2');
            }

            // Aucun choix valide → on affiche une erreur et on reste à l'étape 1
            $this->addFlash('error', 'Choisis si tu es artiste ou structure pour continuer.');
        }

        return $this->render('onboarding/step1.html.twig');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 2 — Profil artiste
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 2 : l'artiste remplit son profil de base.
     *
     * GET  → Affiche le formulaire (pré-rempli si profil existant — retour en arrière)
     * POST → Valide + sauvegarde le profil via OnboardingService::saveStep2()
     *
     * Données collectées :
     *   - Nom d'affichage (obligatoire)
     *   - Localisation (optionnel)
     *   - Disciplines (au moins 1, obligatoire)
     *   - Bio (optionnel)
     *   - URL portfolio, site web (optionnel)
     *   - Instagram handle (optionnel)
     */
    #[Route('/etape-2', name: 'step2', methods: ['GET', 'POST'])]
    public function step2(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Garde : onboarding déjà complété
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

            // Délégation au service (retourne null si succès, ou une string d'erreur)
            $error = $this->onboardingService->saveStep2($user, $dto);

            if ($error === null) {
                // Succès → on passe à l'étape 3
                return $this->redirectToRoute('app_onboarding_step3');
            }
        }

        // On charge toutes les disciplines pour les afficher dans le formulaire
        $disciplines = $this->disciplineRepository->findAll();

        // Profil existant (si l'utilisateur revient à l'étape 2 — pré-remplissage)
        $existingProfile = $user->getArtistProfile();

        return $this->render('onboarding/step2.html.twig', [
            'disciplines'      => $disciplines,
            'existing_profile' => $existingProfile,
            'error'            => $error,
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
     * Les choix sont des cases à cocher correspondant à ArtistLookingFor.
     * La case "Autre" ouvre un champ libre (lookingForOther).
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
                return $this->redirectToRoute('app_onboarding_step4');
            }
        }

        return $this->render('onboarding/step3.html.twig', [
            // On passe tous les cas de l'enum pour les afficher dynamiquement
            // sans avoir à les dupliquer dans le template
            'looking_for_options' => ArtistLookingFor::cases(),
            // Valeurs déjà sélectionnées (si l'utilisateur revient en arrière)
            'selected_values'     => $user->getLookingFor() ?? [],
            'looking_for_other'   => $user->getLookingForOther(),
            'error'               => $error,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 4 — Alertes ressources
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 4 (dernière) : configuration des alertes email.
     *
     * GET  → Affiche le formulaire avec pré-sélection intelligente basée
     *         sur les réponses de l'étape 3 (via OnboardingService::getPreselectedResourceTypeIds)
     * POST → Valide + sauvegarde le ResourceAlert + marque l'onboarding comme complété
     *         + envoie l'email de bienvenue → redirect vers le dashboard
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

        // Garde : les étapes précédentes doivent être complétées
        if ($user->getArtistProfile() === null) {
            return $this->redirectToRoute('app_onboarding_step2');
        }

        if ($user->getLookingFor() === null) {
            return $this->redirectToRoute('app_onboarding_step3');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('onboarding_step4', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de securite invalide. Merci de recharger la page.');
                return $this->redirectToRoute('app_onboarding_step4');
            }

            $dto = OnboardingStep4DTO::fromRequest($request->request->all());
            // saveStep4AndComplete() retourne toujours null (étape 4 sans validation bloquante).
            // Elle persiste les alertes, marque l'onboarding complété et envoie l'email.
            $this->onboardingService->saveStep4AndComplete($user, $dto);

            // Onboarding complété avec succès
            $this->addFlash('success', 'Bienvenue sur Bazaart ! Ton profil est pret.');

            // Redirige vers le dashboard : le gating ne bloquera plus cet utilisateur
            return $this->redirectToRoute('app_dashboard');
        }

        // Données pour le formulaire
        $disciplines   = $this->disciplineRepository->findAll();
        $resourceTypes = $this->resourceTypeRepository->findAll();

        // Pré-sélection intelligente des types de ressources selon l'étape 3
        $preselectedTypeIds = $this->onboardingService->getPreselectedResourceTypeIds($user);

        // Pré-sélection des disciplines de l'artiste (depuis son profil).
        // getArtistProfile() est garanti non-null ici grâce au guard ci-dessus
        // (on a redirigé vers step2 si le profil était null).
        // PHPStan : on utilise le profil déjà chargé pour éviter un appel redondant.
        /** @var \App\Entity\ArtistProfile $artistProfile */
        $artistProfile = $user->getArtistProfile();
        $artistDisciplineIds = array_map(
            static fn ($d) => $d->getId(),
            $artistProfile->getDisciplines()->toArray()
        );

        return $this->render('onboarding/step4.html.twig', [
            'disciplines'             => $disciplines,
            'resource_types'          => $resourceTypes,
            'preselected_type_ids'    => $preselectedTypeIds,
            'preselected_disc_ids'    => $artistDisciplineIds,
            'alert_frequency_options' => \App\Enum\AlertFrequency::cases(),
            'error'                   => $error,
        ]);
    }
}

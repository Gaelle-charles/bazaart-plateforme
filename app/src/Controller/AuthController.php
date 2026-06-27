<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\RegisterDTO;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\EmailVerificationService;
use App\Service\MatchingFormSessionService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

/**
 * AuthController — Gère l'authentification et la vérification d'email.
 *
 * Responsabilités :
 *   - login()                  : formulaire de connexion
 *   - logout()                 : déconnexion (géré par Symfony Security)
 *   - register()               : inscription + envoi email de confirmation
 *   - checkEmail()             : page "vérifie ta boîte mail" après inscription
 *   - verifyEmail()            : validation du lien de confirmation cliqué
 *   - resendVerificationEmail(): renvoyer l'email de confirmation
 *
 * Convention Symfony :
 *   Ce contrôleur est volontairement thin (mince) — pas de logique métier ici.
 *   Toute la logique est déléguée à AuthService et EmailVerificationService.
 */
class AuthController extends AbstractController
{
    /**
     * Injection par autowiring :
     *   - AuthService               : logique d'inscription (hachage, vérification doublon email)
     *   - EmailVerificationService  : génération URL signée + envoi email de confirmation
     *   - UserRepository            : retrouver l'utilisateur pour la vérification
     *   - RateLimiterFactory $registerLimiter : limite les créations de comptes (5/15min/IP)
     *   - RateLimiterFactory $verifyEmailLimiter : limite les renvois d'email (3/10min/email)
     *   - Security                  : connexion programmatique après vérification email (Lot 1)
     *
     * Note : le rate limiting de /login est géré automatiquement par Symfony Security
     * via login_throttling dans security.yaml — aucun code nécessaire ici.
     */
    /**
     * Clé de session pour l'intent d'inscription.
     * Valeur possible : 'artist' (indique que l'utilisateur vient du formulaire matching home).
     */
    private const SESSION_INTENT_KEY = 'bazaart_register_intent';

    public function __construct(
        private readonly AuthService                $authService,
        private readonly EmailVerificationService   $emailVerificationService,
        private readonly UserRepository             $userRepository,
        private readonly RateLimiterFactory         $registerLimiter,
        private readonly RateLimiterFactory         $verifyEmailLimiter,
        // Logger injecté pour tracer les abus de rate limiting sur le renvoi d'email
        // (détection d'un attaquant qui tenterait de spammer la boîte d'un utilisateur).
        // Symfony autorise LoggerInterface dans les contrôleurs via autowiring.
        private readonly LoggerInterface            $logger,
        // Security (Symfony\Bundle\SecurityBundle\Security) est le service central
        // de Symfony Security. Sa méthode login() permet de connecter un utilisateur
        // par programmation, sans passer par le formulaire de login.
        // C'est l'API recommandée depuis Symfony 6.2 (remplace LoginContext + TokenStorage).
        private readonly Security                   $security,
        // MatchingFormSessionService injecté pour lire si des données de matching
        // sont stockées en session (pré-remplissage de l'onboarding après inscription).
        private readonly MatchingFormSessionService $matchingFormSession,
    ) {}

    // ─── Route : /login ───────────────────────────────────────────────────────

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Redirige si déjà connecté (vers le bon dashboard selon le rôle)
        if ($this->getUser()) {
            return $this->isGranted('ROLE_ADMIN')
                ? $this->redirectToRoute('app_admin_dashboard')
                : $this->redirectToRoute('app_dashboard');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    // ─── Route : /logout ──────────────────────────────────────────────────────

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Géré automatiquement par Symfony Security
    }

    // ─── Route : /register ────────────────────────────────────────────────────

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->isGranted('ROLE_ADMIN')
                ? $this->redirectToRoute('app_admin_dashboard')
                : $this->redirectToRoute('app_dashboard');
        }

        // ── Capture du paramètre ?intent=artist ────────────────────────────────
        //
        // Quand un visiteur vient du formulaire matching de la home
        // (clic sur "Créer mon compte artiste"), on reçoit ?intent=artist.
        // On stocke cet intent en session pour l'utiliser après la vérification
        // d'email (cf. verifyEmail() ci-dessous).
        //
        // On ne lit l'intent QUE sur les GET (pas sur les POST de soumission du formulaire)
        // pour éviter qu'un POST malveillant manipule le flux de redirection.
        if ($request->isMethod('GET')) {
            $intent = $request->query->get('intent');
            if ($intent === 'artist') {
                // Stocke l'intent en session — survivra jusqu'à la vérification d'email
                $request->getSession()->set(self::SESSION_INTENT_KEY, 'artist');
            }
        }

        $error = null;

        if ($request->isMethod('POST')) {
            // ── Rate limiting sur /register ────────────────────────────────────
            // On crée un "jeton" identifié par l'IP de la requête.
            // consume(1) décrémente le compteur de 1 et retourne un RateLimit.
            // Si la limite (5/15 min) est dépassée, isAccepted() renvoie false.
            $limiter = $this->registerLimiter->create($request->getClientIp() ?? 'unknown');
            $limit = $limiter->consume(1);

            if (!$limit->isAccepted()) {
                // Calcul du temps d'attente pour informer l'utilisateur
                $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
                $minutes    = (int) ceil($retryAfter / 60);

                $this->addFlash('error', sprintf(
                    'Trop de tentatives d\'inscription. Veuillez réessayer dans %d minute%s.',
                    $minutes,
                    $minutes > 1 ? 's' : ''
                ));

                return $this->redirectToRoute('app_register');
            }

            // Validation du token CSRF avant tout traitement du formulaire.
            // isCsrfTokenValid() compare le token soumis avec celui stocké en session,
            // en utilisant l'identifiant 'registration' défini dans le template Twig.
            if (!$this->isCsrfTokenValid('registration', $request->request->get('_csrf_token'))) {
                return $this->render('auth/register.html.twig', [
                    'error' => 'Token de sécurité invalide. Veuillez recharger la page et réessayer.',
                ]);
            }

            $dto = RegisterDTO::fromArray($request->request->all());

            if ($dto === null) {
                // RegisterDTO::fromArray() retourne null si l'un des champs OBLIGATOIRES est absent :
                //   - first_name (prénom)
                //   - last_name (nom de famille)
                //   - email
                //   - password
                //   - confirm_password
                // Le display_name (nom affiché) est lui facultatif → null ne cause pas ce message.
                $error = 'Le prénom, le nom, l\'email, le mot de passe et sa confirmation sont obligatoires.';
            } elseif (!$dto->isEmailValid()) {
                $error = 'Adresse email invalide.';
            } elseif (!$dto->isPasswordStrong()) {
                // Message conforme à la politique CDC §9 : 10 chars, 1 majuscule, 1 chiffre
                $error = 'Le mot de passe doit contenir au moins 10 caractères, une lettre majuscule et un chiffre.';
            } elseif (!$dto->doPasswordsMatch()) {
                // Vérification que les deux saisies du mot de passe sont identiques
                $error = 'Les mots de passe ne correspondent pas.';
            } else {
                $user = $this->authService->register($dto);

                if ($user === null) {
                    $error = 'Cet email est déjà utilisé.';
                } else {
                    // ── Envoi de l'email de confirmation ────────────────────────
                    //
                    // On envoie l'email APRÈS que le user est persisté en BDD
                    // (l'id est nécessaire pour générer la signature HMAC du bundle).
                    //
                    // En cas d'échec SMTP, le service logge l'erreur mais ne la
                    // propage pas — l'utilisateur peut demander un renvoi depuis
                    // la page "vérifie ta boîte mail".
                    $this->emailVerificationService->sendVerificationEmail($user);

                    // ── Carryover du nom affiché (display_name) ─────────────────
                    //
                    // Si l'utilisateur a saisi un nom affiché / nom de scène à l'inscription
                    // (champ facultatif), on le mémorise en session matching AVANT de rediriger.
                    // L'onboarding (étape 1) le pré-remplira automatiquement.
                    //
                    // La manipulation de session est ENTIÈREMENT déléguée au service dédié
                    // (prefillDisplayName) : le contrôleur ne connaît ni la clé de session
                    // ni la forme du tableau (cf. CLAUDE.md §12). Le service préserve les
                    // autres données matching déjà présentes (cas artiste venu de la home).
                    if ($dto->displayName !== null) {
                        $this->matchingFormSession->prefillDisplayName(
                            $request->getSession(),
                            $dto->displayName
                        );
                    }

                    // Stocke l'email en session pour pré-remplir la page "vérifie ta boîte"
                    // et pour le formulaire de renvoi.
                    // Note : on utilise une clé de session dédiée pour éviter les collisions.
                    $request->getSession()->set('pending_verification_email', $user->getEmail());

                    // On redirige vers la page "vérifie ta boîte mail" plutôt que vers /login.
                    // L'utilisateur doit confirmer son email AVANT de pouvoir se connecter.
                    return $this->redirectToRoute('app_check_email');
                }
            }
        }

        return $this->render('auth/register.html.twig', [
            'error' => $error,
        ]);
    }

    // ─── Route : /verifier-email/confirmation ─────────────────────────────────

    /**
     * Page affichée après l'inscription pour inviter l'utilisateur à vérifier sa boîte.
     *
     * Cette page remplace la redirection vers /login après inscription.
     * Elle affiche :
     *   - L'adresse email à laquelle le lien a été envoyé
     *   - Un bouton pour renvoyer l'email si le lien n'est pas arrivé
     *
     * Sécurité : accessible sans connexion (PUBLIC_ACCESS dans security.yaml).
     */
    #[Route('/verifier-email/confirmation', name: 'app_check_email')]
    public function checkEmail(Request $request): Response
    {
        // Récupère l'email depuis la session (mis en place dans register())
        // Si quelqu'un arrive directement sur cette page sans passer par /register,
        // on redirige vers /register pour éviter une page vide.
        $email = $request->getSession()->get('pending_verification_email');

        if ($email === null) {
            return $this->redirectToRoute('app_register');
        }

        return $this->render('auth/check_email.html.twig', [
            'email' => $email,
        ]);
    }

    // ─── Route : /verifier-email ──────────────────────────────────────────────

    /**
     * Route de vérification d'email — appelée quand l'utilisateur clique sur le lien.
     *
     * Fonctionnement :
     *   1. On charge l'utilisateur par son id (passé dans l'URL signée)
     *   2. On délègue la validation de la signature au service
     *   3. En cas de succès : isVerified=true persisted, connexion automatique via
     *      Security::login(), redirection vers app_dashboard
     *   4. En cas d'échec  : message d'erreur clair avec possibilité de renvoyer
     *
     * L'URL signée contient les paramètres : ?id=X&expires=Y&token=Z
     * Le bundle les parse automatiquement depuis Request::getUri().
     *
     * ── Lot 2 (à venir) ───────────────────────────────────────────────────────
     * Quand le module Onboarding sera implémenté (Lot 2), la redirection finale
     * devra pointer vers la route d'onboarding si l'utilisateur n'a pas encore
     * complété son profil. Le gating se fera probablement dans un EventListener
     * sur KernelEvents::REQUEST (ou dans le dashboard lui-même), pas ici.
     */
    #[Route('/verifier-email', name: 'app_verify_email')]
    public function verifyEmail(Request $request): Response
    {
        // ── Identifier l'utilisateur depuis le paramètre "id" dans l'URL ──────
        //
        // Le bundle SymfonyCasts inclut l'id de l'utilisateur dans l'URL signée.
        // On le récupère ici pour charger l'entité User correspondante.
        // Si l'id est absent ou invalide → on ne peut pas valider → /register.
        $userId = $request->query->get('id');

        if ($userId === null) {
            // URL malformée (pas générée par notre bundle) → retour à l'inscription
            $this->addFlash('error', 'Lien de confirmation invalide. Merci de t\'inscrire à nouveau.');
            return $this->redirectToRoute('app_register');
        }

        // Charge l'utilisateur par son id
        $user = $this->userRepository->find((int) $userId);

        if ($user === null) {
            // L'utilisateur a peut-être supprimé son compte entre-temps
            $this->addFlash('error', 'Ce compte n\'existe plus. Merci de t\'inscrire à nouveau.');
            return $this->redirectToRoute('app_register');
        }

        // ── Si l'email est déjà vérifié : traitement contextuel ──────────────
        //
        // Cas typique : l'utilisateur reclique un lien dont le compte a déjà été confirmé.
        // On distingue 3 sous-cas :
        //
        //   a) L'utilisateur EST DÉJÀ AUTHENTIFIÉ en session
        //      → on le renvoie au dashboard (le gating onboarding s'occupera du reste).
        //
        //   b) L'utilisateur N'EST PAS authentifié ET la signature du lien est ENCORE VALIDE
        //      → On peut lui faire confiance : le lien prouve qu'il possède la boîte email.
        //      → On marque isVerified=true (idempotent), on le connecte, et on redirige
        //        vers le dashboard (le gating le renverra vers l'onboarding si besoin).
        //      → UX : "j'avais ouvert l'email dans un autre navigateur, je reclique" → ça marche.
        //
        //   c) L'utilisateur N'EST PAS authentifié ET la signature est INVALIDE/EXPIRÉE
        //      → Flash informatif + redirection /login (on ne peut pas le connecter sans preuve).
        //
        // SÉCURITÉ : on ne connecte JAMAIS sans avoir validé la signature HMAC.
        if ($user->isVerified()) {
            // Sous-cas a) : déjà authentifié → redirection directe
            if ($this->getUser() !== null) {
                $this->addFlash('success', 'Ton adresse email est déjà confirmée.');
                return $this->redirectToRoute('app_dashboard');
            }

            // Sous-cas b) et c) : on valide la signature pour décider
            try {
                // handleVerification() est idempotent : si isVerified=true,
                // il repose setIsVerified(true) + flush() sans erreur.
                // On l'utilise ici pour valider la signature ET garder le code DRY.
                $this->emailVerificationService->handleVerification($request, $user);

                // Signature valide → connexion automatique (même logique qu'un premier clic)
                $this->addFlash('success', 'Adresse email confirmée. Bienvenue sur Bazaart !');
                $request->getSession()->remove('pending_verification_email');

                $loginResponse = $this->security->login(
                    $user,
                    'security.authenticator.form_login.main',
                    'main'
                );

                if ($loginResponse !== null) {
                    return $loginResponse;
                }

                // Même logique de redirection que pour un premier clic :
                // si intent=artist ou des données matching sont en session → onboarding.
                $intent = $request->getSession()->get(self::SESSION_INTENT_KEY);
                $hasMatchingData = $this->matchingFormSession->hasSessionData($request->getSession());
                if ($intent === 'artist' || $hasMatchingData) {
                    $request->getSession()->remove(self::SESSION_INTENT_KEY);
                    return $this->redirectToRoute('app_onboarding_step2');
                }

                // Le gating renverra l'utilisateur vers l'onboarding si besoin
                return $this->redirectToRoute('app_dashboard');

            } catch (VerifyEmailExceptionInterface) {
                // Sous-cas c) : signature invalide ou expirée → pas de connexion
                // On affiche un message clair plutôt que de renvoyer vers /register
                // (le compte existe déjà, l'utilisateur doit juste se connecter normalement).
                $this->addFlash('info', 'Ton compte est déjà confirmé. Connecte-toi pour continuer.');
                return $this->redirectToRoute('app_login');
            }
        }

        // ── Déléguer la validation de la signature au service ─────────────────
        try {
            // handleVerification() fait deux choses atomiquement :
            //   1. Valide la signature HMAC (lève une exception si invalide/expiré)
            //   2. Persiste user.isVerified = true via EntityManager::flush()
            //
            // IMPORTANT : le flush() est fait AVANT qu'on appelle Security::login()
            // ci-dessous. C'est indispensable car UserChecker::checkPreAuth() vérifie
            // isVerified en base — si on appelait login() avant le flush(), le checker
            // lirait encore isVerified=false et bloquerait la connexion.
            $this->emailVerificationService->handleVerification($request, $user);

        } catch (VerifyEmailExceptionInterface $e) {
            // Le bundle lève différentes exceptions selon le problème :
            //   - ExpiredSignatureException   → lien expiré (> 1 heure)
            //   - InvalidSignatureException   → URL falsifiée
            //   - WrongEmailVerifyException   → email changé depuis l'envoi
            //
            // $e->getReason() retourne un message en anglais (interne) — on affiche
            // notre propre message en français pour l'UX.
            $this->addFlash('error', sprintf(
                'Le lien de confirmation est invalide ou a expiré. '
                . 'Utilise le bouton ci-dessous pour en recevoir un nouveau. '
                . '(Détail technique : %s)',
                $e->getReason()
            ));

            // Stocke l'email en session pour le formulaire de renvoi
            $request->getSession()->set('pending_verification_email', $user->getEmail());

            // Redirige vers la page "vérifie ta boîte mail" pour proposer le renvoi.
            // NE PAS connecter l'utilisateur ici : la signature est invalide.
            return $this->redirectToRoute('app_check_email');
        }

        // ── Succès : email confirmé → connexion automatique ───────────────────
        //
        // À ce point :
        //   - La signature est valide (aucune exception levée)
        //   - user.isVerified = true est persisté en base (flush() fait dans handleVerification)
        //   - UserChecker::checkPreAuth() laissera passer cet utilisateur
        //
        // Security::login() est l'API officielle Symfony 6.2+ pour l'authentification
        // programmatique. Elle :
        //   1. Crée un UsernamePasswordToken pour le firewall spécifié
        //   2. Stocke le token dans la session (TokenStorage)
        //   3. Déclenche l'événement SecurityEvents::INTERACTIVE_LOGIN
        //   4. Régénère l'id de session (protection contre la fixation de session)
        //
        // Le deuxième paramètre est le nom du firewall déclaré dans security.yaml.
        // Ici 'main' — c'est le firewall qui couvre toutes les URLs de l'application.
        //
        // Note : login() retourne une Response|null. Si le firewall a des listeners
        // qui veulent rediriger (ex: remember_me, _target_path), ils peuvent renvoyer
        // une Response. On l'utilise si elle existe, sinon on redirige vers le dashboard.
        $this->addFlash('success', 'Adresse email confirmée. Bienvenue sur Bazaart !');

        // On vide la session de confirmation : le compte est maintenant actif
        $request->getSession()->remove('pending_verification_email');

        // Connexion programmatique sur le firewall 'main'.
        //
        // Signature complète de Security::login() :
        //   login(UserInterface $user, ?string $authenticatorName, ?string $firewallName, ...)
        //
        // Pourquoi on passe 'security.authenticator.form_login.main' ?
        //
        // Notre firewall 'main' déclare DEUX authenticators :
        //   - form_login     (formulaire email/mot de passe)
        //   - GoogleAuthenticator (custom_authenticators)
        //
        // Si $authenticatorName = null, Symfony lève une LogicException car il ne sait
        // pas lequel choisir ("Too many authenticators were found for the firewall").
        // On doit donc lui indiquer lequel utiliser.
        //
        // On choisit 'security.authenticator.form_login.main' car :
        //   1. C'est l'authenticator "natif" pour les connexions par email/mot de passe.
        //   2. Security::login() ne re-vérifie PAS les credentials quand on lui passe
        //      directement un UserInterface — il crée juste le token de session.
        //   3. GoogleAuthenticator serait incorrect sémantiquement (pas de token OAuth ici).
        //
        // C'est le service ID auto-généré par Symfony pour le form_login du firewall 'main'.
        // Format : security.authenticator.form_login.<nom_du_firewall>
        $loginResponse = $this->security->login($user, 'security.authenticator.form_login.main', 'main');

        // ── Redirection post-vérification email ──────────────────────────────
        //
        // Si Security a produit sa propre réponse (cas rare avec des listeners spéciaux
        // comme remember_me, _target_path), on l'utilise en priorité.
        if ($loginResponse !== null) {
            return $loginResponse;
        }

        // ── Carryover matching : redirection vers l'onboarding si intent=artist ─
        //
        // Cas de figure :
        //   1. Le visiteur a rempli le formulaire matching de la home (données en session)
        //   2. Il a cliqué "Créer mon compte artiste" → ?intent=artist stocké en session
        //   3. Il a créé son compte + confirmé son email
        //   4. ICI : on le redirige vers l'étape 2 de l'onboarding (disciplines),
        //      qui sera pré-remplie depuis la session via OnboardingController.
        //
        // LIMITE CONNUE (à signaler à l'utilisateur si besoin) :
        //   Si l'utilisateur confirme son email dans un AUTRE navigateur ou onglet
        //   que celui dans lequel il a rempli le formulaire matching, la session
        //   (intent + données matching) ne sera pas partagée et le pré-remplissage
        //   sera absent. Dans ce cas, l'onboarding s'affichera vide mais fonctionnel.
        //   Ce cas est rare et acceptable pour V1.
        $intent = $request->getSession()->get(self::SESSION_INTENT_KEY);
        $hasMatchingData = $this->matchingFormSession->hasSessionData($request->getSession());

        if ($intent === 'artist' || $hasMatchingData) {
            // Nettoyage de l'intent (pas des données matching — OnboardingController les vide)
            $request->getSession()->remove(self::SESSION_INTENT_KEY);

            // Redirection vers l'étape 2 de l'onboarding (disciplines) avec pré-remplissage
            return $this->redirectToRoute('app_onboarding_step2');
        }

        // Cas normal (sans intent artist) : redirection vers le dashboard.
        // Le gating onboarding proposera à l'utilisateur de compléter son profil.
        return $this->redirectToRoute('app_dashboard');
    }

    // ─── Route : /verifier-email/renvoyer ─────────────────────────────────────

    /**
     * Renvoi de l'email de confirmation.
     *
     * Accessible depuis la page "check_email" (lien/bouton dédié).
     * Protégé par rate limiting pour éviter le spam (3 renvois / 10 min / email).
     *
     * L'email est récupéré :
     *   1. Depuis le POST body (formulaire sur la page check_email)
     *   2. Ou depuis la session en fallback
     */
    #[Route('/verifier-email/renvoyer', name: 'app_resend_verify_email', methods: ['POST'])]
    public function resendVerificationEmail(Request $request): Response
    {
        // ── Validation CSRF ───────────────────────────────────────────────────
        // Même protection que sur le formulaire /register : empêche une soumission
        // externe de déclencher des renvois d'emails à l'insu de l'utilisateur.
        if (!$this->isCsrfTokenValid('resend_verify', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Recharge la page et réessaie.');
            return $this->redirectToRoute('app_register');
        }

        // ── Récupérer l'email à vérifier ──────────────────────────────────────
        // L'email peut venir du formulaire (POST) ou de la session.
        $email = $request->request->getString('email')
              ?: $request->getSession()->get('pending_verification_email', '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse email invalide. Merci de t\'inscrire à nouveau.');
            return $this->redirectToRoute('app_register');
        }

        // ── Rate limiting par email ────────────────────────────────────────────
        //
        // On limite par email (et non par IP) pour éviter qu'un attaquant
        // spamme la boîte d'un utilisateur en changeant d'IP.
        // 3 renvois / 10 min est suffisamment généreux pour une utilisation normale.
        $limiter = $this->verifyEmailLimiter->create($email);
        $limit   = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            $minutes    = (int) ceil($retryAfter / 60);

            // Log de sécurité : trace les abus potentiels.
            // Un attaquant qui connaît l'email d'un utilisateur pourrait appuyer en
            // boucle sur "renvoyer" pour saturer sa boîte mail. Le log permet de le détecter.
            $this->logger->warning('Rate limit renvoi email de confirmation dépassé', [
                'email' => $email,
            ]);

            $this->addFlash('error', sprintf(
                'Trop de demandes de renvoi. Attends %d minute%s avant de réessayer.',
                $minutes,
                $minutes > 1 ? 's' : ''
            ));

            // Remets l'email en session pour la page check_email
            $request->getSession()->set('pending_verification_email', $email);
            return $this->redirectToRoute('app_check_email');
        }

        // ── Charger l'utilisateur et vérifier qu'il existe ────────────────────
        $user = $this->userRepository->findOneBy(['email' => $email]);

        if ($user === null || $user->isVerified()) {
            // L'utilisateur n'existe pas, ou son email est déjà confirmé.
            // On ne révèle pas si l'email existe (anti-énumération).
            // Dans les deux cas, on affiche un message neutre et on redirige.
            $this->addFlash('success', 'Si un compte non confirmé existe pour cette adresse, un email a été envoyé.');
            return $this->redirectToRoute('app_login');
        }

        // ── Renvoyer l'email de confirmation ──────────────────────────────────
        $this->emailVerificationService->sendVerificationEmail($user);

        $this->addFlash('success', 'Email de confirmation renvoyé. Vérifie ta boîte mail (et tes spams).');

        // Remet l'email en session pour la page check_email
        $request->getSession()->set('pending_verification_email', $email);

        return $this->redirectToRoute('app_check_email');
    }
}

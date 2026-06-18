<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\RegisterDTO;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\EmailVerificationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
     *
     * Note : le rate limiting de /login est géré automatiquement par Symfony Security
     * via login_throttling dans security.yaml — aucun code nécessaire ici.
     */
    public function __construct(
        private readonly AuthService              $authService,
        private readonly EmailVerificationService $emailVerificationService,
        private readonly UserRepository           $userRepository,
        private readonly RateLimiterFactory       $registerLimiter,
        private readonly RateLimiterFactory       $verifyEmailLimiter,
        // Logger injecté pour tracer les abus de rate limiting sur le renvoi d'email
        // (détection d'un attaquant qui tenterait de spammer la boîte d'un utilisateur).
        // Symfony autorise LoggerInterface dans les contrôleurs via autowiring.
        private readonly LoggerInterface          $logger,
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
                // Le fromArray() retourne null si email, password OU confirm_password sont absents
                $error = 'Les champs email, mot de passe et confirmation sont obligatoires.';
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
     *   3. En cas de succès : isVerified=true, flash succès, redirection vers /login
     *   4. En cas d'échec  : message d'erreur clair avec possibilité de renvoyer
     *
     * L'URL signée contient les paramètres : ?id=X&expires=Y&token=Z
     * Le bundle les parse automatiquement depuis Request::getUri().
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

        // ── Si l'email est déjà vérifié : on le signale et on redirige ────────
        // Cas typique : l'utilisateur clique deux fois sur le lien.
        // On redirige vers /login avec un message positif plutôt que d'afficher une erreur.
        if ($user->isVerified()) {
            $this->addFlash('success', 'Ton adresse email est déjà confirmée. Tu peux te connecter.');
            return $this->redirectToRoute('app_login');
        }

        // ── Déléguer la validation de la signature au service ─────────────────
        try {
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

            // Redirige vers la page "vérifie ta boîte mail" pour proposer le renvoi
            return $this->redirectToRoute('app_check_email');
        }

        // ── Succès : email confirmé ────────────────────────────────────────────
        $this->addFlash('success', 'Adresse email confirmée. Tu peux maintenant te connecter.');

        // On vide la session : le compte est maintenant actif
        $request->getSession()->remove('pending_verification_email');

        return $this->redirectToRoute('app_login');
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

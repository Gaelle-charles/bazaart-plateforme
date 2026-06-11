<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ResetPasswordDTO;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ForgotPasswordController — Parcours de réinitialisation du mot de passe.
 *
 * Ce contrôleur gère deux routes :
 *
 *   1. GET|POST /mot-de-passe-oublie (app_forgot_password)
 *      → Formulaire de saisie de l'email
 *      → Appelle PasswordResetService::requestReset()
 *      → Affiche TOUJOURS le même message neutre (anti-énumération)
 *
 *   2. GET|POST /reinitialiser-mot-de-passe/{token} (app_reset_password)
 *      → GET : valide le token (validateToken) et affiche le formulaire de nouveau mdp
 *      → POST : valide CSRF + DTO + appelle resetPassword() → flash + redirect
 *
 * ─── Conventions respectées ──────────────────────────────────────────────────
 * - Pas de logique métier : délégation totale à PasswordResetService.
 * - CSRF obligatoire sur les deux formulaires POST.
 * - Rate limiting sur /mot-de-passe-oublie (password_reset_limiter, 5/15min/IP).
 * - Routes déclarées en PUBLIC_ACCESS dans security.yaml.
 *
 * ─── Séparation AuthController / ForgotPasswordController ───────────────────
 * On crée un contrôleur dédié (pas dans AuthController) car :
 *   - Le parcours reset est fonctionnellement indépendant du login/register.
 *   - AuthController injecte déjà $registerLimiter — ajouter $passwordResetLimiter
 *     alourdirait le constructeur sans raison.
 *   - Responsabilité unique : AuthController = authentification, ForgotPasswordController = reset.
 */
class ForgotPasswordController extends AbstractController
{
    /**
     * Injection par autowiring :
     *   - PasswordResetService : toute la logique métier du reset
     *   - RateLimiterFactory $passwordResetLimiter : rate limiting sur la demande de reset
     *     → Le nom "passwordResetLimiter" correspond à "password_reset_limiter" dans framework.yaml
     *       (Symfony convertit snake_case → camelCase pour l'autowiring des RateLimiterFactory)
     */
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
        private readonly RateLimiterFactory $passwordResetLimiter,
    ) {}

    // ─── Route 1 : Formulaire de demande de réinitialisation ─────────────────

    /**
     * Affiche et traite le formulaire "mot de passe oublié".
     *
     * GET : affiche le formulaire email.
     * POST : appelle requestReset() et affiche TOUJOURS le même message neutre.
     *
     * Anti-énumération : qu'un compte existe ou non pour l'email saisi, l'utilisateur
     * voit le même message. Cela empêche de déduire si un email est inscrit ou non
     * en testant la réponse du formulaire.
     *
     * Rate limiting : 5 tentatives / 15 min / IP (password_reset_limiter dans framework.yaml).
     * Protège contre le spam d'emails de reset et les attaques par force brute.
     */
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        // Un utilisateur déjà connecté n'a pas besoin de ce formulaire
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Message neutre affiché après soumission — null = on n'a pas encore soumis
        $messageSent = false;

        if ($request->isMethod('POST')) {
            // ── Rate limiting sur /mot-de-passe-oublie ─────────────────────────
            // Même pattern que AuthController::register() avec $registerLimiter.
            // Identifiant : l'IP de la requête (getClientIp() gère les proxies
            // grâce à trusted_proxies configuré dans framework.yaml).
            $limiter = $this->passwordResetLimiter->create($request->getClientIp() ?? 'unknown');
            $limit   = $limiter->consume(1);

            if (!$limit->isAccepted()) {
                // Rate limit dépassé : calcul du temps d'attente pour informer l'utilisateur
                $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
                $minutes    = (int) ceil($retryAfter / 60);

                $this->addFlash('error', sprintf(
                    'Trop de tentatives. Veuillez réessayer dans %d minute%s.',
                    $minutes,
                    $minutes > 1 ? 's' : ''
                ));

                // Redirection POST/Redirect/GET pour éviter la resoumission du formulaire
                return $this->redirectToRoute('app_forgot_password');
            }

            // ── Validation du token CSRF ───────────────────────────────────────
            // Intention 'forgot_password' — doit correspondre exactement au template Twig.
            if (!$this->isCsrfTokenValid('forgot_password', $request->request->get('_csrf_token'))) {
                return $this->render('auth/forgot_password.html.twig', [
                    'error'       => 'Token de sécurité invalide. Veuillez recharger la page et réessayer.',
                    'messageSent' => false,
                ]);
            }

            // ── Récupérer et nettoyer l'email saisi ───────────────────────────
            // strtolower : on normalise en minuscules car les emails sont
            // insensibles à la casse. Sans ça, un utilisateur inscrit en minuscules
            // qui taperait "Alice@Exemple.fr" ne serait pas retrouvé (findOneBy est
            // sensible à la casse) et ne recevrait jamais son email de réinitialisation.
            $email = strtolower(trim((string) $request->request->get('email', '')));

            // ── Appeler le service — il gère silencieusement les cas invalides ─
            // requestReset() ne lève jamais d'exception (anti-énumération).
            // Si l'email n'existe pas, il ne fait rien — et l'utilisateur voit
            // le même message que si l'email existe.
            if ($email !== '') {
                $this->passwordResetService->requestReset($email);
            }

            // ── Afficher le message neutre dans TOUS les cas ──────────────────
            // Ce booléen déclenche l'affichage du message dans le template Twig.
            $messageSent = true;
        }

        return $this->render('auth/forgot_password.html.twig', [
            'messageSent' => $messageSent,
            'error'       => null,
        ]);
    }

    // ─── Route 2 : Formulaire de nouveau mot de passe ────────────────────────

    /**
     * Affiche et traite le formulaire de saisie du nouveau mot de passe.
     *
     * GET :
     *   - Valide le token via validateToken().
     *   - Si invalide/expiré → message d'erreur + lien pour redemander.
     *   - Si valide → affiche le formulaire nouveau mot de passe.
     *
     * POST :
     *   - Valide le token CSRF.
     *   - Valide le DTO (force du mdp + correspondance confirmation).
     *   - Appelle resetPassword() → si succès, flash + redirect vers app_login.
     *   - Si token invalide entre GET et POST (race condition ou expiration) → message d'erreur.
     *
     * Sécurité :
     *   - Pas de rate limiter supplémentaire ici : validateToken() ne fait que lire en BDD
     *     (pas d'effet de bord), et la tentative de POST avec un mauvais token échoue
     *     silencieusement (retour false) — aucun risque de brute force utile.
     *
     * @param string $token Token de réinitialisation extrait de l'URL (en clair)
     */
    #[Route('/reinitialiser-mot-de-passe/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, string $token): Response
    {
        // Un utilisateur déjà connecté n'a pas besoin de ce formulaire
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('GET')) {
            // ── Validation du token à l'affichage du formulaire ───────────────
            // Si le token est invalide ou expiré dès le GET, on affiche un message
            // d'erreur avec un lien pour refaire une demande — pas de formulaire vide.
            $user = $this->passwordResetService->validateToken($token);

            if ($user === null) {
                // Token invalide ou expiré — affiche le formulaire avec un flag d'erreur
                return $this->render('auth/reset_password.html.twig', [
                    'tokenValid' => false,
                    'token'      => $token,
                    'error'      => null,
                ]);
            }

            // Token valide → afficher le formulaire de nouveau mot de passe
            return $this->render('auth/reset_password.html.twig', [
                'tokenValid' => true,
                'token'      => $token,
                'error'      => null,
            ]);
        }

        // ── Traitement du POST ────────────────────────────────────────────────

        // ── 1. Validation CSRF ────────────────────────────────────────────────
        // Intention 'reset_password' — doit correspondre au template Twig.
        if (!$this->isCsrfTokenValid('reset_password', $request->request->get('_csrf_token'))) {
            return $this->render('auth/reset_password.html.twig', [
                'tokenValid' => true,
                'token'      => $token,
                'error'      => 'Token de sécurité invalide. Veuillez recharger la page et réessayer.',
            ]);
        }

        // ── 2. Construire et valider le DTO ───────────────────────────────────
        $dto = ResetPasswordDTO::fromArray($request->request->all());

        if ($dto === null) {
            // Champs absents ou vides
            return $this->render('auth/reset_password.html.twig', [
                'tokenValid' => true,
                'token'      => $token,
                'error'      => 'Le mot de passe et sa confirmation sont obligatoires.',
            ]);
        }

        // ── Validation de la politique de mot de passe (CDC §9) ──────────────
        if (!$dto->isPasswordStrong()) {
            return $this->render('auth/reset_password.html.twig', [
                'tokenValid' => true,
                'token'      => $token,
                'error'      => 'Le mot de passe doit contenir au moins 10 caractères, une lettre majuscule et un chiffre.',
            ]);
        }

        // ── Validation de la correspondance des mots de passe ─────────────────
        if (!$dto->doPasswordsMatch()) {
            return $this->render('auth/reset_password.html.twig', [
                'tokenValid' => true,
                'token'      => $token,
                'error'      => 'Les deux mots de passe ne correspondent pas.',
            ]);
        }

        // ── 3. Appliquer le reset via le service ──────────────────────────────
        // resetPassword() valide le token une dernière fois (le token a pu expirer
        // entre le GET et le POST, ou être déjà utilisé dans un autre onglet).
        $success = $this->passwordResetService->resetPassword($token, $dto->password);

        if (!$success) {
            // Token invalide ou expiré au moment du POST (edge case possible)
            return $this->render('auth/reset_password.html.twig', [
                'tokenValid' => false,
                'token'      => $token,
                'error'      => null,
            ]);
        }

        // ── 4. Succès → flash + redirection vers la page de connexion ─────────
        $this->addFlash(
            'success',
            'Votre mot de passe a bien été réinitialisé. Vous pouvez maintenant vous connecter.'
        );

        return $this->redirectToRoute('app_login');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\RegisterDTO;
use App\Service\AuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    /**
     * Injection par autowiring :
     *   - AuthService : logique d'inscription (hachage, vérification doublon email)
     *   - RateLimiterFactory $registerLimiter : fabrique de jetons pour /register
     *     (nommage : "register_limiter" dans framework.yaml → $registerLimiter en PHP)
     *
     * Note : le rate limiting de /login est géré automatiquement par Symfony Security
     * via login_throttling dans security.yaml — aucun code nécessaire ici.
     */
    public function __construct(
        private readonly AuthService $authService,
        private readonly RateLimiterFactory $registerLimiter,
    ) {}

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

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Géré automatiquement par Symfony Security
    }

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
            // L'IP est extraite de la requête (getClientIp() gère les proxies
            // si trusted_proxies est configuré dans framework.yaml).
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
                $error = 'Les champs email et password sont obligatoires.';
            } elseif (!$dto->isEmailValid()) {
                $error = 'Adresse email invalide.';
            } elseif (!$dto->isPasswordStrong()) {
                $error = 'Le mot de passe doit contenir au moins 8 caractères.';
            } else {
                $user = $this->authService->register($dto);

                if ($user === null) {
                    $error = 'Cet email est déjà utilisé.';
                } else {
                    $this->addFlash('success', 'Inscription réussie ! Vous pouvez vous connecter.');
                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('auth/register.html.twig', [
            'error' => $error,
        ]);
    }
}

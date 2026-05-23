<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\RegisterDTO;
use App\Service\AuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthService $authService,
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

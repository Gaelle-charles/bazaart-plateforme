<?php

declare(strict_types=1);

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GoogleController gère les deux étapes du flux OAuth2 Google.
 *
 * Étape 1 — /connect/google :
 *   L'utilisateur clique "Se connecter avec Google".
 *   On le redirige vers Google avec les scopes demandés (email + profil de base).
 *
 * Étape 2 — /connect/google/callback :
 *   Google redirige l'utilisateur ici avec un code d'autorisation.
 *   Le GoogleAuthenticator prend le relais automatiquement (il intercepte cette route).
 *   La méthode callback() ne fait donc rien — tout est géré par l'authenticator.
 */
class GoogleController extends AbstractController
{
    /**
     * Démarre le flux OAuth2 : redirige l'utilisateur vers Google.
     *
     * Les scopes demandés :
     * - 'email'   → pour récupérer l'adresse email de l'utilisateur
     * - 'profile' → pour récupérer le nom et la photo (utilisable plus tard)
     */
    #[Route('/connect/google', name: 'app_google_connect')]
    public function connect(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry
            ->getClient('google')
            ->redirect(['email', 'profile'], []);
    }

    /**
     * Route de retour après l'authentification Google.
     * Le GoogleAuthenticator est déclenché automatiquement sur cette route
     * (via la méthode supports()), donc cette méthode ne sera jamais exécutée
     * en cas de succès. Elle sert de "filet" en cas d'erreur inattendue.
     */
    #[Route('/connect/google/callback', name: 'app_google_callback')]
    public function callback(): Response
    {
        // En théorie jamais atteint (l'authenticator redirige avant).
        // En cas de problème grave, on redirige vers le login.
        return $this->redirectToRoute('app_login');
    }
}

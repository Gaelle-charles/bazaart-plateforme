<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * GoogleAuthenticator gère l'authentification OAuth2 via Google.
 *
 * Fonctionnement :
 * 1. L'utilisateur clique "Se connecter avec Google" → redirigé vers Google
 * 2. Google redirige vers /connect/google/callback avec un code d'autorisation
 * 3. Cet authenticator est déclenché sur cette route (supports())
 * 4. Il échange le code contre un token, récupère l'email Google
 * 5. Si l'email existe en base → connexion. Sinon → création automatique du compte.
 *
 * AuthenticationEntryPointInterface permet à Symfony de savoir où rediriger
 * un utilisateur non connecté qui tente d'accéder à une page protégée.
 */
class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly RouterInterface $router,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * Indique à Symfony que cet authenticator ne doit s'activer QUE sur la route callback.
     * Pour toutes les autres routes, il retourne null (= ne pas intervenir).
     */
    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_google_callback';
    }

    /**
     * Cœur de l'authenticator : récupère le token Google, en déduit l'utilisateur.
     * Retourne un Passport — l'objet Symfony qui représente "qui se connecte".
     */
    public function authenticate(Request $request): Passport
    {
        $client      = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge(
                // L'identifiant unique du badge = le token d'accès Google
                $accessToken->getToken(),
                // Ce callable est appelé par Symfony pour charger l'utilisateur
                function () use ($accessToken, $client) {
                    /** @var GoogleUser $googleUser */
                    $googleUser = $client->fetchUserFromToken($accessToken);

                    $email = $googleUser->getEmail();

                    // Cherche un compte existant avec cet email
                    $user = $this->userRepository->findOneBy(['email' => $email]);

                    if ($user === null) {
                        // Première connexion Google : création automatique du compte.
                        // Le mot de passe est aléatoire et haché — l'utilisateur ne s'en
                        // servira jamais puisqu'il se connecte via Google.
                        $user = new User();
                        $user->setEmail($email);
                        $user->setRoles(['ROLE_USER']);
                        $user->setPassword(
                            $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(20)))
                        );

                        $this->em->persist($user);
                        $this->em->flush();
                    }

                    return $user;
                }
            )
        );
    }

    /**
     * Appelé après une connexion réussie.
     * Les admins sont redirigés vers leur tableau de bord dédié,
     * les utilisateurs normaux vers le dashboard classique.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        // Vérifie si l'utilisateur a le rôle admin pour le rediriger au bon endroit
        if ($user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new RedirectResponse($this->router->generate('app_admin_dashboard'));
        }

        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    /**
     * Appelé si la connexion échoue (token invalide, Google refuse, etc.).
     * On redirige vers la page de login avec un message d'erreur.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add(
            'error',
            'La connexion via Google a échoué. Veuillez réessayer.'
        );

        return new RedirectResponse($this->router->generate('app_login'));
    }

    /**
     * Point d'entrée : si un utilisateur non connecté tente d'accéder à une page protégée,
     * on le redirige vers la page de login (et non directement vers Google).
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }
}

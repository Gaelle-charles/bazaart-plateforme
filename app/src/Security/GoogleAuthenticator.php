<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\MatchingFormSessionService;
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
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
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
        private readonly ClientRegistry               $clientRegistry,
        private readonly RouterInterface              $router,
        private readonly UserRepository               $userRepository,
        private readonly EntityManagerInterface       $em,
        private readonly UserPasswordHasherInterface  $passwordHasher,
        // AuthorizationCheckerInterface permet d'appeler isGranted() depuis
        // onAuthenticationSuccess(). On l'injecte plutôt que de lire getRoles()
        // directement sur l'entité, car isGranted() respecte la hiérarchie des rôles
        // définie dans security.yaml (ROLE_ADMIN hérite de ROLE_STRUCTURE, etc.).
        // Lire $user->getRoles() retournerait seulement les rôles bruts de la BDD,
        // sans expansion de la hiérarchie — ce qui peut mener à des incohérences.
        private readonly AuthorizationCheckerInterface $authChecker,
        // MatchingFormSessionService injecté pour appliquer la même logique de
        // redirection matching que le LoginSuccessHandler (form_login).
        // Google OAuth a son propre onAuthenticationSuccess() — il ne passe PAS
        // par LoginSuccessHandler — donc on doit dupliquer le check ici.
        private readonly MatchingFormSessionService $matchingFormSession,
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
                        // ADR-0029 (Option A) : nouvel inscrit = artiste par défaut,
                        // y compris via Google (cohérent avec l'inscription classique).
                        // ROLE_USER est ajouté automatiquement par User::getRoles().
                        $user->setRoles(['ROLE_ARTIST']);
                        $user->setPassword(
                            $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(20)))
                        );

                        // ── Email déjà vérifié par Google ────────────────────────
                        //
                        // Google garantit que l'email retourné par OAuth appartient
                        // à l'utilisateur et a été validé par Google lui-même.
                        // On marque donc directement isVerified=true pour ne pas
                        // bloquer les utilisateurs Google dans UserChecker::checkPreAuth().
                        //
                        // Sans ce flag, UserChecker bloquerait toute connexion Google
                        // car le compte aurait isVerified=false (valeur par défaut).
                        $user->setIsVerified(true);

                        $this->em->persist($user);
                        $this->em->flush();
                    }

                    return $user;
                }
            )
        );
    }

    /**
     * Appelé après une connexion réussie via Google OAuth.
     *
     * ORDRE DE PRIORITÉ des redirections (identique à LoginSuccessHandler pour form_login) :
     *
     *   1. Données matching en session → home #swipe-section
     *      Si l'utilisateur avait commencé le formulaire matching de la home avant
     *      de cliquer "Se connecter avec Google", ses données de session sont préservées.
     *      On le renvoie sur la section matching pour qu'il retrouve son contexte.
     *
     *   2. Admin → app_admin_dashboard
     *      Les admins ont leur propre espace.
     *
     *   3. Défaut → app_dashboard
     *      Comportement normal pour tout autre utilisateur.
     *
     * NOTE : Google OAuth ne passe PAS par LoginSuccessHandler (ce handler est exclusif
     * à form_login). C'est pourquoi on duplique ici la logique matching.
     * Pour le target path (priorité 1 dans LoginSuccessHandler), il n'est PAS appliqué
     * ici car Google OAuth utilise un flow de redirection externe (le navigateur passe
     * par google.com) — le target path mémorisé avant /login est souvent perdu
     * dans ce flow (redirect vers callback ≠ redirect direct). On simplifie.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // ── PRIORITÉ 1 : Données de matching en session ──────────────────────────
        //
        // On utilise AuthorizationCheckerInterface::isGranted() plutôt que
        // $user->getRoles() pour respecter la hiérarchie des rôles de security.yaml.
        // isGranted() consulte le token courant (déjà peuplé par Symfony Security)
        // et applique la hiérarchie complète — c'est la méthode canonique.
        if ($this->matchingFormSession->hasSessionData($request->getSession())) {
            // NOTE TECHNIQUE : '#swipe-section' est concaténé manuellement car
            // RouterInterface::generate() ne gère pas les fragments d'URL.
            // Les ancres (#) sont des informations purement côté client que le serveur
            // ne reçoit jamais — elles ne font pas partie de l'URL HTTP.
            return new RedirectResponse(
                $this->router->generate('app_home') . '#swipe-section'
            );
        }

        // ── PRIORITÉ 2 : Admin → tableau de bord admin ───────────────────────────
        if ($this->authChecker->isGranted('ROLE_ADMIN')) {
            return new RedirectResponse($this->router->generate('app_admin_dashboard'));
        }

        // ── PRIORITÉ 3 : Défaut → dashboard utilisateur ──────────────────────────
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

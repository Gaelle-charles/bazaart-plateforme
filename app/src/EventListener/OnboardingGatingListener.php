<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * OnboardingGatingListener — Force les nouveaux utilisateurs vers l'onboarding.
 *
 * Ce listener se déclenche sur chaque requête HTTP (KernelEvents::REQUEST).
 * Son rôle est de vérifier si l'utilisateur connecté a complété son onboarding.
 * Si ce n'est pas le cas, il le redirige vers la première étape.
 *
 * POURQUOI un EventListener plutôt que du code dans le controller ?
 *   Un EventListener sur kernel.request s'exécute AVANT que le controller soit
 *   appelé. C'est le bon endroit pour un gating global (comportement transverse)
 *   plutôt que de copier la vérification dans chaque controller.
 *
 * EXEMPTIONS (routes qui ne déclenchent PAS la redirection) :
 *   - Toutes les routes de l'onboarding lui-même (préfixe "app_onboarding_")
 *     → sinon, l'utilisateur ne pourrait pas accéder aux pages d'onboarding !
 *   - La route de déconnexion (app_logout)
 *     → on doit laisser l'utilisateur se déconnecter même s'il n'a pas fini l'onboarding
 *   - Les routes de vérification d'email (app_verify_email, app_check_email, app_resend_verify_email)
 *     → l'email doit pouvoir être confirmé avant / indépendamment de l'onboarding
 *   - Le profiler et les assets Symfony (/_wdt/, /_profiler/, routes internes)
 *     → ces routes ne font pas partie de l'application proprement dite
 *   - Les routes de connexion et d'inscription (app_login, app_register)
 *     → l'utilisateur peut ne pas être encore authentifié sur ces pages
 *   - Les routes structure (préfixe "app_structure_")
 *     → permet à une structure de candidater directement depuis l'étape 1 de l'onboarding
 *       (l'étape 1 redirige vers app_structure_register pour les structures)
 *
 * EXEMPTION ADMINS / MODERATORS / STRUCTURES :
 *   Les administrateurs (ROLE_ADMIN), modérateurs (ROLE_MODERATOR) et comptes
 *   structure (ROLE_STRUCTURE) sont exemptés du gating d'onboarding artiste.
 *   Ces comptes ne suivent pas le parcours artiste. On utilise AuthorizationCheckerInterface
 *   (via isGranted) plutôt que getRoles() pour respecter la hiérarchie de rôles Symfony
 *   et éviter le pattern anti-CLAUDE.md in_array sur getRoles().
 *
 * Convention Symfony 7.x : #[AsEventListener] remplace l'enregistrement dans services.yaml.
 */
// Priorité 7 : INDISPENSABLE de passer APRÈS le firewall de sécurité.
// Le firewall Symfony s'exécute sur kernel.request à la priorité 8 ; c'est lui qui
// authentifie la requête et remplit le TokenStorage. Si ce listener tournait à 8 (ou plus),
// il s'exécuterait AVANT l'authentification → getToken() renverrait null → aucun gating.
// En passant à 7, on garantit que l'utilisateur est déjà authentifié quand on le lit.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
class OnboardingGatingListener
{
    /**
     * Liste des préfixes et noms de routes exemptés du gating.
     * Contient à la fois des noms de routes exactes et des préfixes (détectés via str_starts_with).
     *
     * @var list<string>
     */
    private const WHITELISTED_ROUTES = [
        // Onboarding lui-même — ne pas rediriger sur les pages d'onboarding
        'app_onboarding_',

        // Auth — login, logout, inscription, vérification email
        'app_login',
        'app_logout',
        'app_register',
        'app_check_email',
        'app_verify_email',
        'app_resend_verify_email',

        // Réinitialisation de mot de passe (éviter de bloquer un reset de mdp).
        // 'app_forgot_password'  → formulaire "j'ai oublié mon mot de passe" (/mot-de-passe-oublie)
        // 'app_reset_password'   → formulaire de saisie du nouveau mot de passe (/reinitialiser-mot-de-passe/{token})
        // Ces deux routes sont nécessaires pour que le flux complet puisse se dérouler
        // sans que le gating redirige l'utilisateur vers l'onboarding en cours de route.
        'app_forgot_password',
        'app_reset_password',

        // Routes structure — l'étape 1 de l'onboarding y redirige
        'app_structure_',

        // Debug / profiler Symfony (routes internes commençant par _)
        '_',

        // Assets web (ex: robots.txt, favicon, CSS/JS bundlés)
        'app_static_',
    ];

    public function __construct(
        // TokenStorageInterface donne accès au token de sécurité courant,
        // qui contient l'utilisateur authentifié (ou null si anonyme).
        // On l'utilise plutôt que Security::getUser() pour rester léger dans le listener.
        private readonly TokenStorageInterface        $tokenStorage,
        private readonly RouterInterface              $router,
        // AuthorizationCheckerInterface::isGranted() respecte la hiérarchie des rôles
        // déclarée dans security.yaml (ex. ROLE_ADMIN hérite des autres rôles).
        // C'est l'API recommandée par Symfony — jamais in_array sur getRoles().
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    /**
     * Méthode principale exécutée à chaque requête.
     *
     * LOGIQUE :
     *   1. On ignore les sous-requêtes (ex : ESI, Symfony HttpKernel subrequests)
     *      → isMasterRequest() / isMainRequest() selon la version Symfony
     *   2. On récupère le token de sécurité
     *   3. On ignore si l'utilisateur n'est pas authentifié
     *   4. On ignore si la route est dans la whitelist
     *   5. On ignore les admins
     *   6. Si onboardingCompleted = false → on redirige vers l'onboarding
     */
    public function __invoke(RequestEvent $event): void
    {
        // ── Étape 1 : Ignorer les sous-requêtes ──────────────────────────────
        //
        // Symfony peut déclencher des sous-requêtes internes (ex : rendu de fragment,
        // erreurs 404 sub-rendered, ESI). On ne veut pas gater ces requêtes internes.
        if (!$event->isMainRequest()) {
            return;
        }

        // ── Étape 2 : Récupérer l'utilisateur ────────────────────────────────
        $token = $this->tokenStorage->getToken();

        // Pas de token → utilisateur anonyme → pas de gating à appliquer
        if ($token === null) {
            return;
        }

        $user = $token->getUser();

        // Utilisateur non authentifié (token présent mais pas d'entité User)
        if (!$user instanceof User) {
            return;
        }

        // ── Étape 3 : Vérifier la route courante ─────────────────────────────
        $request   = $event->getRequest();
        $routeName = $request->attributes->get('_route', '');

        // On parcourt la whitelist et on vérifie si le nom de route
        // correspond exactement OU commence par le préfixe.
        // Ex: 'app_onboarding_' matche 'app_onboarding_step1', 'app_onboarding_step2', etc.
        foreach (self::WHITELISTED_ROUTES as $whitelisted) {
            if ($routeName === $whitelisted || str_starts_with($routeName, $whitelisted)) {
                // Route exemptée → on laisse passer sans redirection
                return;
            }
        }

        // ── Étape 4 : Exempter les admins, modérateurs et structures ─────────
        //
        // Ces comptes ne suivent pas le parcours artiste en 4 étapes — ils ne doivent
        // donc jamais être bloqués par le gating onboarding.
        //
        // POURQUOI AuthorizationCheckerInterface plutôt que in_array sur getRoles() ?
        //   isGranted() respecte la hiérarchie de rôles Symfony définie dans security.yaml.
        //   Exemple : si ROLE_ADMIN hérite de ROLE_MODERATOR, un admin sera exempté
        //   via isGranted('ROLE_ADMIN') sans qu'on ait besoin de lister tous les rôles.
        //   C'est la convention Symfony — voir CLAUDE.md section 7.
        if (
            $this->authorizationChecker->isGranted('ROLE_ADMIN')
            || $this->authorizationChecker->isGranted('ROLE_MODERATOR')
            || $this->authorizationChecker->isGranted('ROLE_STRUCTURE')
        ) {
            return;
        }

        // ── Étape 5 : Gating onboarding ──────────────────────────────────────
        //
        // Si onboardingCompleted = false, on redirige vers la première étape.
        // La première étape demande à l'utilisateur s'il est artiste ou structure,
        // ce qui détermine la suite du parcours.
        if (!$user->isOnboardingCompleted()) {
            $response = new RedirectResponse(
                $this->router->generate('app_onboarding_step1')
            );

            // On force la réponse : Symfony interrompra le dispatch du controller
            // et renverra cette redirection HTTP 302 au navigateur.
            $event->setResponse($response);
        }
    }
}

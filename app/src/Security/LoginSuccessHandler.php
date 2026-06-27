<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\MatchingFormSessionService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * LoginSuccessHandler — Gère la redirection après une connexion réussie via le formulaire.
 *
 * Ce handler est déclaré dans security.yaml (firewalls.main.form_login.success_handler)
 * et remplace la logique de redirection par défaut de Symfony (default_target_path).
 *
 * ORDRE DE PRIORITÉ des redirections (du plus prioritaire au moins prioritaire) :
 *
 *   1. TARGET PATH STANDARD (Symfony)
 *      → Si l'utilisateur essayait d'accéder à une page protégée AVANT de se connecter,
 *        Symfony a mémorisé cette URL dans la session ("target path").
 *        Exemple : l'utilisateur tente d'aller sur /dashboard → redirigé vers /login
 *        → se connecte → doit atterrir sur /dashboard (pas sur la home matching).
 *        C'est le comportement standard qu'on NE veut PAS casser.
 *
 *   2. DONNÉES MATCHING EN SESSION
 *      → Si la session contient des données du formulaire matching de la home
 *        (l'utilisateur a commencé à remplir le formulaire AVANT de se connecter),
 *        on le renvoie sur la home section #swipe-section.
 *        Là, le profil sera soit complet (→ swipe), soit incomplet (→ formulaire pré-rempli).
 *
 *   3. RÔLE ADMIN
 *      → Les admins sont redirigés vers leur tableau de bord dédié.
 *
 *   4. DÉFAUT : DASHBOARD
 *      → Tout autre utilisateur atterrit sur le dashboard classique.
 *
 * CONVENTION SYMFONY :
 *   AuthenticationSuccessHandlerInterface est l'interface standard pour personnaliser
 *   la redirection post-login dans le firewall form_login.
 *   TargetPathTrait apporte la méthode getTargetPath() qui lit la session.
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    // TargetPathTrait fournit getTargetPath($session, $firewallName) et
    // removeTargetPath($session, $firewallName) — méthodes officielles Symfony
    // pour lire/écrire l'URL cible mémorisée avant une redirection vers /login.
    use TargetPathTrait;

    public function __construct(
        // RouterInterface génère les URLs à partir des noms de routes Symfony.
        // Plus fiable que des URLs hardcodées : fonctionne quel que soit le contexte.
        private readonly RouterInterface $router,

        // AuthorizationCheckerInterface permet d'appeler isGranted() depuis ce service.
        // On N'utilise PAS $token->getUser()->getRoles() directement car cela
        // retournerait les rôles bruts de la BDD sans expansion de la hiérarchie.
        // Exemple : un admin a ['ROLE_ADMIN'] en BDD, mais isGranted('ROLE_STRUCTURE')
        // doit retourner true grâce à la hiérarchie security.yaml.
        private readonly AuthorizationCheckerInterface $authChecker,

        // MatchingFormSessionService encapsule toute la logique de lecture de session
        // pour le formulaire matching. On n'accède JAMAIS directement à la clé de session.
        private readonly MatchingFormSessionService $matchingFormSession,
    ) {}

    // Nom du firewall (security.yaml → firewalls.main). On le code en dur car
    // AuthenticationSuccessHandlerInterface::onAuthenticationSuccess() ne reçoit
    // PAS le nom du firewall (contrairement à AuthenticatorInterface). getTargetPath()
    // en a besoin pour lire la bonne clé de session ('_security.main.target_path').
    private const FIREWALL_NAME = 'main';

    /**
     * Appelé automatiquement par Symfony Security après une authentification réussie
     * via form_login (formulaire email/mot de passe).
     *
     * NB : Google OAuth a sa propre méthode onAuthenticationSuccess() dans GoogleAuthenticator.
     * Ce handler ne concerne que le form_login natif.
     *
     * IMPORTANT : la signature DOIT correspondre EXACTEMENT à l'interface
     * AuthenticationSuccessHandlerInterface (2 paramètres : Request + TokenInterface).
     * Symfony n'appelle ce handler qu'avec ces 2 arguments. Un 3e paramètre
     * obligatoire provoquerait une ArgumentCountError au login (cassé).
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): ?Response
    {
        // ── PRIORITÉ 1 : Target path standard ────────────────────────────────────
        //
        // Avant que l'utilisateur arrive sur /login, Symfony Security peut avoir
        // mémorisé l'URL qu'il tentait d'atteindre (ex: /dashboard, /admin).
        // Cette URL est stockée dans la session sous la clé interne de TargetPathTrait.
        //
        // CAS TYPIQUE : l'utilisateur clique sur un lien vers /mon-profil (page protégée),
        // il n'est pas connecté → firewall le redirige vers /login en sauvant l'URL.
        // Après connexion, il doit retourner sur /mon-profil, pas sur la home.
        //
        // On respecte ce comportement standard car c'est une attente UX fondamentale.
        // La logique matching passe APRÈS ce cas.
        $targetPath = $this->getTargetPath($request->getSession(), self::FIREWALL_NAME);

        if ($targetPath !== null && $targetPath !== '') {
            // getTargetPath() supprime automatiquement la clé de session après lecture.
            // On retourne directement la redirection sans passer par les règles suivantes.
            return new RedirectResponse($targetPath);
        }

        // ── PRIORITÉ 2 : Données de matching en session ──────────────────────────
        //
        // Si la session contient des réponses du formulaire matching de la home
        // (clé 'bazaart_matching_form' non vide), c'est que l'utilisateur a commencé
        // le formulaire multi-étapes AVANT de se connecter.
        //
        // On le renvoie sur la home, ancre #swipe-section.
        //   → Profil complet (display_name + location + disciplines + lookingFor remplis) :
        //     la section affichera les cartes de swipe directement.
        //   → Profil incomplet : la section affichera le formulaire pré-rempli avec
        //     les données de session (carryover).
        //
        // NOTE TECHNIQUE : on concatène '#swipe-section' manuellement car generate()
        // ne prend pas en charge les fragments d'URL (l'ancre # est côté client,
        // pas côté serveur — les serveurs HTTP ne la reçoivent jamais).
        if ($this->matchingFormSession->hasSessionData($request->getSession())) {
            return new RedirectResponse(
                $this->router->generate('app_home') . '#swipe-section'
            );
        }

        // ── PRIORITÉ 3 : Admin → tableau de bord admin ───────────────────────────
        //
        // isGranted() consulte le token déjà peuplé par Symfony Security
        // et applique la hiérarchie des rôles (ROLE_ADMIN hérite de tout).
        if ($this->authChecker->isGranted('ROLE_ADMIN')) {
            return new RedirectResponse($this->router->generate('app_admin_dashboard'));
        }

        // ── PRIORITÉ 4 : Défaut → dashboard utilisateur ──────────────────────────
        //
        // Cas normal : utilisateur classique, pas de matching en cours, pas admin.
        // Le dashboard demandera à l'utilisateur de compléter son profil si besoin.
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }
}

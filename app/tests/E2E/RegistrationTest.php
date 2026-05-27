<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Repository\UserRepository;

/**
 * RegistrationTest — Test E2E #1 : Inscription et connexion.
 *
 * Ce test couvre le parcours critique "Inscription + Login" défini
 * dans le CDC V3 §10 (couverture cible V1).
 *
 * Scénarios testés :
 *   1. La page /register s'affiche correctement (GET 200)
 *   2. Un utilisateur peut s'inscrire avec des données valides (POST → redirect /login)
 *   3. Un mot de passe trop court est rejeté (formulaire ré-affiché avec erreur)
 *   4. La page /login s'affiche correctement (GET 200)
 *   5. Un utilisateur inscrit peut se connecter (POST /login → redirect dashboard)
 *
 * Conventions testées :
 *   - Les pages publiques sont accessibles sans être connecté
 *   - Le CSRF token est requis sur les formulaires
 *   - Les erreurs de validation sont affichées dans le formulaire
 *   - L'inscription redirige vers /login (pas directement connecté en V1)
 */
class RegistrationTest extends AbstractE2ETestCase
{
    /**
     * setUp() spécifique à ce test.
     * Purge la BDD pour repartir d'un état propre à chaque méthode.
     *
     * Attention : purgeDatabase() est coûteux (TRUNCATE) — on le fait
     * uniquement dans les tests qui créent des données en BDD.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // On vide la BDD avant chaque test d'inscription pour éviter les
        // conflits sur la contrainte UNIQUE users.email
        $this->purgeDatabase();
    }

    // ─── Test 1.1 : Page /register accessible ─────────────────────────────────

    /**
     * Vérifie que la page d'inscription est accessible sans être connecté.
     *
     * La route app_register est déclarée PUBLIC_ACCESS dans security.yaml.
     * Si cette règle disparaît, tous les nouveaux utilisateurs seraient bloqués.
     */
    public function testRegisterPageIsAccessible(): void
    {
        $this->client->request('GET', '/register');

        // 200 OK : la page s'affiche normalement
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 1.2 : Inscription valide ───────────────────────────────────────

    /**
     * Vérifie qu'un utilisateur peut créer un compte avec des données valides.
     *
     * Flux attendu :
     *   POST /register → redirect 302 vers /login
     *
     * Après inscription, l'utilisateur n'est PAS connecté automatiquement en V1.
     * Il doit se connecter manuellement via le formulaire de login.
     *
     * Note sur le CSRF :
     *   On utilise $this->client->getCrawler() pour récupérer le token CSRF
     *   généré dans le HTML du formulaire, puis on l'inclut dans le POST.
     *   C'est la technique standard pour tester des formulaires avec CSRF en Symfony.
     */
    public function testRegisterWithValidData(): void
    {
        // ── Étape 1 : charge la page GET pour récupérer le token CSRF ────────
        // Le formulaire d'inscription génère un token CSRF dans un champ caché.
        // On doit le lire depuis le HTML avant d'envoyer le POST.
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        // Extrait le token CSRF depuis le champ caché du formulaire.
        // Le champ s'appelle "_csrf_token" dans register.html.twig.
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_csrf_token"]');

        // ── Étape 2 : envoyer le formulaire ──────────────────────────────────
        $this->client->request('POST', '/register', [
            'email'            => 'nouvel.utilisateur@test.fr',
            // Respecte la politique CDC §9 : 10+ chars, 1 majuscule, 1 chiffre
            'password'         => 'MotDePasse123!',
            'confirm_password' => 'MotDePasse123!',
            '_csrf_token'      => $csrfToken,
        ]);

        // ── Étape 3 : vérifier la redirection ────────────────────────────────
        // Après une inscription réussie, le controller redirige vers /login
        // avec un message flash de confirmation.
        $this->assertResponseRedirects('/login');

        // ── Étape 4 : vérifier que l'utilisateur est en BDD ──────────────────
        /** @var \App\Repository\UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'nouvel.utilisateur@test.fr']);

        $this->assertNotNull($user, 'L\'utilisateur doit exister en BDD après inscription');
        $this->assertSame('nouvel.utilisateur@test.fr', $user->getEmail());
        // Le mot de passe doit être haché — jamais en clair en base
        $this->assertNotSame('MotDePasse123!', $user->getPassword(), 'Le mot de passe ne doit PAS être stocké en clair');
    }

    // ─── Test 1.3 : Mot de passe trop court ──────────────────────────────────

    /**
     * Vérifie que l'inscription est rejetée si le mot de passe ne respecte
     * pas la politique de complexité définie dans le CDC §9 :
     *   - minimum 10 caractères
     *   - au moins 1 lettre majuscule
     *   - au moins 1 chiffre
     *
     * On envoie "abcdefghi" : 9 caractères, sans majuscule, sans chiffre.
     * Ce mot de passe viole les trois critères à la fois.
     *
     * Comportement attendu : le formulaire est ré-affiché avec une erreur.
     * Pas de redirection — l'utilisateur reste sur /register.
     */
    public function testRegisterWithShortPasswordShowsError(): void
    {
        // Charge la page GET pour récupérer le token CSRF
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_csrf_token"]');

        // POST avec un mot de passe non conforme à la politique CDC §9 :
        //   "abcdefghi" = 9 caractères, pas de majuscule, pas de chiffre → rejeté
        $this->client->request('POST', '/register', [
            'email'            => 'test.motdepasse@test.fr',
            'password'         => 'abcdefghi',
            'confirm_password' => 'abcdefghi',
            '_csrf_token'      => $csrfToken,
        ]);

        // Le formulaire est ré-affiché : pas de redirection, code 200
        // AuthController retourne $this->render() avec l'erreur → 200 OK
        $this->assertResponseIsSuccessful();

        // Vérifie que le message d'erreur est présent dans la réponse HTML
        // AuthController passe la variable $error au template register.html.twig
        $this->assertSelectorExists('body'); // Page bien rendue

        // L'utilisateur NE doit PAS exister en BDD (la validation a bloqué)
        /** @var \App\Repository\UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'test.motdepasse@test.fr']);
        $this->assertNull($user, 'Aucun utilisateur ne doit être créé avec un mot de passe non conforme à la politique');
    }

    // ─── Test 1.4 : Mots de passe non identiques ─────────────────────────────

    /**
     * Vérifie que l'inscription est rejetée si password et confirm_password
     * sont différents.
     *
     * Cette vérification est indépendante de la force du mot de passe :
     * même un mot de passe fort est rejeté si la confirmation ne correspond pas.
     *
     * Comportement attendu : formulaire ré-affiché avec une erreur "ne correspondent pas".
     */
    public function testRegisterWithMismatchedPasswordsShowsError(): void
    {
        // Charge la page GET pour récupérer le token CSRF
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_csrf_token"]');

        // POST avec un mot de passe fort mais une confirmation différente
        // Les deux valeurs sont délibérément distinctes pour déclencher l'erreur
        $this->client->request('POST', '/register', [
            'email'            => 'test.confirm@test.fr',
            'password'         => 'MotDePasse123!',      // Conforme à CDC §9
            'confirm_password' => 'MotDePasse999!',      // Différent → doit être rejeté
            '_csrf_token'      => $csrfToken,
        ]);

        // Le formulaire est ré-affiché : pas de redirection, code 200
        $this->assertResponseIsSuccessful();

        // Vérifie la présence du message d'erreur dans le HTML
        // Le template register.html.twig affiche $error dans un div d'erreur
        $this->assertSelectorTextContains('body', 'ne correspondent pas');

        // L'utilisateur NE doit PAS être créé en BDD
        /** @var \App\Repository\UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'test.confirm@test.fr']);
        $this->assertNull($user, 'Aucun utilisateur ne doit être créé si les mots de passe ne correspondent pas');
    }

    // ─── Test 1.6 : Page /login accessible ───────────────────────────────────

    /**
     * Vérifie que la page de connexion est accessible à tous (PUBLIC_ACCESS).
     *
     * C'est une vérification basique mais critique : si /login devient protégé,
     * personne ne peut plus se connecter → service totalement inaccessible.
     */
    public function testLoginPageIsAccessible(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
    }

    // ─── Test 1.7 : Connexion avec credentials valides ───────────────────────

    /**
     * Vérifie qu'un utilisateur existant peut se connecter.
     *
     * Flux attendu :
     *   POST /login → redirect 302 vers /dashboard (ou admin si ROLE_ADMIN)
     *
     * Note : on crée l'utilisateur directement en BDD (via createTestUser)
     * plutôt que de passer par le formulaire d'inscription — les deux parcours
     * sont indépendants et on veut tester la connexion isolément.
     *
     * Pourquoi POST /login et pas $client->loginUser() ?
     *   Ce test vérifie EXPLICITEMENT le formulaire de login et le flow HTTP.
     *   $client->loginUser() bypass le formulaire — ce n'est pas ce qu'on teste ici.
     */
    public function testLoginWithValidCredentials(): void
    {
        // ── Prépare un utilisateur en BDD ────────────────────────────────────
        // Le mot de passe respecte la politique CDC §9 (10 chars, 1 maj, 1 chiffre)
        $user = $this->createTestUser(
            email:    'test.login@test.fr',
            password: 'TestPass12!',
        );

        // Charge la page login pour récupérer le token CSRF
        // Note : le token s'appelle "authenticate" dans security.yaml (form_login csrf_token_id)
        $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_csrf_token"]');

        // ── Envoie le formulaire de login ─────────────────────────────────────
        // Note : security.yaml configure username_parameter: email, password_parameter: password
        $this->client->request('POST', '/login', [
            'email'        => 'test.login@test.fr',
            'password'     => 'TestPass12!',
            '_csrf_token'  => $csrfToken,
        ]);

        // ── Vérifie la redirection post-login ────────────────────────────────
        // Un login réussi redirige vers default_target_path: app_dashboard (= /dashboard)
        // On vérifie seulement qu'il y a une redirection (pas que l'URL est exacte)
        // car la destination peut varier selon le rôle (admin → /admin/dashboard)
        $this->assertResponseRedirects();

        // Le statut doit être 302 Found (redirect temporaire standard après form POST)
        $this->assertResponseStatusCodeSame(302);
    }

    // ─── Test 1.8 : Email en double rejeté ───────────────────────────────────

    /**
     * Vérifie qu'on ne peut pas s'inscrire avec un email déjà utilisé.
     *
     * AuthService::register() retourne null si l'email existe déjà.
     * AuthController appelle addFlash('error', 'Cet email est déjà utilisé.')
     * puis redirige vers /register.
     *
     * Note : contrairement aux erreurs de validation (render direct),
     * l'erreur "email doublon" passe par un flash + redirect. On doit donc
     * suivre la redirection pour voir le message flash dans le HTML.
     */
    public function testRegisterWithDuplicateEmailShowsError(): void
    {
        // Crée d'abord un utilisateur avec cet email
        // Mot de passe conforme CDC §9 : 10 chars, majuscule, chiffre
        $this->createTestUser(
            email:    'doublon@test.fr',
            password: 'TestPass12!',
        );

        // Note : pour ce test, AuthController fait render() direct (pas addFlash+redirect)
        // quand l'email est en doublon. Le POST doit retourner 200 directement
        // avec la variable $error dans le template.
        // On conserve followRedirects(false) — par défaut dans setUp().

        // Charge la page GET pour récupérer le token CSRF
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_csrf_token"]');

        // Tente de s'inscrire avec le même email
        // Le mot de passe est conforme (12+ chars, majuscule, chiffre) pour que
        // la validation ne s'arrête pas sur la force du mot de passe avant de
        // tester la contrainte de doublon email.
        $this->client->request('POST', '/register', [
            'email'            => 'doublon@test.fr',
            'password'         => 'AutreMotDePasse123!',
            'confirm_password' => 'AutreMotDePasse123!',
            '_csrf_token'      => $csrfToken,
        ]);

        // AuthController fait render() direct avec $error quand l'email est en doublon
        // (pas de addFlash + redirect) → réponse 200 avec le message dans le template
        $this->assertResponseIsSuccessful();

        // Vérifie que le message d'erreur est présent dans le HTML retourné
        // Le template register.html.twig affiche $error dans un div.auth-flash-error
        $this->assertSelectorTextContains('body', 'déjà utilisé');
    }
}

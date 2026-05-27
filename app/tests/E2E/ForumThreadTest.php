<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ForumCategory;
use App\Repository\ForumThreadRepository;

/**
 * ForumThreadTest — Test E2E #3 : Forum communautaire.
 *
 * Ce test couvre le parcours "Créer un thread forum" du CDC V3 §10.
 *
 * Scénarios testés :
 *   1. La page /forum est accessible pour un utilisateur connecté (200)
 *   2. La page /forum/{slug} (catégorie) est accessible (200)
 *   3. Un utilisateur connecté peut créer un thread → redirect vers le thread
 *   4. Un utilisateur non connecté est redirigé vers /login sur POST
 *
 * Architecture :
 *   ForumController est protégé par #[IsGranted('ROLE_USER')] au niveau de la classe
 *   → toutes les routes /forum/* nécessitent une authentification.
 *
 * Notes sur les slugs :
 *   ForumService::createThread() génère le slug à partir du titre.
 *   ForumCategory a un slug défini lors de la création.
 */
class ForumThreadTest extends AbstractE2ETestCase
{
    /**
     * Catégorie du forum créée pour les tests.
     */
    private ?ForumCategory $category = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Purge pour repartir d'un état propre
        $this->purgeDatabase();

        // Crée une catégorie de forum de test
        $this->category = new ForumCategory();
        $this->category
            ->setName('Général')
            ->setSlug('general')
            ->setDescription('Catégorie de test pour les tests E2E')
            ->setOrderPosition(0)
            ->setIsActive(true);

        $this->em->persist($this->category);
        $this->em->flush();
    }

    // ─── Test 3.1 : Page /forum accessible ───────────────────────────────────

    /**
     * Vérifie que la page d'accueil du forum s'affiche pour un utilisateur connecté.
     *
     * ForumController::index() charge toutes les catégories actives.
     * Ici on a 1 catégorie active "Général" créée dans setUp().
     */
    public function testForumIndexIsAccessibleForAuthenticatedUser(): void
    {
        $user = $this->createRegularUser();
        $this->loginAs($user);

        $this->client->request('GET', '/forum');

        // 200 OK : la liste des catégories s'affiche
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 3.2 : Page /forum/{slug} (catégorie) accessible ───────────────

    /**
     * Vérifie qu'on peut accéder à la page d'une catégorie du forum.
     *
     * ForumController::category() charge les threads de la catégorie.
     * La catégorie "general" a été créée dans setUp().
     */
    public function testForumCategoryPageIsAccessible(): void
    {
        $user = $this->createRegularUser();
        $this->loginAs($user);

        $this->client->request('GET', '/forum/general');

        // 200 OK : la liste des threads (vide pour l'instant) s'affiche
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 3.3 : 404 si catégorie inconnue ────────────────────────────────

    /**
     * Vérifie qu'une catégorie inexistante renvoie 404.
     * Ce test valide la gestion d'erreur du controller.
     */
    public function testForumUnknownCategoryReturns404(): void
    {
        $user = $this->createRegularUser();
        $this->loginAs($user);

        $this->client->request('GET', '/forum/categorie-qui-nexiste-pas');

        $this->assertResponseStatusCodeSame(404);
    }

    // ─── Test 3.4 : Créer un thread → redirect vers le thread ───────────────

    /**
     * Vérifie qu'un utilisateur connecté peut créer un nouveau thread.
     *
     * Flux attendu :
     *   GET /forum/general/nouveau → formulaire
     *   POST /forum/general/nouveau → redirect 302 vers /forum/general/{thread-slug}
     *
     * ForumService::createThread() :
     *   - Valide le titre et le corps
     *   - Génère le slug depuis le titre
     *   - Persiste le ForumThread
     *
     * On vérifie en BDD que le thread a bien été créé.
     */
    public function testCreateThreadRedirectsToNewThread(): void
    {
        $user = $this->createRegularUser('auteur.thread@test.fr');
        $this->loginAs($user);

        // ── Étape 1 : charge la page du formulaire et récupère le token CSRF ────
        //
        // Note de routage : la route /{categorySlug}/{threadSlug} avait une contrainte
        // manquante — elle capturait GET /forum/general/nouveau (en interprétant "nouveau"
        // comme un threadSlug). Ce bug a été corrigé dans ForumController en ajoutant
        // requirements: ['threadSlug' => '(?!nouveau$).+'] qui exclut le mot "nouveau".
        //
        // Maintenant GET /forum/general/nouveau accède correctement au formulaire
        // de création de thread (route app_forum_new_thread).
        $this->client->request('GET', '/forum/general/nouveau');
        $this->assertResponseIsSuccessful();

        // Extrait le token CSRF depuis le champ caché "_token" du formulaire.
        // Défini dans forum/new_thread.html.twig : csrf_token('forum_new_thread')
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_token"]');

        // ── Étape 2 : crée le thread ──────────────────────────────────────────
        // Note : le template new_thread.html.twig utilise name="content" (pas "body")
        //        pour le champ texte principal — correspond à $data['content'] dans ForumService.
        $this->client->request('POST', '/forum/general/nouveau', [
            'title'   => 'Mon premier sujet de test E2E',
            'content' => 'Contenu du thread de test E2E. Ce message est assez long pour '
                       . 'passer la validation minimale (minimum 10 caractères requis).',
            '_token'  => $csrfToken,
        ]);

        // ── Étape 3 : vérifie la redirection vers le thread créé ─────────────
        // Après création, le controller redirige vers app_forum_thread
        // URL : /forum/general/{slug-du-thread}
        $this->assertResponseRedirects();
        $this->assertResponseStatusCodeSame(302);

        // Récupère l'URL de la redirection pour vérifier qu'elle pointe vers /forum/general/
        $redirectUrl = $this->client->getResponse()->headers->get('Location') ?? '';
        $this->assertStringContainsString('/forum/general/', $redirectUrl,
            'La redirection doit pointer vers le thread dans la catégorie "general"'
        );

        // Note : on ne suit PAS la redirection ici car cela nécessite que le thread
        // soit chargeable par le repository dans le même contexte de requête.
        // La vérification en BDD ci-dessous suffit à confirmer la création.

        // ── Étape 4 : vérifie la présence en BDD ─────────────────────────────
        /** @var ForumThreadRepository $repo */
        $repo = static::getContainer()->get(ForumThreadRepository::class);

        $thread = $repo->findOneBy(['title' => 'Mon premier sujet de test E2E']);
        $this->assertNotNull($thread, 'Le thread doit avoir été créé en BDD');
        $this->assertSame('Mon premier sujet de test E2E', $thread->getTitle());

        // Vérifie que l'auteur est bien l'utilisateur connecté
        $this->assertSame($user->getId(), $thread->getAuthor()->getId());
    }

    // ─── Test 3.5 : Non connecté → redirect /login sur POST ─────────────────

    /**
     * Vérifie qu'un utilisateur non authentifié ne peut pas créer un thread.
     *
     * ForumController est protégé par #[IsGranted('ROLE_USER')] au niveau de la classe.
     * → une requête sans session → redirect vers /login.
     */
    public function testCreateThreadWithoutAuthenticationRedirectsToLogin(): void
    {
        // POST sans être connecté
        $this->client->request('POST', '/forum/general/nouveau', [
            'title'  => 'Thread non autorisé',
            'body'   => 'Ce thread ne doit pas être créé.',
            '_token' => 'token_invalide',
        ]);

        // Doit rediriger vers le login
        $this->assertResponseRedirects();
        $this->assertResponseStatusCodeSame(302);

        // Suit la redirection et vérifie qu'on est sur la page de login
        $this->client->followRedirect();
        $this->assertRouteSame('app_login');
    }
}

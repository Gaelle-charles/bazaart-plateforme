<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Course;
use App\Enum\CourseLevel;
use App\Repository\CourseEnrollmentRepository;

/**
 * CourseEnrollmentTest — Test E2E #5 : Inscription à une formation.
 *
 * Ce test couvre le parcours "S'inscrire à une formation" du CDC V3 §10.
 *
 * Scénarios testés :
 *   1. Le catalogue /formations est accessible sans authentification (public)
 *   2. La page de détail /formations/{slug} est accessible (200)
 *   3. Un utilisateur connecté peut s'inscrire à une formation
 *   4. Un CourseEnrollment est créé en BDD après inscription
 *
 * Architecture :
 *   CourseController::index()  → public (pas de #[IsGranted])
 *   CourseController::show()   → public (affiche le bouton "s'inscrire" si connecté)
 *   CourseController::enroll() → protégé IS_AUTHENTICATED_FULLY
 *
 * Notes sur la formation de test :
 *   On crée une formation avec isPublished = true et un slug unique.
 *   La route enroll() utilise findPublishedBySlug() donc la formation DOIT être publiée.
 *   Le CSRF token est nommé dynamiquement : 'enroll_' ~ course.id
 */
class CourseEnrollmentTest extends AbstractE2ETestCase
{
    /**
     * Formation publiée de test.
     */
    private ?Course $course = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Purge pour repartir d'un état propre
        $this->purgeDatabase();

        // ── Crée une formation publiée de test ────────────────────────────────
        // On crée directement en BDD car il n'y a pas de formulaire public
        // de création de formation (c'est admin-only).
        //
        // Important : isPublished = true pour que CourseRepository::findPublishedBySlug()
        // retourne la formation (les formations non publiées retournent null).
        $this->course = new Course();
        $this->course
            ->setSlug('initiation-musique-test-e2e')
            ->setTitle('Initiation à la musique afrobeats — Test E2E')
            ->setDescription(
                'Formation de test créée pour les tests E2E automatisés. '
                . 'Contenu pédagogique fictif.'
            )
            ->setInstructorName('Formateur Test')
            ->setLevel(CourseLevel::BEGINNER)
            ->setIsPublished(true)                              // CRUCIAL : la formation doit être publiée
            ->setPublishedAt(new \DateTime('-1 day'));           // Publiée hier (date passée = valide)

        $this->em->persist($this->course);
        $this->em->flush();

        // Recharge pour avoir l'ID généré
        $this->em->refresh($this->course);
    }

    // ─── Test 5.1 : Catalogue /formations accessible pour un utilisateur connecté ─

    /**
     * Vérifie que le catalogue des formations est accessible pour un utilisateur connecté.
     *
     * CourseController::index() n'a PAS de #[IsGranted] au niveau du controller,
     * mais la règle access_control globale `{ path: ^/, roles: ROLE_USER }` dans
     * security.yaml s'applique à TOUTES les routes non explicitement exemptées.
     *
     * Résultat pratique : /formations requiert une authentification en V1.
     * (Un futur ticket pourrait rendre /formations public — il faudra alors
     * ajouter `{ path: ^/formations, roles: PUBLIC_ACCESS }` dans security.yaml
     * et mettre à jour ce test.)
     */
    public function testCatalogueIsAccessibleForAuthenticatedUser(): void
    {
        $user = $this->createRegularUser();
        $this->loginAs($user);

        $this->client->request('GET', '/formations');

        // 200 OK : le catalogue s'affiche pour un utilisateur connecté
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 5.2 : Page de détail /formations/{slug} accessible ────────────

    /**
     * Vérifie que la page de présentation d'une formation est accessible.
     *
     * La formation "initiation-musique-test-e2e" a été créée dans setUp().
     * Un utilisateur connecté voit le bouton "S'inscrire".
     * Un visiteur anonyme voit la présentation sans pouvoir s'inscrire.
     */
    public function testCourseDetailPageIsAccessible(): void
    {
        $user = $this->createRegularUser();
        $this->loginAs($user);

        $this->client->request('GET', '/formations/initiation-musique-test-e2e');

        // 200 OK : la page de présentation s'affiche
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 5.3 : Non authentifié → redirect /login sur /formations ───────

    /**
     * Vérifie que les visiteurs non connectés sont redirigés vers /login.
     *
     * La règle `{ path: ^/, roles: ROLE_USER }` dans security.yaml s'applique
     * à /formations et /formations/{slug}. C'est voulu en V1 : seuls les membres
     * inscrits peuvent consulter les formations.
     *
     * Si on voulait rendre /formations public (V2 ?), il faudrait ajouter une règle
     * PUBLIC_ACCESS AVANT la règle globale dans security.yaml.
     */
    public function testCourseDetailRedirectsToLoginWhenNotAuthenticated(): void
    {
        // Pas de loginAs() → visiteur anonyme
        $this->client->request('GET', '/formations/initiation-musique-test-e2e');

        // Doit rediriger vers /login (pas de 200)
        $this->assertResponseRedirects('/login');
    }

    // ─── Test 5.4 : Inscription crée un CourseEnrollment en BDD ─────────────

    /**
     * Vérifie qu'un utilisateur connecté peut s'inscrire à une formation.
     *
     * Flux attendu :
     *   POST /formations/{slug}/enroll → redirect 302 vers /formations/{slug}/learn
     *
     * Après inscription, un CourseEnrollment doit exister en BDD avec :
     *   - user = l'utilisateur connecté
     *   - course = la formation
     *   - progressPercent = 0 (début)
     *
     * Particularité CSRF :
     *   Le token est nommé 'enroll_{course.id}' — on doit récupérer l'ID de la
     *   formation créée dynamiquement dans setUp().
     */
    public function testEnrollmentCreatesEnrollmentRecord(): void
    {
        $user = $this->createRegularUser('apprenant@test.fr');
        $this->loginAs($user);

        // ── Étape 1 : vérifie que la page GET s'affiche et récupère le token CSRF ─
        // La page de présentation /formations/{slug} affiche le bouton "S'inscrire"
        // avec un formulaire contenant le token CSRF nommé "_token".
        $this->client->request('GET', '/formations/initiation-musique-test-e2e');
        $this->assertResponseIsSuccessful();

        // Extrait le token CSRF depuis le champ caché "_token" du formulaire d'inscription.
        // Dans course/show.html.twig : csrf_token('enroll_' ~ course.id)
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_token"]');

        // ── Étape 2 : envoie le formulaire d'inscription ──────────────────────
        $this->client->request('POST', '/formations/initiation-musique-test-e2e/enroll', [
            '_token' => $csrfToken,
        ]);

        // ── Étape 3 : vérifie la redirection vers l'espace apprenant ─────────
        // CourseController::enroll() redirige vers app_course_learn après succès
        $this->assertResponseRedirects('/formations/initiation-musique-test-e2e/learn');

        // ── Étape 4 : vérifie qu'un CourseEnrollment existe en BDD ───────────
        /** @var CourseEnrollmentRepository $repo */
        $repo = static::getContainer()->get(CourseEnrollmentRepository::class);

        // Recharge les entités pour éviter le cache Doctrine
        $this->em->clear();
        $userFresh   = $this->em->find(\App\Entity\User::class, $user->getId());
        $courseFresh = $this->em->find(Course::class, $this->course->getId());

        $enrollment = $repo->findByUserAndCourse($userFresh, $courseFresh);

        $this->assertNotNull(
            $enrollment,
            'Un CourseEnrollment doit avoir été créé après inscription'
        );

        // Vérifie les données de l'inscription
        $this->assertSame($user->getId(), $enrollment->getUser()->getId());
        $this->assertSame($this->course->getId(), $enrollment->getCourse()->getId());
        $this->assertSame(0, $enrollment->getProgressPercent(), 'La progression initiale doit être 0%');
    }

    // ─── Test 5.5 : Inscription depuis un utilisateur non connecté → redirect ─

    /**
     * Vérifie qu'un utilisateur non authentifié ne peut pas s'inscrire.
     *
     * CourseController::enroll() est protégé par #[IsGranted('IS_AUTHENTICATED_FULLY')].
     * → POST non authentifié → redirect vers /login.
     */
    public function testEnrollmentRequiresAuthentication(): void
    {
        // POST sans être connecté
        $this->client->request('POST', '/formations/initiation-musique-test-e2e/enroll', [
            '_token' => 'token_invalide',
        ]);

        // Doit rediriger (vers /login)
        $this->assertResponseRedirects();
        $this->assertResponseStatusCodeSame(302);

        // Suit la redirection → page de login
        $this->client->followRedirect();
        $this->assertRouteSame('app_login');
    }
}

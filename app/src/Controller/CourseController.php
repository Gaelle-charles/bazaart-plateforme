<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Course;
use App\Entity\Lesson;
use App\Repository\CourseRepository;
use App\Repository\LessonProgressRepository;
use App\Service\CourseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CourseController — Parcours public des formations.
 *
 * Ce controller gère toutes les routes accessibles aux utilisateurs :
 *   - /formations          → catalogue (toutes les formations publiées)
 *   - /formations/{slug}   → page de vente d'une formation
 *   - /formations/{slug}/enroll          → inscription (POST)
 *   - /formations/{slug}/learn           → espace apprenant (vue d'ensemble)
 *   - /formations/{slug}/learn/{id}      → lecteur de leçon
 *   - /formations/{slug}/learn/{id}/progress → mise à jour progression (AJAX)
 *
 * Convention Symfony : le préfixe de route est déclaré sur la classe avec
 * #[Route('/formations', name: 'app_course_')]. Chaque méthode complète
 * le nom de route avec son suffixe (ex: 'index' → 'app_course_index').
 *
 * Principe thin controller : la logique métier est dans CourseService.
 * Ce controller ne fait que :
 *   1. Récupérer les données via les repositories
 *   2. Déléguer les opérations à CourseService
 *   3. Retourner la bonne réponse HTTP (render, redirect, JsonResponse)
 */
#[Route('/formations', name: 'app_course_')]
class CourseController extends AbstractController
{
    /**
     * Injection par constructeur (pattern préféré en Symfony 7.x).
     * readonly = la propriété ne peut pas être réassignée après l'injection.
     */
    public function __construct(
        private readonly CourseService            $courseService,
        private readonly CourseRepository         $courseRepository,
        private readonly LessonProgressRepository $progressRepository,
        private readonly EntityManagerInterface   $em,
    ) {}

    // ─── Catalogue public ─────────────────────────────────────────────────────

    /**
     * GET /formations — Catalogue de toutes les formations publiées.
     *
     * Accessible sans authentification (formations visibles par tous).
     * Seules les formations isPublished = true sont affichées.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // findAllPublished() retourne Course[] triés par publishedAt DESC
        $courses = $this->courseRepository->findAllPublished();

        return $this->render('course/index.html.twig', [
            'courses'      => $courses,
            // totalCourses pour l'en-tête "N formations disponibles"
            'totalCourses' => count($courses),
        ]);
    }

    // ─── Page de vente ────────────────────────────────────────────────────────

    /**
     * GET /formations/{slug} — Page de présentation d'une formation.
     *
     * Accessible sans authentification.
     * Les admins peuvent voir les formations non publiées (pour prévisualisation).
     * Les visiteurs et apprenants ne voient que les formations publiées.
     */
    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $slug): Response
    {
        // ── Récupération de la formation ─────────────────────────────────────
        // Si l'utilisateur est admin → chercher par slug (publiée ou non)
        // Sinon → chercher uniquement les publiées (404 si brouillon)
        if ($this->isGranted('ROLE_ADMIN')) {
            // findOneBy est la méthode magique de ServiceEntityRepository
            $course = $this->courseRepository->findOneBy(['slug' => $slug]);
        } else {
            // findPublishedBySlug retourne null si non publiée
            $course = $this->courseRepository->findPublishedBySlug($slug);
        }

        // 404 si la formation n'existe pas (ou n'est pas publiée pour les non-admins)
        if ($course === null) {
            throw $this->createNotFoundException(
                sprintf('La formation "%s" est introuvable ou non publiée.', $slug)
            );
        }

        // ── Vérifier si l'utilisateur est inscrit ────────────────────────────
        $enrollment = null;
        $isEnrolled = false;

        if ($this->getUser() !== null) {
            // getUser() retourne ?UserInterface, on caste en User pour le service
            /** @var \App\Entity\User $user */
            $user       = $this->getUser();
            $enrollment = $this->courseService->findEnrollment($course, $user);
            $isEnrolled = ($enrollment !== null);
        }

        return $this->render('course/show.html.twig', [
            'course'     => $course,
            'isEnrolled' => $isEnrolled,
            'enrollment' => $enrollment,
        ]);
    }

    // ─── Inscription ─────────────────────────────────────────────────────────

    /**
     * POST /formations/{slug}/enroll — Inscription à une formation.
     *
     * Requiert d'être authentifié (IS_AUTHENTICATED_FULLY).
     * Vérifie le token CSRF pour protéger contre les requêtes forgées.
     *
     * Flux :
     *   1. Vérifier le token CSRF
     *   2. Récupérer la formation publiée
     *   3. Appeler CourseService::enrollUser()
     *   4. Rediriger vers l'espace apprenant avec flash "success"
     *
     * En cas d'erreur (doublon, token invalide) : flash "error" + redirect show.
     */
    #[Route('/{slug}/enroll', name: 'enroll', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function enroll(string $slug, Request $request): Response
    {
        // ── Récupération de la formation ─────────────────────────────────────
        $course = $this->courseRepository->findPublishedBySlug($slug);
        if ($course === null) {
            throw $this->createNotFoundException('Formation introuvable.');
        }

        // ── Validation du token CSRF ──────────────────────────────────────────
        // Le token est généré dans show.html.twig : csrf_token('enroll_' ~ course.id)
        // Cette protection évite qu'un site tiers puisse inscrire l'utilisateur
        // à son insu via une requête forgée (CSRF attack).
        $tokenId = 'enroll_' . $course->getId();
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_course_show', ['slug' => $slug]);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        try {
            // Délégation au service — toute la logique est là
            $this->courseService->enrollUser($course, $user);
            $this->addFlash('success', 'Inscription confirmée ! Bonne formation.');
        } catch (\LogicException $e) {
            // CourseService lève LogicException si l'utilisateur est déjà inscrit
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_course_show', ['slug' => $slug]);
        }

        // Redirection vers l'espace apprenant après inscription réussie
        return $this->redirectToRoute('app_course_learn', ['slug' => $slug]);
    }

    // ─── Espace apprenant ─────────────────────────────────────────────────────

    /**
     * GET /formations/{slug}/learn — Tableau de bord de l'apprenant.
     *
     * Affiche le plan de la formation avec l'état de progression par leçon.
     * Accessible uniquement aux inscrits et aux admins.
     *
     * Données passées à la vue :
     *   - course      : la formation
     *   - enrollment  : l'inscription (pour progressPercent, completedAt)
     *   - modules     : CourseModule[] avec leçons chargées (via collection Doctrine)
     *   - progresses  : LessonProgress[] indexés par lesson_id pour lookup O(1) en Twig
     */
    #[Route('/{slug}/learn', name: 'learn', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function learn(string $slug): Response
    {
        // ── Récupération formation ────────────────────────────────────────────
        $course = $this->courseRepository->findOneBy(['slug' => $slug]);
        if ($course === null) {
            throw $this->createNotFoundException('Formation introuvable.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // ── Vérification de l'accès ───────────────────────────────────────────
        // L'admin peut toujours accéder pour prévisualiser
        $enrollment = $this->courseService->findEnrollment($course, $user);

        if ($enrollment === null && !$this->isGranted('ROLE_ADMIN')) {
            // L'utilisateur n'est pas inscrit et n'est pas admin → 403
            throw $this->createAccessDeniedException(
                'Vous devez vous inscrire à cette formation pour accéder au contenu.'
            );
        }

        // ── Progressions indexées par lesson_id ───────────────────────────────
        // On charge toutes les progressions de l'enrollment en une seule requête
        // et on les indexe par lesson_id pour un accès O(1) dans le template Twig.
        // Sans cela, Twig ferait N requêtes (N+1 problem).
        $progressesByLessonId = [];
        if ($enrollment !== null) {
            $progressList = $this->progressRepository->findByEnrollmentWithLesson($enrollment);
            foreach ($progressList as $progress) {
                // lesson_id est la clé → accès direct dans Twig : progresses[lesson.id]
                $progressesByLessonId[$progress->getLesson()->getId()] = $progress;
            }
        }

        return $this->render('course/learn.html.twig', [
            'course'     => $course,
            'enrollment' => $enrollment,
            // getModules() retourne la collection triée par orderPosition (via @ORM\OrderBy)
            'modules'    => $course->getModules(),
            'progresses' => $progressesByLessonId,
        ]);
    }

    // ─── Lecteur de leçon ─────────────────────────────────────────────────────

    /**
     * GET /formations/{slug}/learn/{lessonId} — Lecteur de leçon.
     *
     * Affiche la vidéo d'une leçon avec la navigation prev/next.
     * Accès autorisé si :
     *   - L'utilisateur est inscrit à la formation, OU
     *   - La leçon est isFreePreview = true (teaser gratuit), OU
     *   - L'utilisateur est ROLE_ADMIN
     *
     * @param int $lessonId L'ID de la leçon (paramètre de route)
     */
    #[Route('/{slug}/learn/{lessonId<\d+>}', name: 'lesson', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function lesson(string $slug, int $lessonId): Response
    {
        // ── Récupération de la formation ─────────────────────────────────────
        $course = $this->courseRepository->findOneBy(['slug' => $slug]);
        if ($course === null) {
            throw $this->createNotFoundException('Formation introuvable.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // ── Récupération de l'enrollment ─────────────────────────────────────
        $enrollment = $this->courseService->findEnrollment($course, $user);

        // ── Récupération de la leçon + vérification appartenance ─────────────
        // On cherche la leçon dans les modules du cours (pas directement en BDD)
        // pour vérifier qu'elle appartient bien à CETTE formation.
        $lesson     = null;
        $allLessons = []; // liste ordonnée de toutes les leçons du cours (pour prev/next)

        foreach ($course->getModules() as $module) {
            foreach ($module->getLessons() as $l) {
                $allLessons[] = $l;
                if ($l->getId() === $lessonId) {
                    $lesson = $l;
                }
            }
        }

        // La leçon n'existe pas OU n'appartient pas à ce cours → 404
        if ($lesson === null) {
            throw $this->createNotFoundException('Leçon introuvable dans cette formation.');
        }

        // ── Vérification des droits d'accès ──────────────────────────────────
        // Accès refusé (403) si :
        //   - Pas admin
        //   - Pas inscrit
        //   - La leçon n'est pas en preview gratuit
        $isAdmin        = $this->isGranted('ROLE_ADMIN');
        $isEnrolled     = ($enrollment !== null);
        $isFreePreview  = $lesson->isFreePreview();

        if (!$isAdmin && !$isEnrolled && !$isFreePreview) {
            throw $this->createAccessDeniedException(
                'Accès réservé aux apprenants inscrits à cette formation.'
            );
        }

        // ── Navigation prev / next ────────────────────────────────────────────
        // On trouve la position de la leçon courante dans la liste ordonnée
        $currentIndex = array_search($lesson, $allLessons, strict: true);
        $prevLesson   = ($currentIndex > 0) ? $allLessons[$currentIndex - 1] : null;
        $nextLesson   = ($currentIndex < count($allLessons) - 1) ? $allLessons[$currentIndex + 1] : null;

        // ── Progression de la leçon courante ─────────────────────────────────
        $lessonProgress = null;
        if ($enrollment !== null) {
            $lessonProgress = $this->progressRepository->findByEnrollmentAndLesson(
                $enrollment,
                $lesson,
            );
        }

        return $this->render('course/lesson.html.twig', [
            'course'         => $course,
            'lesson'         => $lesson,
            'enrollment'     => $enrollment,
            'lessonProgress' => $lessonProgress,
            'prevLesson'     => $prevLesson,
            'nextLesson'     => $nextLesson,
        ]);
    }

    // ─── Progression AJAX ─────────────────────────────────────────────────────

    /**
     * POST /formations/{slug}/learn/{lessonId}/progress — Mise à jour de progression.
     *
     * Route AJAX appelée périodiquement par le lecteur de leçon.
     * Corps JSON attendu : { "position": int, "completed": bool }
     *
     * Retourne JSON : { "success": true, "progressPercent": int }
     *
     * Cette route est uniquement accessible aux utilisateurs authentifiés et inscrits.
     * Les admins non inscrits ne peuvent pas envoyer de progression (pas d'enrollment).
     *
     * @param int $lessonId L'ID de la leçon (paramètre de route)
     */
    #[Route('/{slug}/learn/{lessonId<\d+>}/progress', name: 'lesson_progress', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function lessonProgress(string $slug, int $lessonId, Request $request): JsonResponse
    {
        // ── Récupération formation + inscription ──────────────────────────────
        $course = $this->courseRepository->findOneBy(['slug' => $slug]);
        if ($course === null) {
            return new JsonResponse(['success' => false, 'error' => 'Formation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Entity\User $user */
        $user       = $this->getUser();
        $enrollment = $this->courseService->findEnrollment($course, $user);

        if ($enrollment === null) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Vous n\'êtes pas inscrit à cette formation.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        // ── Récupération de la leçon ─────────────────────────────────────────
        $lesson = null;
        foreach ($course->getModules() as $module) {
            foreach ($module->getLessons() as $l) {
                if ($l->getId() === $lessonId) {
                    $lesson = $l;
                    break 2; // sort des deux foreach dès que la leçon est trouvée
                }
            }
        }

        if ($lesson === null) {
            return new JsonResponse(['success' => false, 'error' => 'Leçon introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // ── Lecture du corps JSON ─────────────────────────────────────────────
        // getContent() retourne le corps brut de la requête (string JSON)
        $data      = json_decode($request->getContent(), associative: true) ?? [];
        // Valeur défensive : si "position" est absent ou invalide → 0
        $position  = isset($data['position']) && is_int($data['position'])
            ? max(0, $data['position'])
            : 0;
        // "completed" doit être un booléen JSON true/false
        $completed = isset($data['completed']) && $data['completed'] === true;

        // ── Délégation au service ─────────────────────────────────────────────
        try {
            $this->courseService->updateLessonProgress(
                enrollment:         $enrollment,
                lesson:             $lesson,
                lastPositionSeconds: $position,
                markCompleted:      $completed,
            );
        } catch (\Exception $e) {
            // Erreur inattendue → réponse 500 avec message générique
            return new JsonResponse(
                ['success' => false, 'error' => 'Erreur lors de la mise à jour.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // Rechargement de l'enrollment pour avoir progressPercent à jour
        $this->em->refresh($enrollment);

        return new JsonResponse([
            'success'         => true,
            'progressPercent' => $enrollment->getProgressPercent(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Course;
use App\Entity\CourseModule;
use App\Entity\Lesson;
use App\Enum\CourseLevel;
use App\Repository\CourseModuleRepository;
use App\Repository\CourseRepository;
use App\Service\CourseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminCourseController — Interface d'administration des formations.
 *
 * Réservé aux utilisateurs ayant le rôle ROLE_ADMIN.
 * Préfixe de route : /admin/formations (name: 'app_admin_course_')
 *
 * Ce controller gère :
 *   - La liste de toutes les formations (publiées + brouillons)
 *   - La création d'une nouvelle formation
 *   - L'édition d'une formation existante (infos + modules + leçons)
 *   - L'ajout de modules et de leçons via des formulaires inline
 *   - La publication d'une formation
 *
 * IMPORTANT : #[IsGranted] est déclaré sur la CLASSE entière.
 * Cela signifie que toutes les routes de ce controller exigent ROLE_ADMIN.
 * Symfony vérifie le rôle avant même d'entrer dans la méthode.
 * C'est la convention préférable à des check manuels dans chaque action.
 */
#[Route('/admin/formations', name: 'app_admin_course_')]
#[IsGranted('ROLE_ADMIN')]
class AdminCourseController extends AbstractController
{
    public function __construct(
        private readonly CourseService         $courseService,
        private readonly CourseRepository      $courseRepository,
        private readonly CourseModuleRepository $moduleRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    // ─── Liste admin de toutes les formations ─────────────────────────────────

    /**
     * GET /admin/formations — Liste toutes les formations (publiées + brouillons).
     *
     * Triées par createdAt DESC pour voir les plus récentes en premier.
     * Les deux statuts (publié / brouillon) sont affichés avec un badge distinctif.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // findAllWithModulesAndLessons() charge formations + modules + leçons
        // en une seule requête SQL (FETCH JOIN) au lieu du problème N+1 que
        // causait l'ancien findBy() (1 requête + N lazy-loads par module).
        // Sans ça, le template Twig qui itère course.modules puis module.lessons
        // déclenchait des dizaines de requêtes supplémentaires.
        $courses = $this->courseRepository->findAllWithModulesAndLessons();

        return $this->render('admin/course/index.html.twig', [
            'courses' => $courses,
        ]);
    }

    // ─── Création d'une nouvelle formation ────────────────────────────────────

    /**
     * GET|POST /admin/formations/new — Formulaire de création d'une formation.
     *
     * GET  → affichage du formulaire vide
     * POST → validation + création + redirection vers l'édition
     *
     * On n'utilise pas FormType de Symfony ici (surcharge pour V1).
     * Les champs sont validés manuellement (titre obligatoire, level valide).
     * Pour V2, migrer vers un CourseFormType pour une meilleure maintenabilité.
     *
     * Token CSRF : 'new_course' — généré dans le template, vérifié ici.
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // ── Traitement du formulaire soumis ──────────────────────────────────
        if ($request->isMethod('POST')) {

            // ── Validation CSRF ───────────────────────────────────────────────
            if (!$this->isCsrfTokenValid('new_course', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide.');
                return $this->redirectToRoute('app_admin_course_new');
            }

            // ── Validation du titre ───────────────────────────────────────────
            $title = trim($request->request->getString('title'));
            if ($title === '') {
                $this->addFlash('error', 'Le titre de la formation est obligatoire.');
                return $this->redirectToRoute('app_admin_course_new');
            }

            // ── Validation du niveau ──────────────────────────────────────────
            $levelValue = $request->request->getString('level', CourseLevel::BEGINNER->value);
            // tryFrom() retourne null si la valeur n'est pas dans l'enum
            $level = CourseLevel::tryFrom($levelValue) ?? CourseLevel::BEGINNER;

            // ── Création de l'entité Formation ────────────────────────────────
            $course = new Course();
            $course->setTitle($title);
            $course->setLevel($level);

            // Champs optionnels — on nettoie avec trim() et on ignore les vides
            $subtitle = trim($request->request->getString('subtitle'));
            if ($subtitle !== '') {
                $course->setSubtitle($subtitle);
            }

            $instructorName = trim($request->request->getString('instructorName'));
            if ($instructorName !== '') {
                $course->setInstructorName($instructorName);
            }

            $trailerVideoUrl = trim($request->request->getString('trailerVideoUrl'));
            if ($trailerVideoUrl !== '') {
                // ── Validation du domaine vidéo (sécurité BLOCANTE) ───────────
                // On vérifie que l'URL provient d'un hébergeur autorisé avant
                // de la stocker. Sans cette vérification, n'importe quelle URL
                // serait injectée dans src="" d'une iframe (risque XSS/phishing).
                if (!$this->courseService->isAllowedVideoUrl($trailerVideoUrl)) {
                    $this->addFlash('danger', sprintf(
                        'URL vidéo non autorisée : seuls YouTube, Vimeo et Bunny Stream sont acceptés. URL reçue : "%s".',
                        $trailerVideoUrl
                    ));
                    return $this->redirectToRoute('app_admin_course_new');
                }
                $course->setTrailerVideoUrl($trailerVideoUrl);
            }

            $description = trim($request->request->getString('description'));
            if ($description !== '') {
                $course->setDescription($description);
            }

            // ── Génération du slug unique ─────────────────────────────────────
            // CourseService::generateSlug() gère la translittération et l'unicité
            $slug = $this->courseService->generateSlug($title);
            $course->setSlug($slug);

            // ── Persistance en base ───────────────────────────────────────────
            $this->em->persist($course);
            $this->em->flush();

            $this->addFlash('success', sprintf('Formation "%s" créée. Ajoutez maintenant les modules et leçons.', $title));

            // Redirection vers l'édition pour ajouter les modules immédiatement
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $course->getId()]);
        }

        // ── Affichage du formulaire vide (GET) ────────────────────────────────
        return $this->render('admin/course/new.html.twig', [
            // On passe les niveaux disponibles pour le <select>
            'levels' => CourseLevel::cases(),
        ]);
    }

    // ─── Édition d'une formation ─────────────────────────────────────────────

    /**
     * GET /admin/formations/{id}/edit — Édition d'une formation.
     *
     * Affiche les infos de la formation, ses modules et ses leçons.
     * Les formulaires d'ajout de module et de leçon sont des POST séparés
     * (app_admin_course_module_add et app_admin_course_lesson_add).
     */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        // findOneBy(['id' => $id]) ou on peut utiliser find($id)
        $course = $this->courseRepository->find($id);
        if ($course === null) {
            throw $this->createNotFoundException(sprintf('Formation #%d introuvable.', $id));
        }

        return $this->render('admin/course/edit.html.twig', [
            'course'  => $course,
            // getModules() retourne la collection triée par orderPosition
            'modules' => $course->getModules(),
            'levels'  => CourseLevel::cases(),
        ]);
    }

    /**
     * POST /admin/formations/{id}/update — Mise à jour des infos d'une formation.
     *
     * Route séparée de GET edit pour respecter le pattern POST-Redirect-Get.
     * Appelée par le formulaire de modification dans edit.html.twig.
     * Token CSRF : 'update_course_{id}'
     */
    #[Route('/{id}/update', name: 'update', methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $course = $this->courseRepository->find($id);
        if ($course === null) {
            throw $this->createNotFoundException(sprintf('Formation #%d introuvable.', $id));
        }

        // ── Validation CSRF ───────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('update_course_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
        }

        // ── Validation du titre ───────────────────────────────────────────────
        $title = trim($request->request->getString('title'));
        if ($title === '') {
            $this->addFlash('error', 'Le titre est obligatoire.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
        }

        // ── Mise à jour des champs ────────────────────────────────────────────
        // On capture l'ANCIEN titre AVANT de mettre à jour l'entité,
        // car getTitle() après setTitle() retournerait déjà la nouvelle valeur
        // et la comparaison serait toujours fausse.
        $oldTitle = $course->getTitle();
        $course->setTitle($title);

        // Si le titre a changé, on régénère le slug (en excluant la formation courante).
        // $excludeId = $course->getId() garantit qu'on ne compare pas le slug
        // de cette formation avec lui-même (slug toujours "disponible" pour elle-même).
        if ($title !== $oldTitle) {
            $course->setSlug($this->courseService->generateSlug($title, $course->getId()));
        }

        $levelValue = $request->request->getString('level', CourseLevel::BEGINNER->value);
        $course->setLevel(CourseLevel::tryFrom($levelValue) ?? CourseLevel::BEGINNER);

        $course->setSubtitle(
            trim($request->request->getString('subtitle')) ?: null
        );
        $course->setInstructorName(
            trim($request->request->getString('instructorName')) ?: null
        );

        // ── Validation du domaine vidéo avant mise à jour (sécurité BLOCANTE) ──
        // Même protection que dans new() : on refuse toute URL hors liste blanche.
        // Si le champ est vide, on efface la valeur existante (null = pas de trailer).
        $trailerVideoUrl = trim($request->request->getString('trailerVideoUrl')) ?: null;
        if ($trailerVideoUrl !== null && !$this->courseService->isAllowedVideoUrl($trailerVideoUrl)) {
            $this->addFlash('danger', sprintf(
                'URL vidéo non autorisée : seuls YouTube, Vimeo et Bunny Stream sont acceptés. URL reçue : "%s".',
                $trailerVideoUrl
            ));
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
        }
        $course->setTrailerVideoUrl($trailerVideoUrl);

        $course->setDescription(
            trim($request->request->getString('description')) ?: null
        );

        // Pas besoin de persist() car l'entité est déjà managée par Doctrine
        // (elle a été chargée via le repository → Doctrine la suit automatiquement)
        $this->em->flush();

        $this->addFlash('success', 'Formation mise à jour.');
        return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
    }

    // ─── Ajout de module ─────────────────────────────────────────────────────

    /**
     * POST /admin/formations/{id}/modules — Ajouter un module à une formation.
     *
     * orderPosition = max(positions existantes) + 1 pour ajouter à la fin.
     * Token CSRF : 'add_module_{course.id}'
     */
    #[Route('/{id}/modules', name: 'module_add', methods: ['POST'])]
    public function moduleAdd(int $id, Request $request): Response
    {
        $course = $this->courseRepository->find($id);
        if ($course === null) {
            throw $this->createNotFoundException(sprintf('Formation #%d introuvable.', $id));
        }

        // ── Validation CSRF ───────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('add_module_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
        }

        // ── Validation du titre de module ─────────────────────────────────────
        $title = trim($request->request->getString('moduleTitle'));
        if ($title === '') {
            $this->addFlash('error', 'Le titre du module est obligatoire.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
        }

        // ── Calcul de la position ─────────────────────────────────────────────
        // On ajoute le nouveau module à la fin (max position + 1)
        $maxPosition = 0;
        foreach ($course->getModules() as $existingModule) {
            if ($existingModule->getOrderPosition() > $maxPosition) {
                $maxPosition = $existingModule->getOrderPosition();
            }
        }

        // ── Création du module ────────────────────────────────────────────────
        $module = new CourseModule();
        $module->setTitle($title);
        $module->setOrderPosition($maxPosition + 1);

        // addModule() synchronise aussi $module->setCourse($course)
        // (voir Course::addModule())
        $course->addModule($module);

        // persist() n'est pas nécessaire sur le module car Course::addModule()
        // ajoute à la collection, et cascade: ['persist'] sur Course->modules
        // propagera le persist au moment du flush.
        // Cependant, on le fait explicitement pour plus de clarté :
        $this->em->persist($module);
        $this->em->flush();

        $this->addFlash('success', sprintf('Module "%s" ajouté.', $title));
        return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
    }

    // ─── Ajout de leçon ──────────────────────────────────────────────────────

    /**
     * POST /admin/formations/{id}/modules/{moduleId}/lessons — Ajouter une leçon.
     *
     * Crée une leçon dans un module spécifique.
     * Option B : champ videoUrl (URL iframe embed directe).
     * Token CSRF : 'add_lesson_{moduleId}'
     */
    #[Route('/{id}/modules/{moduleId}/lessons', name: 'lesson_add', methods: ['POST'])]
    public function lessonAdd(int $id, int $moduleId, Request $request): Response
    {
        // ── Récupération de la formation ─────────────────────────────────────
        $course = $this->courseRepository->find($id);
        if ($course === null) {
            throw $this->createNotFoundException(sprintf('Formation #%d introuvable.', $id));
        }

        // ── Récupération du module (qui doit appartenir à la formation) ───────
        $module = $this->moduleRepository->find($moduleId);
        if ($module === null || $module->getCourse()->getId() !== $id) {
            throw $this->createNotFoundException(
                sprintf('Module #%d introuvable dans la formation #%d.', $moduleId, $id)
            );
        }

        // ── Validation CSRF ───────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('add_lesson_' . $moduleId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
        }

        // ── Validation du titre ───────────────────────────────────────────────
        $title = trim($request->request->getString('lessonTitle'));
        if ($title === '') {
            $this->addFlash('error', 'Le titre de la leçon est obligatoire.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
        }

        // ── Calcul de la position dans le module ──────────────────────────────
        $maxPosition = 0;
        foreach ($module->getLessons() as $existingLesson) {
            if ($existingLesson->getOrderPosition() > $maxPosition) {
                $maxPosition = $existingLesson->getOrderPosition();
            }
        }

        // ── Création de la leçon ──────────────────────────────────────────────
        $lesson = new Lesson();
        $lesson->setTitle($title);
        $lesson->setOrderPosition($maxPosition + 1);

        // Option B : URL iframe directe (YouTube, Vimeo, Bunny Stream)
        // ── Validation du domaine vidéo (sécurité BLOCANTE) ──────────────────
        // Même logique que pour trailerVideoUrl : on refuse les URL hors
        // liste blanche avant tout setVideoUrl() pour ne pas injecter
        // un src="" arbitraire dans l'iframe de la leçon.
        $videoUrl = trim($request->request->getString('videoUrl'));
        if ($videoUrl !== '') {
            if (!$this->courseService->isAllowedVideoUrl($videoUrl)) {
                $this->addFlash('danger', sprintf(
                    'URL vidéo non autorisée : seuls YouTube, Vimeo et Bunny Stream sont acceptés. URL reçue : "%s".',
                    $videoUrl
                ));
                return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
            }
            $lesson->setVideoUrl($videoUrl);
        }

        // Durée en secondes (optionnelle — peut être renseignée plus tard)
        $durationSeconds = $request->request->getInt('durationSeconds', 0);
        if ($durationSeconds > 0) {
            $lesson->setDurationSeconds($durationSeconds);
        }

        // isFreePreview — case à cocher dans le formulaire
        // getInt() retourne 0 si absent, '1' si coché
        $isFreePreview = $request->request->getBoolean('isFreePreview', false);
        $lesson->setIsFreePreview($isFreePreview);

        // addLesson() synchronise $lesson->setModule($module) automatiquement
        $module->addLesson($lesson);
        $this->em->persist($lesson);
        $this->em->flush();

        $this->addFlash('success', sprintf('Leçon "%s" ajoutée.', $title));
        return $this->redirectToRoute('app_admin_course_edit', ['id' => $id]);
    }

    // ─── Publication d'une formation ──────────────────────────────────────────

    /**
     * POST /admin/formations/{id}/publish — Publier une formation.
     *
     * Met isPublished = true et publishedAt = now().
     * Pas de dépublication en V1 (action irréversible simple).
     * Token CSRF : 'publish_course_{id}'
     *
     * En V2, ajouter une validation préalable :
     *   - Au moins 1 module avec 1 leçon
     *   - Titre + description renseignés
     */
    #[Route('/{id}/publish', name: 'publish', methods: ['POST'])]
    public function publish(int $id, Request $request): Response
    {
        $course = $this->courseRepository->find($id);
        if ($course === null) {
            throw $this->createNotFoundException(sprintf('Formation #%d introuvable.', $id));
        }

        // ── Validation CSRF ───────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('publish_course_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_index');
        }

        // ── Déjà publiée → idempotent ──────────────────────────────────────
        if ($course->isPublished()) {
            $this->addFlash('info', 'Cette formation est déjà publiée.');
            return $this->redirectToRoute('app_admin_course_index');
        }

        // ── Publication ───────────────────────────────────────────────────────
        $course->setIsPublished(true);
        $course->setPublishedAt(new \DateTime());

        // Pas de persist() nécessaire (entité managée par Doctrine)
        $this->em->flush();

        $this->addFlash('success', sprintf('Formation "%s" publiée.', $course->getTitle()));
        return $this->redirectToRoute('app_admin_course_index');
    }
}

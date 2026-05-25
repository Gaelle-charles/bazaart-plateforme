<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Course;
use App\Entity\CourseEnrollment;
use App\Entity\Lesson;
use App\Entity\LessonProgress;
use App\Entity\User;
use App\Repository\CourseEnrollmentRepository;
use App\Repository\CourseRepository;
use App\Repository\LessonProgressRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CourseService — Logique métier du module Formation.
 *
 * Ce service centralise toutes les opérations "intelligentes" du module :
 *   - Génération de slug URL-safe avec garantie d'unicité
 *   - Inscription d'un utilisateur à une formation
 *   - Mise à jour de la progression sur une leçon
 *   - Recalcul du pourcentage global d'un enrollment
 *   - Validation des URL vidéo (domaines autorisés uniquement)
 *
 * Principe fondamental : les controllers restent "fins" (thin controllers).
 * Toute décision métier passe ici. Les controllers se contentent d'appeler
 * le service et de gérer la réponse HTTP (redirections, flash messages, JSON).
 *
 * Injection de dépendances :
 *   - EntityManagerInterface : pour persist() + flush() (écriture en BDD)
 *   - CourseRepository : pour vérifier l'unicité des slugs
 *   - CourseEnrollmentRepository : pour rechercher des inscriptions existantes
 *   - LessonProgressRepository : pour trouver/créer les progressions par leçon
 */
class CourseService
{
    /**
     * Liste des domaines d'hébergement vidéo autorisés pour les iframes.
     *
     * Seuls ces hôtes peuvent être utilisés dans videoUrl (leçon) et
     * trailerVideoUrl (formation). Toute autre URL est rejetée par
     * isAllowedVideoUrl() pour éviter l'injection de src iframe arbitraire.
     *
     * Domaines :
     *   - YouTube         : youtube.com / www.youtube.com
     *   - Vimeo (embed)   : player.vimeo.com
     *   - Bunny Stream    : iframe.mediadelivery.net
     */
    private const ALLOWED_VIDEO_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'player.vimeo.com',
        'iframe.mediadelivery.net',
    ];

    public function __construct(
        // L'EntityManager est le point d'entrée pour toutes les opérations Doctrine
        private readonly EntityManagerInterface $em,
        // Repository des formations (vérification unicité slug)
        private readonly CourseRepository $courseRepository,
        // Repository des inscriptions (check doublon avant enroll)
        private readonly CourseEnrollmentRepository $enrollmentRepository,
        // Repository des progressions par leçon
        private readonly LessonProgressRepository $progressRepository,
    ) {}

    // ─── Validation des URL vidéo ─────────────────────────────────────────────

    /**
     * Vérifie qu'une URL vidéo provient d'un domaine autorisé.
     *
     * Pourquoi cette validation est critique :
     *   Sans elle, n'importe quelle URL soumise dans le formulaire admin
     *   serait injectée directement dans src="" d'une iframe. Un attaquant
     *   ayant accès au compte admin pourrait pointer vers un site malveillant.
     *   La liste blanche de domaines (ALLOWED_VIDEO_HOSTS) limite le risque.
     *
     * Mécanisme :
     *   - parse_url() extrait le composant PHP_URL_HOST depuis l'URL brute.
     *   - On vérifie que l'hôte figure dans ALLOWED_VIDEO_HOSTS.
     *   - Retourne false si l'URL est malformée (pas de composant host).
     *
     * Utilisé par AdminCourseController avant tout setVideoUrl() /
     * setTrailerVideoUrl() — en cas d'échec, le controller ajoute
     * un flash 'danger' et retourne au formulaire sans persister.
     *
     * @param string $url URL brute soumise par le formulaire
     * @return bool        true = domaine autorisé, false = refusé
     */
    public function isAllowedVideoUrl(string $url): bool
    {
        // parse_url retourne false si l'URL est totalement invalide,
        // ou une chaîne vide/'null' si le composant host est absent.
        $host = parse_url($url, PHP_URL_HOST);

        // On refuse si l'hôte est vide, null ou pas une chaîne
        if (!is_string($host) || $host === '') {
            return false;
        }

        // Comparaison stricte contre la liste des hôtes autorisés
        return in_array($host, self::ALLOWED_VIDEO_HOSTS, strict: true);
    }

    // ─── Génération de slug ───────────────────────────────────────────────────

    /**
     * Génère un slug URL-safe depuis le titre d'une formation.
     *
     * Étapes de transformation :
     *   1. Translittération des caractères accentués (é→e, ç→c, ü→u...)
     *      via iconv avec //TRANSLIT
     *   2. Passage en minuscules
     *   3. Remplacement de tous les caractères non-alphanumérique par des tirets
     *   4. Suppression des tirets en début/fin (trim)
     *   5. Suppression des tirets multiples consécutifs
     *
     * Garantie d'unicité :
     *   Si le slug généré existe déjà en base (pour une autre formation),
     *   on suffixe avec -2, -3... jusqu'à trouver un slug libre.
     *   $excludeId permet d'exclure la formation courante lors d'une édition
     *   (sinon son propre slug serait détecté comme "déjà utilisé").
     *
     * Exemple : "Initiation à l'Afrobeats : rythme & percussion"
     *           → "initiation-a-l-afrobeats-rythme-percussion"
     *
     * @param string   $title     Le titre brut de la formation
     * @param int|null $excludeId L'ID de la formation en cours d'édition (null = création)
     */
    public function generateSlug(string $title, ?int $excludeId = null): string
    {
        // ── Étape 1 : translittération ASCII ────────────────────────────────
        // iconv transforme "é" → "e", "ç" → "c", "œ" → "oe", etc.
        // L'option //TRANSLIT indique à iconv d'approcher les caractères non-ASCII
        // par leur équivalent ASCII le plus proche (au lieu de les supprimer).
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);

        // Si iconv échoue (faux sur certains systèmes), repli sur le titre brut
        if ($slug === false) {
            $slug = $title;
        }

        // ── Étape 2 : minuscules ─────────────────────────────────────────────
        $slug = strtolower($slug);

        // ── Étape 3 : caractères non-alphanumériques → tirets ───────────────
        // [^a-z0-9]+ : tout ce qui n'est pas lettre minuscule ou chiffre
        // + = groupé pour éviter les doubles tirets
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        // ── Étapes 4 & 5 : nettoyage des tirets de bord ─────────────────────
        $slug = trim($slug, '-');

        // Sécurité : slug vide (titre tout en caractères spéciaux)
        if ($slug === '') {
            $slug = 'formation';
        }

        // ── Garantie d'unicité ───────────────────────────────────────────────
        $baseSlug = $slug;
        $suffix   = 1;

        while ($this->slugExists($slug, $excludeId)) {
            // On incrémente le suffixe jusqu'à trouver un slug libre
            $suffix++;
            $slug = $baseSlug . '-' . $suffix;
        }

        return $slug;
    }

    /**
     * Vérifie si un slug est déjà utilisé en base.
     *
     * Utilise directement le QueryBuilder du repository pour éviter de charger
     * l'entité complète (seul le count nous intéresse).
     *
     * @param string   $slug      Slug à vérifier
     * @param int|null $excludeId ID de la formation à exclure (édition)
     */
    private function slugExists(string $slug, ?int $excludeId): bool
    {
        // On passe par le QueryBuilder pour une requête légère (COUNT seul)
        $qb = $this->courseRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.slug = :slug')
            ->setParameter('slug', $slug);

        // Si on est en mode édition, on exclut la formation elle-même
        if ($excludeId !== null) {
            $qb->andWhere('c.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    // ─── Inscription à une formation ─────────────────────────────────────────

    /**
     * Inscrit un utilisateur à une formation.
     *
     * Vérifie d'abord si l'utilisateur est déjà inscrit (via repository).
     * Si oui, lève une \LogicException avec un message lisible — le controller
     * attrapera cette exception pour afficher un flash 'error'.
     *
     * Si non, crée et persiste un nouveau CourseEnrollment.
     *
     * Note : la contrainte SQL UNIQUE sur (user_id, course_id) joue aussi
     * le rôle de filet de sécurité ultime, mais on préfère le contrôle PHP
     * pour des messages d'erreur lisibles.
     *
     * @throws \LogicException si l'utilisateur est déjà inscrit
     */
    public function enrollUser(Course $course, User $user): CourseEnrollment
    {
        // Vérifie l'inscription existante avant de créer
        $existing = $this->enrollmentRepository->findByUserAndCourse($user, $course);

        if ($existing !== null) {
            throw new \LogicException(
                sprintf(
                    'L\'utilisateur "%s" est déjà inscrit à la formation "%s".',
                    $user->getEmail(),
                    $course->getTitle(),
                )
            );
        }

        // Création de l'inscription
        $enrollment = new CourseEnrollment();
        $enrollment->setUser($user);
        $enrollment->setCourse($course);
        // progressPercent = 0 par défaut (défini dans l'entité)

        // persist() enregistre l'entité dans l'UnitOfWork de Doctrine
        // flush() déclenche l'INSERT réel en base
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    /**
     * Retourne l'inscription d'un utilisateur à une formation, ou null.
     *
     * Méthode utilitaire utilisée par les controllers pour vérifier
     * l'accès avant d'afficher l'espace apprenant.
     */
    public function findEnrollment(Course $course, User $user): ?CourseEnrollment
    {
        return $this->enrollmentRepository->findByUserAndCourse($user, $course);
    }

    // ─── Progression des leçons ───────────────────────────────────────────────

    /**
     * Met à jour (ou crée) la progression d'un apprenant sur une leçon.
     *
     * Appelé par la route AJAX POST /formations/{slug}/learn/{lessonId}/progress
     * toutes les N secondes pendant le visionnage de la vidéo.
     *
     * Comportement :
     *   1. Cherche un LessonProgress existant pour (enrollment, lesson)
     *   2. S'il n'existe pas → création + initialisation de startedAt
     *   3. Mise à jour de lastPositionSeconds
     *   4. Si $markCompleted = true → renseigne completedAt (si pas déjà fait)
     *   5. Délègue à recalculateProgress() qui fait le flush() (une seule transaction)
     *
     * @param CourseEnrollment $enrollment          L'inscription de l'apprenant
     * @param Lesson           $lesson              La leçon visionnée
     * @param int              $lastPositionSeconds Position actuelle dans la vidéo (secondes)
     * @param bool             $markCompleted       true = marquer la leçon comme terminée
     */
    public function updateLessonProgress(
        CourseEnrollment $enrollment,
        Lesson $lesson,
        int $lastPositionSeconds,
        bool $markCompleted = false,
    ): LessonProgress {
        // ── Recherche d'une progression existante ────────────────────────────
        $progress = $this->progressRepository->findByEnrollmentAndLesson($enrollment, $lesson);

        if ($progress === null) {
            // Première ouverture de la leçon → création de la ligne de progression
            $progress = new LessonProgress();
            $progress->setEnrollment($enrollment);
            $progress->setLesson($lesson);
            // startedAt = maintenant (premier accès à la leçon)
            $progress->setStartedAt(new \DateTime());

            // On informe Doctrine de suivre cette nouvelle entité
            $this->em->persist($progress);
        }

        // ── Mise à jour de la position de lecture ────────────────────────────
        // Le setter de l'entité applique max(0, $value) pour éviter les négatifs
        $progress->setLastPositionSeconds($lastPositionSeconds);

        // ── Marquage "terminée" ───────────────────────────────────────────────
        // On ne réécrit pas completedAt si la leçon est déjà terminée
        // (pas de "décomplétion" en V1)
        if ($markCompleted && $progress->getCompletedAt() === null) {
            $progress->setCompletedAt(new \DateTime());
        }

        // ── Recalcul du pourcentage global ────────────────────────────────────
        // IMPORTANT : on n'appelle PAS flush() ici.
        // recalculateProgress() effectue lui-même un flush() qui persiste
        // en une seule transaction à la fois le LessonProgress modifié ET
        // le nouveau progressPercent de l'enrollment.
        // Deux flush() successifs == deux aller-retours base inutiles.
        $this->recalculateProgress($enrollment);

        return $progress;
    }

    /**
     * Recalcule et sauvegarde le pourcentage de progression d'un enrollment.
     *
     * Formule : (leçons complétées / total leçons du course) × 100
     *
     * Cas particuliers :
     *   - 0 leçon dans la formation → 0% (évite la division par zéro)
     *   - 100% → completedAt est renseigné si ce n'est pas déjà le cas
     *
     * Cette méthode est aussi appelée en interne après updateLessonProgress().
     * Elle est publique pour permettre à l'admin de recalculer manuellement
     * si nécessaire (ex : après un import de données).
     */
    public function recalculateProgress(CourseEnrollment $enrollment): void
    {
        $course = $enrollment->getCourse();

        // ── Compter le total de leçons dans la formation ──────────────────────
        // On parcourt les modules et leurs leçons.
        // Pas de requête SQL dédiée : Doctrine a déjà chargé la collection
        // (elle est hydratée via la relation Course→modules→lessons).
        $totalLessons = 0;
        foreach ($course->getModules() as $module) {
            $totalLessons += $module->getLessons()->count();
        }

        // ── Éviter la division par zéro ───────────────────────────────────────
        if ($totalLessons === 0) {
            // Formation vide → progression = 0, sans mettre à jour completedAt
            $enrollment->setProgressPercent(0);
            $this->em->flush();
            return;
        }

        // ── Compter les leçons terminées via le repository ────────────────────
        // LessonProgressRepository::countCompletedByEnrollment() fait un COUNT SQL
        // efficace (seul le nombre nous intéresse, pas les entités complètes).
        $completedCount = $this->progressRepository->countCompletedByEnrollment($enrollment);

        // ── Calcul du pourcentage ─────────────────────────────────────────────
        // intval(round(...)) pour un entier propre (0–100)
        $percent = (int) round(($completedCount / $totalLessons) * 100);

        // Clampage défensif : le setProgressPercent de l'entité applique min/max
        $enrollment->setProgressPercent($percent);

        // ── Marquage "formation terminée" si 100% ─────────────────────────────
        if ($percent === 100 && $enrollment->getCompletedAt() === null) {
            $enrollment->setCompletedAt(new \DateTime());
        }

        // Persistance du nouvel état
        $this->em->flush();
    }
}

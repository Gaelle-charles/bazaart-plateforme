<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CourseEnrollment;
use App\Entity\Lesson;
use App\Entity\LessonProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour le suivi de progression par leçon (LessonProgress).
 *
 * @extends ServiceEntityRepository<LessonProgress>
 */
class LessonProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonProgress::class);
    }

    /**
     * Trouve la progression d'un apprenant sur une leçon spécifique.
     * Retourne null si l'apprenant n'a jamais accédé à cette leçon.
     *
     * Appelé par le controller de lecture de leçon pour initialiser
     * la position de reprise dans le player vidéo.
     */
    public function findByEnrollmentAndLesson(
        CourseEnrollment $enrollment,
        Lesson $lesson,
    ): ?LessonProgress {
        return $this->createQueryBuilder('p')
            ->where('p.enrollment = :enrollment')
            ->andWhere('p.lesson = :lesson')
            ->setParameter('enrollment', $enrollment)
            ->setParameter('lesson', $lesson)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte le nombre de leçons terminées pour une inscription donnée.
     * Utilisé par CourseEnrollmentService::recalculateProgress() pour calculer
     * le pourcentage de complétion.
     */
    public function countCompletedByEnrollment(CourseEnrollment $enrollment): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.enrollment = :enrollment')
            ->andWhere('p.completedAt IS NOT NULL')
            ->setParameter('enrollment', $enrollment)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne toutes les progressions d'une inscription, avec les leçons préchargées.
     * Utilisé pour afficher l'état de chaque leçon dans la vue "espace apprenant".
     *
     * FETCH JOIN sur lesson pour éviter N+1.
     *
     * @return LessonProgress[]
     */
    public function findByEnrollmentWithLesson(CourseEnrollment $enrollment): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.lesson', 'l')
            ->addSelect('l')
            ->where('p.enrollment = :enrollment')
            ->setParameter('enrollment', $enrollment)
            ->getQuery()
            ->getResult();
    }
}

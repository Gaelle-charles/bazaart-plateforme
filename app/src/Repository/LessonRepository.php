<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Course;
use App\Entity\Lesson;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les leçons (Lesson).
 *
 * @extends ServiceEntityRepository<Lesson>
 */
class LessonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lesson::class);
    }

    /**
     * Compte le nombre total de leçons d'une formation.
     * Utilisé par CourseEnrollmentService::recalculateProgress() pour calculer
     * le pourcentage de complétion d'un apprenant.
     *
     * Fait une jointure Course → CourseModule → Lesson en une seule requête
     * pour éviter le problème N+1.
     */
    public function countByCourse(Course $course): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.module', 'm')
            ->where('m.course = :course')
            ->setParameter('course', $course)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

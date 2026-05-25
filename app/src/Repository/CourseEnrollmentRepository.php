<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Course;
use App\Entity\CourseEnrollment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les inscriptions aux formations (CourseEnrollment).
 *
 * @extends ServiceEntityRepository<CourseEnrollment>
 */
class CourseEnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseEnrollment::class);
    }

    /**
     * Vérifie si un utilisateur est déjà inscrit à une formation.
     * Appelé par CourseEnrollmentService::enroll() avant de créer une inscription.
     *
     * Retourne l'inscription existante ou null si l'utilisateur n'est pas inscrit.
     */
    public function findByUserAndCourse(User $user, Course $course): ?CourseEnrollment
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.course = :course')
            ->setParameter('user', $user)
            ->setParameter('course', $course)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne toutes les inscriptions d'un utilisateur, avec la formation préchargée.
     * Utilisé pour le tableau de bord apprenant (liste "Mes formations").
     *
     * FETCH JOIN : évite les requêtes N+1 en chargeant Course en même temps.
     *
     * @return CourseEnrollment[]
     */
    public function findByUserWithCourse(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.course', 'c')
            ->addSelect('c')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.enrolledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CourseProposal;
use App\Entity\User;
use App\Enum\CourseProposalStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseProposal>
 */
class CourseProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseProposal::class);
    }

    /**
     * Propositions en attente de revue, les plus anciennes d'abord (FIFO de traitement).
     *
     * @return CourseProposal[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', CourseProposalStatus::Pending)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les propositions déjà traitées (acceptées ou refusées), récentes d'abord.
     *
     * @return CourseProposal[]
     */
    public function findReviewed(int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status != :pending')
            ->setParameter('pending', CourseProposalStatus::Pending)
            ->orderBy('p.reviewedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Propositions d'un utilisateur donné, récentes d'abord (widget « Mes propositions »).
     *
     * @return CourseProposal[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.proposedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de propositions en attente (badge admin).
     */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.status = :status')
            ->setParameter('status', CourseProposalStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

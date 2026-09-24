<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectActivity;
use App\Entity\ProjectTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur le journal d'activité de l'Espace projets (ADR-0037).
 *
 * @extends ServiceEntityRepository<ProjectActivity>
 */
class ProjectActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectActivity::class);
    }

    /**
     * Dernières activités (globales, d'un projet ou d'une tâche).
     *
     * @return list<ProjectActivity>
     */
    public function findRecent(int $limit = 20, ?Project $project = null, ?ProjectTask $task = null): array
    {
        $qb = $this->createQueryBuilder('act')
            ->leftJoin('act.actor', 'u')->addSelect('u')
            ->leftJoin('act.task', 't')->addSelect('t')
            ->orderBy('act.createdAt', 'DESC')
            ->addOrderBy('act.id', 'DESC')
            ->setMaxResults($limit);

        if ($project !== null) {
            $qb->andWhere('act.project = :project')->setParameter('project', $project);
        }
        if ($task !== null) {
            $qb->andWhere('act.task = :task')->setParameter('task', $task);
        }

        /** @var list<ProjectActivity> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}

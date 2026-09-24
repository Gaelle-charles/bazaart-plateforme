<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectSubtask;
use App\Entity\ProjectTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les sous-tâches (checklists) de l'Espace projets (ADR-0037).
 *
 * @extends ServiceEntityRepository<ProjectSubtask>
 */
class ProjectSubtaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectSubtask::class);
    }

    /** Position de la prochaine sous-tâche (ajoutée en bas de la checklist). */
    public function nextPosition(ProjectTask $task): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.task = :task')
            ->setParameter('task', $task)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }
}

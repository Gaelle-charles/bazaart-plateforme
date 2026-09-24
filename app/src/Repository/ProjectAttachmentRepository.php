<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les pièces jointes (Drive ou liens) de l'Espace projets (ADR-0037).
 *
 * Les méthodes héritées (find, findBy, findOneBy, count) suffisent pour l'instant.
 *
 * @extends ServiceEntityRepository<ProjectAttachment>
 */
class ProjectAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAttachment::class);
    }
}

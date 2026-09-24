<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectMemberProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les profils / préférences des membres de l'Espace projets (ADR-0037).
 *
 * Les méthodes héritées (find, findBy, findOneBy, count) suffisent pour l'instant.
 *
 * @extends ServiceEntityRepository<ProjectMemberProfile>
 */
class ProjectMemberProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMemberProfile::class);
    }
}

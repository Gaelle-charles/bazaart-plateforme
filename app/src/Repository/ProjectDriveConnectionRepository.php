<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectDriveConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectDriveConnection>
 */
class ProjectDriveConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectDriveConnection::class);
    }

    /** La connexion active (la plus récente), ou null si le Drive n'est pas connecté. */
    public function findCurrent(): ?ProjectDriveConnection
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}

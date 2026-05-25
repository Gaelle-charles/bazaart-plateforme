<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CourseModule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les modules de formation (CourseModule).
 *
 * En V1, les modules sont toujours accédés via la collection Course::$modules
 * (Doctrine charge les modules triés par orderPosition grâce au #[ORM\OrderBy]).
 * Ce repository est créé pour la convention du projet et pour les besoins V2
 * (réordonnancement via drag-and-drop, etc.).
 *
 * @extends ServiceEntityRepository<CourseModule>
 */
class CourseModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseModule::class);
    }
}

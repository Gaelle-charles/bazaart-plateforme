<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LessonResource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les ressources téléchargeables de leçons (LessonResource).
 *
 * @extends ServiceEntityRepository<LessonResource>
 */
class LessonResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonResource::class);
    }
}

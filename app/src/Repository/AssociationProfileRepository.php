<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssociationProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Fiches de nos associations (ADR-0038).
 *
 * @extends ServiceEntityRepository<AssociationProfile>
 */
class AssociationProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssociationProfile::class);
    }
}

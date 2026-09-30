<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectOpportunityReview;
use App\Entity\Resource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Décisions de l'équipe sur les opportunités (onglet « Opportunités », ADR-0038).
 *
 * @extends ServiceEntityRepository<ProjectOpportunityReview>
 */
class ProjectOpportunityReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectOpportunityReview::class);
    }

    public function findOneForResource(Resource $resource): ?ProjectOpportunityReview
    {
        return $this->findOneBy(['resource' => $resource]);
    }

    /**
     * Toutes les décisions, indexées par ID d'opportunité (une seule requête pour la page).
     *
     * @return array<int, ProjectOpportunityReview>
     */
    public function findAllIndexedByResource(): array
    {
        /** @var list<ProjectOpportunityReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->leftJoin('r.project', 'p')->addSelect('p')
            ->leftJoin('r.updatedBy', 'u')->addSelect('u')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($reviews as $review) {
            $indexed[(int) $review->getResource()->getId()] = $review;
        }

        return $indexed;
    }
}

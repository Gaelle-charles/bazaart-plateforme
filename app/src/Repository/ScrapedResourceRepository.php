<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScrapedResource;
use App\Enum\ScrapedResourceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScrapedResource>
 */
class ScrapedResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScrapedResource::class);
    }

    /**
     * Cherche une opportunité par son URL.
     * Utilisé pour éviter les doublons lors du scraping.
     */
    public function findByUrl(string $url): ?ScrapedResource
    {
        return $this->findOneBy(['url' => $url]);
    }

    /**
     * Retourne toutes les opportunités en attente de validation, triées par score desc.
     *
     * @return ScrapedResource[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Pending)
            ->orderBy('s.relevanceScore', 'DESC')
            ->addOrderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les opportunités déjà validées, triées par date desc.
     *
     * @return ScrapedResource[]
     */
    public function findVerified(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Verified)
            ->orderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les opportunités (pending + verified), triées par score puis date.
     *
     * @return ScrapedResource[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.status', 'ASC')        // pending avant verified
            ->addOrderBy('s.relevanceScore', 'DESC')
            ->addOrderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

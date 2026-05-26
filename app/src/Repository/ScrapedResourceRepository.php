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

    /**
     * Compte les opportunités en attente de validation.
     *
     * Utilisé pour le badge "X en attente" dans le widget scraping du dashboard admin.
     * On utilise COUNT en DQL plutôt que count(findPending()) pour éviter de charger
     * tous les objets en mémoire — bien plus efficace sur une table volumineuse.
     */
    public function countPending(): int
    {
        // getSingleScalarResult() retourne une chaîne en PHP ; le cast (int) est obligatoire
        // pour satisfaire PHPStan niveau 6 (return type strict : int).
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne toutes les opportunités rejetées, triées du plus récent au plus ancien.
     *
     * @return ScrapedResource[]
     */
    public function findRejected(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Rejected)
            ->orderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne la date du scraping le plus récent, ou null si la table est vide.
     *
     * Utilisé dans le dashboard admin pour afficher "Dernier scraping : XX/XX/XXXX".
     * On utilise SELECT MAX() en DQL pour éviter de charger toute la table en mémoire.
     *
     * Note : getSingleScalarResult() peut retourner null (table vide) ou une string ISO-8601.
     * On construit un \DateTime depuis cette string, ou on retourne null si table vide.
     */
    public function findLatestScrapedAt(): ?\DateTimeInterface
    {
        // Retourne la valeur scalaire maximale de scrapedAt (ou null si table vide)
        $result = $this->createQueryBuilder('s')
            ->select('MAX(s.scrapedAt) AS latestAt')
            ->getQuery()
            ->getSingleScalarResult();

        // Si la table est vide, MAX() retourne null — on renvoie null directement
        if ($result === null) {
            return null;
        }

        // getSingleScalarResult() retourne une string (format ISO-8601 depuis PostgreSQL).
        // On la convertit en \DateTime pour que Twig puisse appliquer le filtre |date().
        return new \DateTime((string) $result);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Enum\ProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les projets de l'Espace projets (ADR-0037).
 *
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * Projets pour la page « Projets » (cartes + chronologie).
     *
     * Le responsable est chargé dans la même requête (JOIN FETCH) pour éviter
     * une requête par carte (problème N+1).
     *
     * @param ProjectStatus|null $status          filtre sur un statut précis
     * @param bool               $includeArchived inclure les projets archivés (si aucun statut n'est imposé)
     *
     * @return list<Project>
     */
    public function findForList(?ProjectStatus $status = null, bool $includeArchived = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.owner', 'o')->addSelect('o')
            ->orderBy('p.name', 'ASC');

        if ($status !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        } elseif (!$includeArchived) {
            $qb->andWhere('p.status <> :archived')->setParameter('archived', ProjectStatus::Archived);
        }

        /** @var list<Project> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Projets proposés dans les listes déroulantes (hors archivés), triés par nom.
     *
     * @return list<Project>
     */
    public function findSelectable(): array
    {
        /** @var list<Project> $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.status <> :archived')
            ->setParameter('archived', ProjectStatus::Archived)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** Nombre de projets « ouverts » (à venir, en cours, en pause). */
    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.status IN (:open)')
            ->setParameter('open', ProjectStatus::openCases())
            ->getQuery()
            ->getSingleScalarResult();
    }
}

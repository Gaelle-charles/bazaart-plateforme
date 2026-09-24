<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur le mur de notes de l'Espace projets (ADR-0037).
 *
 * @extends ServiceEntityRepository<ProjectNote>
 */
class ProjectNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectNote::class);
    }

    /**
     * Notes du mur, épinglées d'abord puis les plus récentes.
     * Auteur et projet chargés d'office (JOIN FETCH) : chaque note affiche sa signature.
     *
     * @return list<ProjectNote>
     */
    public function findForWall(?int $authorId = null, ?int $projectId = null, ?string $search = null, bool $pinnedOnly = false, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('n')
            ->leftJoin('n.author', 'u')->addSelect('u')
            ->leftJoin('n.project', 'p')->addSelect('p')
            ->orderBy('n.pinned', 'DESC')
            ->addOrderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($authorId !== null) {
            $qb->andWhere('u.id = :authorId')->setParameter('authorId', $authorId);
        }
        if ($projectId !== null) {
            $qb->andWhere('p.id = :projectId')->setParameter('projectId', $projectId);
        }
        if ($search !== null && $search !== '') {
            $like = '%' . mb_strtolower(addcslashes($search, '%_\\')) . '%';
            $qb->andWhere('LOWER(n.content) LIKE :search OR LOWER(COALESCE(n.title, \'\')) LIKE :search')
                ->setParameter('search', $like);
        }
        if ($pinnedOnly) {
            $qb->andWhere('n.pinned = true');
        }

        /** @var list<ProjectNote> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Notes rattachées à un projet (fiche projet), plus récentes d'abord.
     *
     * @return list<ProjectNote>
     */
    public function findByProject(Project $project, int $limit = 30): array
    {
        return $this->findForWall(projectId: $project->getId(), limit: $limit);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssociationProject;
use App\Entity\AssociationProjectBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les blocs de présentation des projets de l'association (ADR-0039).
 *
 * @extends ServiceEntityRepository<AssociationProjectBlock>
 */
class AssociationProjectBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssociationProjectBlock::class);
    }

    /**
     * Un bloc, à condition qu'il appartienne AU projet donné.
     *
     * SÉCURITÉ : côté partenaires, on ne cherche JAMAIS un bloc par son seul numéro.
     * Sinon, avec l'adresse d'un projet publié, on pourrait demander l'image d'un bloc
     * appartenant à un projet non publié en changeant simplement le numéro.
     */
    public function findOneForProject(int $blockId, AssociationProject $project): ?AssociationProjectBlock
    {
        return $this->findOneBy(['id' => $blockId, 'project' => $project]);
    }

    /** Ordre à donner à un nouveau bloc : à la fin de la présentation (pas de 10). */
    public function nextPosition(AssociationProject $project): int
    {
        $max = $this->createQueryBuilder('b')
            ->select('MAX(b.position)')
            ->andWhere('b.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 10 : ((int) $max) + 10;
    }
}

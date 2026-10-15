<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssociationProject;
use App\Entity\AssociationProjectDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssociationProjectDocument>
 */
class AssociationProjectDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssociationProjectDocument::class);
    }

    /**
     * Un document cherché DANS une fiche donnée (jamais par son seul numéro) :
     * depuis l'adresse d'une fiche publiée, on ne peut pas lire le document d'une
     * autre fiche en changeant simplement le numéro.
     */
    public function findOneForProject(int $documentId, AssociationProject $project): ?AssociationProjectDocument
    {
        return $this->findOneBy(['id' => $documentId, 'project' => $project]);
    }

    /** Position suivante : à la fin de la liste des documents de la fiche. */
    public function nextPosition(AssociationProject $project): int
    {
        $max = $this->createQueryBuilder('d')
            ->select('MAX(d.position)')
            ->where('d.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 10 : (int) $max + 10;
    }
}

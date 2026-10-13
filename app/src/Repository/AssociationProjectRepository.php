<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssociationProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les projets de l'association (page « Projets en cours », ADR-0039).
 *
 * Règle d'or : toute méthode utilisée côté partenaires filtre sur isPublished = true.
 * Ces méthodes ont « Published » dans leur nom, pour que ce soit visible à la relecture.
 *
 * @extends ServiceEntityRepository<AssociationProject>
 */
class AssociationProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssociationProject::class);
    }

    /**
     * Toutes les fiches (publiées ou non) pour le back-office, dans l'ordre d'affichage.
     * Le titre départage deux fiches qui auraient le même ordre.
     *
     * @return list<AssociationProject>
     */
    public function findAllOrdered(): array
    {
        /** @var list<AssociationProject> $result */
        $result = $this->createQueryBuilder('p')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Fiches PUBLIÉES, dans l'ordre d'affichage, pour le carrousel et l'API.
     *
     * @param bool $withBlocks charger aussi les blocs de présentation dans la même
     *                         requête (JOIN FETCH), utile pour l'API qui les renvoie
     *                         tous : évite une requête par projet (problème « N+1 »).
     *
     * @return list<AssociationProject>
     */
    public function findPublishedOrdered(bool $withBlocks = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.isPublished = true')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.title', 'ASC');

        if ($withBlocks) {
            $qb->leftJoin('p.blocks', 'b')->addSelect('b');
        }

        /** @var list<AssociationProject> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Une fiche PUBLIÉE par son slug (null si inconnue OU non publiée : le contrôleur
     * répond alors 404, exactement comme pour une adresse qui n'existe pas).
     */
    public function findPublishedBySlug(string $slug): ?AssociationProject
    {
        /** @var AssociationProject|null $project */
        $project = $this->createQueryBuilder('p')
            ->leftJoin('p.blocks', 'b')->addSelect('b')
            ->andWhere('p.slug = :slug')
            ->andWhere('p.isPublished = true')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $project;
    }

    /** Un slug est-il déjà pris ? (sert à générer un slug unique : « formations-2 »…) */
    public function slugExists(string $slug): bool
    {
        return $this->count(['slug' => $slug]) > 0;
    }

    /**
     * Ordre à donner à une nouvelle fiche : à la fin de la liste, avec un pas de 10
     * (comme les fiches de départ 10, 20… 90) pour pouvoir intercaler facilement.
     */
    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('p')
            ->select('MAX(p.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 10 : ((int) $max) + 10;
    }
}

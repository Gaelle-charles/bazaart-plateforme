<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectLabel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les étiquettes des tâches de l'Espace projets (ADR-0037).
 *
 * @extends ServiceEntityRepository<ProjectLabel>
 */
class ProjectLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectLabel::class);
    }

    /**
     * Toutes les étiquettes, triées par nom (listes déroulantes, filtres).
     *
     * @return list<ProjectLabel>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Recherche insensible à la casse : « com » retrouve l'étiquette « Com »
     * existante au lieu de créer un doublon.
     */
    public function findOneByNameInsensitive(string $name): ?ProjectLabel
    {
        /** @var ProjectLabel|null $label */
        $label = $this->createQueryBuilder('l')
            ->andWhere('LOWER(l.name) = :name')
            ->setParameter('name', mb_strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $label;
    }
}

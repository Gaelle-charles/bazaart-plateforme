<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les catégories du forum.
 *
 * Convention Doctrine : les repositories contiennent toute la logique de requête.
 * Les controllers et services ne font jamais de DQL ou QueryBuilder directement.
 *
 * @extends ServiceEntityRepository<ForumCategory>
 */
class ForumCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumCategory::class);
    }

    /**
     * Retourne toutes les catégories actives, triées par position d'affichage.
     *
     * Utilisée sur la page d'accueil du forum pour afficher la liste des catégories.
     * Les catégories inactives (isActive = false) sont exclues — elles ne sont
     * visibles que dans l'interface d'administration.
     *
     * @return ForumCategory[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            // Filtre : catégories actives uniquement
            ->where('c.isActive = true')
            // Tri par orderPosition croissant (0 = premier affiché)
            ->orderBy('c.orderPosition', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve une catégorie par son slug, avec ses threads pré-chargés (LEFT JOIN).
     *
     * Le LEFT JOIN FETCH évite le problème N+1 : sans ça, chaque accès à
     * $category->getThreads() déclencherait une nouvelle requête SQL.
     * Avec FETCH, tout est chargé en une seule requête.
     *
     * Retourne null si aucune catégorie ne correspond au slug (→ 404 dans le controller).
     */
    public function findBySlug(string $slug): ?ForumCategory
    {
        return $this->createQueryBuilder('c')
            // LEFT JOIN FETCH charge les threads en même temps que la catégorie
            ->leftJoin('c.threads', 't')
            ->addSelect('t')
            // Filtre sur le slug (identifiant URL de la catégorie)
            ->where('c.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

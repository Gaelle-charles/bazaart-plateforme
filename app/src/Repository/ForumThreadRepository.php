<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumCategory;
use App\Entity\ForumThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les threads du forum.
 *
 * Centralise toutes les requêtes liées aux threads.
 * Le controller et le service délèguent ici toute la logique de persistance.
 *
 * @extends ServiceEntityRepository<ForumThread>
 */
class ForumThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumThread::class);
    }

    /**
     * Retourne les threads d'une catégorie avec pagination.
     *
     * Tri appliqué (dans cet ordre de priorité) :
     *   1. isPinned DESC  → les threads épinglés sont toujours en tête
     *   2. lastReplyAt DESC NULLS LAST → les threads avec activité récente remontent
     *   3. createdAt DESC → à égalité, les plus récents d'abord
     *
     * Les jointures FETCH évitent le problème N+1 lors de l'affichage de la liste
     * (accès à $thread->getAuthor()->getEmail() sans requête supplémentaire).
     *
     * @return ForumThread[]
     */
    public function findByCategory(ForumCategory $category, int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('t')
            // Charge l'auteur en même temps (évite N requêtes pour N threads)
            ->leftJoin('t.author', 'a')
            ->addSelect('a')
            // Charge la catégorie également (utile pour le breadcrumb dans les templates)
            ->leftJoin('t.category', 'c')
            ->addSelect('c')
            // Filtre : uniquement les threads de la catégorie demandée
            ->where('t.category = :category')
            ->setParameter('category', $category)
            // Tri : épinglés en premier, puis par activité récente
            ->orderBy('t.isPinned', 'DESC')
            ->addOrderBy('t.lastReplyAt', 'DESC')   // NULLS LAST en PostgreSQL
            ->addOrderBy('t.createdAt', 'DESC')
            // Pagination
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve un thread par son slug dans une catégorie donnée.
     *
     * La combinaison slug + catégorie est utilisée pour les URLs du type :
     * /forum/{categorySlug}/{threadSlug}
     *
     * On vérifie la catégorie en plus du slug pour éviter les collisions
     * entre threads de catégories différentes qui auraient le même slug.
     *
     * Retourne null si non trouvé (→ 404 dans le controller).
     */
    public function findBySlugAndCategory(string $slug, ForumCategory $category): ?ForumThread
    {
        return $this->createQueryBuilder('t')
            // Pré-charge l'auteur pour éviter une requête lazy lors de l'affichage
            ->leftJoin('t.author', 'a')
            ->addSelect('a')
            ->where('t.slug = :slug')
            ->andWhere('t.category = :category')
            ->setParameter('slug', $slug)
            ->setParameter('category', $category)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte le nombre total de threads dans une catégorie.
     *
     * Utilisé pour calculer le nombre de pages (pagination) et pour
     * afficher les statistiques de la catégorie (ex: "42 discussions").
     */
    public function countByCategory(ForumCategory $category): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne les N derniers threads d'une catégorie (pour les aperçus sur la page d'accueil).
     *
     * Utilisé dans ForumController::index() pour afficher un aperçu de chaque catégorie
     * sans charger tous les threads de chaque catégorie.
     *
     * @return ForumThread[]
     */
    public function findLatestByCategory(ForumCategory $category, int $limit = 3): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.author', 'a')
            ->addSelect('a')
            ->where('t.category = :category')
            ->setParameter('category', $category)
            ->orderBy('t.isPinned', 'DESC')
            ->addOrderBy('t.lastReplyAt', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

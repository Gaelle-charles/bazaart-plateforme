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
     *   1. isPinned DESC     → les threads épinglés sont toujours en tête
     *   2. NULLS LAST manuel → les threads sans réponse (lastReplyAt = NULL) passent EN FIN de liste
     *   3. lastReplyAt DESC  → les threads avec activité récente remontent
     *   4. createdAt DESC    → à égalité, les plus récents d'abord
     *
     * Correction 2 — Bug PostgreSQL NULLS FIRST :
     *   `ORDER BY last_reply_at DESC` génère implicitement NULLS FIRST en PostgreSQL.
     *   Conséquence : les threads sans aucune réponse (lastReplyAt = NULL) remontaient
     *   en tête de liste, devant les threads actifs — comportement inverse du voulu.
     *
     *   Solution : on ajoute une expression CASE WHEN intermédiaire qui vaut 0 quand
     *   lastReplyAt est renseigné, et 1 quand il est NULL. En triant ASC sur cette
     *   colonne calculée, les threads avec activité (0) passent avant les threads sans (1).
     *   Doctrine ORM 3.x supporte les expressions CASE WHEN dans addOrderBy().
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
            // Tri 1 : threads épinglés toujours en premier
            ->orderBy('t.isPinned', 'DESC')
            // Tri 2 : simule NULLS LAST — 0 si lastReplyAt renseigné, 1 si NULL
            // Les threads avec activité (0) passent avant les threads sans réponse (1).
            ->addOrderBy('CASE WHEN t.lastReplyAt IS NULL THEN 1 ELSE 0 END', 'ASC')
            // Tri 3 : parmi les threads avec activité, le plus récent en premier
            ->addOrderBy('t.lastReplyAt', 'DESC')
            // Tri 4 : à égalité complète (ex : tous NULL), les plus récents en premier
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
     * Retourne les N threads les plus récents, toutes catégories confondues.
     *
     * Tri appliqué : updatedAt DESC — le thread avec la dernière activité
     * (nouveau post ou mise à jour) remonte en tête du feed.
     *
     * Pourquoi updatedAt et non lastReplyAt ?
     *   - updatedAt est toujours renseigné (jamais NULL), contrairement à lastReplyAt
     *     qui est NULL tant qu'il n'y a pas de réponse.
     *   - Utiliser updatedAt évite de reproduire le bug NULLS FIRST documenté
     *     dans findByCategory() et findLatestByCategory().
     *   - Un thread édité sans réponse remonte aussi, ce qui est souhaité ici.
     *
     * Jointures JOIN (pas LEFT JOIN) :
     *   On utilise INNER JOIN sur category et author car ces relations sont
     *   obligatoires (NOT NULL en base). Le JOIN est plus performant que
     *   LEFT JOIN et évite de charger des threads orphelins.
     *   Doctrine charge les entités jointes en même temps (FETCH JOIN via addSelect)
     *   ce qui évite les requêtes N+1 lors du rendu Twig :
     *     - thread.category.slug → pas de requête supplémentaire
     *     - thread.author.email  → pas de requête supplémentaire
     *
     * Utilisé par ForumController::index() — variable `threads` dans le template.
     *
     * @return ForumThread[]
     */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('t')
            // FETCH JOIN : charge category et author en une seule requête SQL
            ->addSelect('c', 'u')
            ->join('t.category', 'c')
            ->join('t.author', 'u')
            // Tri unique : activité la plus récente en tête (updatedAt jamais NULL)
            ->orderBy('t.updatedAt', 'DESC')
            // Limite le nombre de résultats pour le feed de la page d'accueil
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les N derniers threads d'une catégorie (pour les aperçus sur la page d'accueil).
     *
     * Utilisé dans ForumController::index() pour afficher un aperçu de chaque catégorie
     * sans charger tous les threads de chaque catégorie.
     *
     * Correction 2 — même bug NULLS FIRST que findByCategory() — même correction.
     * Les threads sans réponse (lastReplyAt = NULL) ne doivent pas remonter devant
     * les threads avec activité récente dans les aperçus de la page d'accueil.
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
            // Même logique de tri que findByCategory() — cf. commentaires détaillés là-haut
            ->orderBy('t.isPinned', 'DESC')
            ->addOrderBy('CASE WHEN t.lastReplyAt IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('t.lastReplyAt', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

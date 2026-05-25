<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Course;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les formations (Course).
 *
 * Centralise toutes les requêtes liées au catalogue de formations.
 * Les controllers et services ne font jamais de DQL/SQL directement :
 * ils appellent les méthodes de ce repository.
 *
 * @extends ServiceEntityRepository<Course>
 */
class CourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Course::class);
    }

    /**
     * Retourne toutes les formations publiées, triées par date de publication décroissante.
     * Utilisé pour le catalogue public /formations.
     *
     * @return Course[]
     */
    public function findAllPublished(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isPublished = true')
            ->orderBy('c.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche une formation publiée par son slug.
     * Utilisé pour la route /formations/{slug}.
     * null si la formation n'existe pas ou n'est pas publiée.
     */
    public function findPublishedBySlug(string $slug): ?Course
    {
        return $this->createQueryBuilder('c')
            ->where('c.slug = :slug')
            ->andWhere('c.isPublished = true')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne toutes les formations avec leurs modules et leçons en une seule requête SQL.
     *
     * Pourquoi cette méthode existe :
     *   La liste admin (/admin/formations) itère sur courses → modules → lessons
     *   dans le template Twig. Sans FETCH JOIN, Doctrine charge chaque relation
     *   de façon "lazy" : 1 requête pour les formations, puis 1 par module pour
     *   ses leçons, etc. Avec N formations ayant M modules chacune, cela fait
     *   1 + N + N*M requêtes (problème N+1).
     *
     * Solution : leftJoin + addSelect force Doctrine à hydrater les collections
     *   en une seule requête SQL avec des JOINs.
     *   "leftJoin" (et non "join") car une formation peut n'avoir aucun module
     *   (brouillon vide) — on veut quand même la voir dans la liste.
     *
     * Tri :
     *   - formations par createdAt DESC (plus récentes d'abord)
     *   - modules par orderPosition ASC (ordre pédagogique)
     *   - leçons par orderPosition ASC (idem)
     *
     * @return Course[]
     */
    public function findAllWithModulesAndLessons(): array
    {
        return $this->createQueryBuilder('c')
            // Jointure gauche sur les modules de chaque formation
            // 'm' est l'alias utilisable dans le reste de la requête
            ->leftJoin('c.modules', 'm')
            // addSelect inclut les modules dans l'hydratation Doctrine
            // (sans addSelect, le leftJoin filtre en SQL mais Doctrine
            //  ne charge pas les entités jointes → lazy-load toujours actif)
            ->addSelect('m')
            // Jointure gauche sur les leçons de chaque module
            ->leftJoin('m.lessons', 'l')
            ->addSelect('l')
            // Tri des formations : plus récentes d'abord
            ->orderBy('c.createdAt', 'DESC')
            // Tri des modules : ordre pédagogique
            ->addOrderBy('m.orderPosition', 'ASC')
            // Tri des leçons : ordre pédagogique dans le module
            ->addOrderBy('l.orderPosition', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

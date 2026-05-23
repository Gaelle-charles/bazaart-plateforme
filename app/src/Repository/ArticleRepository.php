<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\ArticleStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * Retourne tous les articles publiés, du plus récent au plus ancien.
     * On charge l'auteur et son profil artiste en une seule requête (évite le N+1).
     *
     * @return Article[]
     */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.author', 'u')->addSelect('u')
            ->leftJoin('u.artistProfile', 'ap')->addSelect('ap')
            ->where('a.status = :status')
            ->setParameter('status', ArticleStatus::Published)
            ->orderBy('a.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne un article publié par son slug.
     * Utilisé pour la page de détail : /articles/mon-article
     */
    public function findPublishedBySlug(string $slug): ?Article
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.author', 'u')->addSelect('u')
            ->leftJoin('u.artistProfile', 'ap')->addSelect('ap')
            ->where('a.slug = :slug')
            ->andWhere('a.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', ArticleStatus::Published)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne tous les articles d'un auteur donné (brouillons inclus).
     * Utilisé dans "Mes articles" pour que l'auteur voie aussi ses brouillons.
     *
     * @return Article[]
     */
    public function findByAuthor(User $author): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.author = :author')
            ->setParameter('author', $author)
            ->orderBy('a.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne tous les articles (publiés + brouillons) pour l'admin.
     *
     * @return Article[]
     */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.author', 'u')->addSelect('u')
            ->leftJoin('u.artistProfile', 'ap')->addSelect('ap')
            ->orderBy('a.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

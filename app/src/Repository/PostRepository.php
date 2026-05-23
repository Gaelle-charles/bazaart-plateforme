<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Retourne les posts pour le fil d'actualité, du plus récent au plus ancien.
     * On charge en une seule requête l'auteur, ses profils, les commentaires et les likes
     * pour éviter le problème N+1 (une requête par post).
     *
     * @return Post[]
     */
    public function findFeed(int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            // JOIN sur l'auteur et son profil artiste (pour afficher son nom/avatar)
            ->leftJoin('p.author', 'u')->addSelect('u')
            ->leftJoin('u.artistProfile', 'ap')->addSelect('ap')
            ->leftJoin('u.organizationProfile', 'op')->addSelect('op')
            // JOIN sur les commentaires et leurs auteurs
            ->leftJoin('p.comments', 'c')->addSelect('c')
            ->leftJoin('c.author', 'ca')->addSelect('ca')
            ->leftJoin('ca.artistProfile', 'cap')->addSelect('cap')
            // JOIN sur les likes
            ->leftJoin('p.likes', 'l')->addSelect('l')
            ->leftJoin('l.user', 'lu')->addSelect('lu')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le total de posts pour la pagination.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}

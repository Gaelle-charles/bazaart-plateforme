<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Post;
use App\Entity\PostLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostLike>
 */
class PostLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostLike::class);
    }

    /**
     * Cherche si un utilisateur a déjà liké un post.
     * Retourne le PostLike existant ou null.
     */
    public function findByPostAndUser(Post $post, User $user): ?PostLike
    {
        return $this->findOneBy(['post' => $post, 'user' => $user]);
    }
}

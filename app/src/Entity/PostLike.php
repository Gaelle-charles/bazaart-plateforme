<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PostLikeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * PostLike représente le "like" d'un utilisateur sur un post.
 *
 * On utilise une entité dédiée plutôt qu'une relation ManyToMany directe
 * car ça permet d'ajouter facilement des métadonnées (date du like, etc.)
 * et de faire des requêtes précises (qui a liké ? est-ce que j'ai liké ?).
 *
 * Contrainte unique sur (post, user) : un utilisateur ne peut liker qu'une fois.
 */
#[ORM\Entity(repositoryClass: PostLikeRepository::class)]
#[ORM\Table(name: 'post_likes')]
#[ORM\UniqueConstraint(name: 'unique_post_user_like', columns: ['post_id', 'user_id'])]
class PostLike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'likes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Post $post;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct(Post $post, User $user)
    {
        $this->post      = $post;
        $this->user      = $user;
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}

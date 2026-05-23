<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Post représente une publication dans le fil d'actualité communautaire.
 * Un utilisateur peut poster du texte, éventuellement avec un lien ou une image.
 */
#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
#[ORM\HasLifecycleCallbacks]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Auteur du post — ManyToOne car un user peut avoir plusieurs posts.
     * nullable: false car un post orphelin n'a pas de sens.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    /**
     * Contenu textuel du post (limité à 2000 caractères côté formulaire).
     */
    #[ORM\Column(type: 'text')]
    private string $content;

    /**
     * Commentaires associés à ce post.
     * orphanRemoval: true = si on retire un comment du post, il est supprimé en BDD.
     */
    #[ORM\OneToMany(mappedBy: 'post', targetEntity: Comment::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    /**
     * Likes associés à ce post.
     */
    #[ORM\OneToMany(mappedBy: 'post', targetEntity: PostLike::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $likes;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
        $this->likes    = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // --- Getters / Setters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function getLikes(): Collection
    {
        return $this->likes;
    }

    /**
     * Compte le nombre de likes — utile dans les templates sans requête supplémentaire.
     */
    public function getLikesCount(): int
    {
        return $this->likes->count();
    }

    /**
     * Vérifie si un utilisateur donné a déjà liké ce post.
     */
    public function isLikedByUser(User $user): bool
    {
        foreach ($this->likes as $like) {
            if ($like->getUser() === $user) {
                return true;
            }
        }
        return false;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}

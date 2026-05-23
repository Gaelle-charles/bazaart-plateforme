<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ArticleStatus;
use App\Repository\ArticleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Article représente un article de blog/magazine long format.
 * Contrairement aux Posts (courts, communautaires), les articles sont
 * structurés : titre, accroche, contenu long, image de couverture.
 *
 * Cycle de vie : Draft (brouillon) → Published (publié), cf. ArticleStatus.
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Table(name: 'articles')]
#[ORM\HasLifecycleCallbacks]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Titre de l'article — affiché en gros sur la page et dans les listes.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * Slug : version "URL-friendly" du titre.
     * Ex: "Mon premier article" → "mon-premier-article"
     * Utilisé dans l'URL : /articles/mon-premier-article
     * Doit être unique en base.
     */
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $slug;

    /**
     * Accroche courte affichée dans les listes (max ~300 caractères).
     * Résume l'article pour donner envie de lire.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $excerpt = null;

    /**
     * Contenu complet de l'article en texte libre.
     * Affiché avec nl2br (retours à la ligne respectés).
     */
    #[ORM\Column(type: 'text')]
    private string $content;

    /**
     * Chemin relatif vers l'image de couverture.
     * Ex: "uploads/articles/cover_abc123.jpg"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $coverImagePath = null;

    /**
     * Auteur de l'article (l'utilisateur connecté au moment de la création).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    /**
     * Statut de publication — voir App\Enum\ArticleStatus.
     *
     * Pourquoi `enumType: ArticleStatus::class` ?
     * Doctrine sait stocker un enum en BDD comme sa valeur backed (ici un string).
     * En PHP la propriété est typée `ArticleStatus`, donc le moteur PHP refuse
     * toute autre valeur. Côté SQL, la colonne reste un VARCHAR(20) classique :
     * pas de migration nécessaire, les valeurs 'draft'/'published' existantes
     * sont automatiquement hydratées en cases de l'enum.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ArticleStatus::class)]
    private ArticleStatus $status = ArticleStatus::Draft;

    /**
     * Date de publication effective (quand le statut passe à 'published').
     * Null si l'article est encore en brouillon.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): static
    {
        $this->excerpt = $excerpt;
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

    public function getCoverImagePath(): ?string
    {
        return $this->coverImagePath;
    }

    public function setCoverImagePath(?string $coverImagePath): static
    {
        $this->coverImagePath = $coverImagePath;
        return $this;
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

    public function getStatus(): ArticleStatus
    {
        return $this->status;
    }

    public function setStatus(ArticleStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isPublished(): bool
    {
        // Comparaison d'enum par identité (===) : deux cases du même enum
        // sont identiques uniquement si elles pointent sur la même case.
        return $this->status === ArticleStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->status === ArticleStatus::Draft;
    }

    public function getPublishedAt(): ?\DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeInterface $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
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

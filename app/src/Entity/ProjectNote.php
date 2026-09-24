<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProjectNoteColor;
use App\Repository\ProjectNoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectNote — une note du « mur d'équipe » de l'Espace projets (ADR-0037).
 *
 * Le mur est visible par les 3 membres de l'équipe. Chaque note est SIGNÉE :
 * l'auteur (author) et les dates de création / modification sont toujours
 * affichés, pour savoir qui a écrit quoi.
 *
 * Règles (portées par ProjectVoter) :
 *   - tout le monde peut lire et épingler / désépingler une note ;
 *   - seule l'autrice peut la modifier ou la supprimer.
 *
 * Une note peut être rattachée à un projet (elle apparaît alors aussi sur la
 * fiche du projet). Si le projet est supprimé, la note reste (SET NULL).
 */
#[ORM\Entity(repositoryClass: ProjectNoteRepository::class)]
#[ORM\Table(name: 'project_notes')]
#[ORM\Index(name: 'idx_project_notes_pinned_created', columns: ['pinned', 'created_at'])]
class ProjectNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private string $content = '';

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectNoteColor::class)]
    private ProjectNoteColor $color = ProjectNoteColor::Yellow;

    /** Note épinglée = affichée en tête du mur et sur la vue d'ensemble. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $pinned = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Renseignée uniquement quand le CONTENU est modifié (pas lors d'un simple épinglage). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function isAuthoredBy(User $user): bool
    {
        return $this->author !== null && $this->author->getId() === $user->getId();
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

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

    public function getColor(): ProjectNoteColor
    {
        return $this->color;
    }

    public function setColor(ProjectNoteColor $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): static
    {
        $this->pinned = $pinned;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Appelée explicitement par ProjectNoteService lors d'une vraie modification du texte. */
    public function markEdited(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}

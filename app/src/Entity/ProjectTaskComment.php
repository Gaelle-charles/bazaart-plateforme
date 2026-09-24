<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectTaskCommentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectTaskComment — commentaire signé sous une tâche (ADR-0037).
 *
 * Chaque commentaire garde son auteur et sa date : on sait toujours QUI a écrit
 * QUOI et QUAND. Seul l'auteur peut supprimer son commentaire
 * (règle portée par ProjectVoter::COMMENT_DELETE).
 */
#[ORM\Entity(repositoryClass: ProjectTaskCommentRepository::class)]
#[ORM\Table(name: 'project_task_comments')]
class ProjectTaskComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProjectTask $task;

    /** Auteur. SET NULL si le compte est supprimé : le texte reste, signé « Compte supprimé ». */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(type: 'text')]
    private string $content = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): ProjectTask
    {
        return $this->task;
    }

    public function setTask(ProjectTask $task): static
    {
        $this->task = $task;

        return $this;
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

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

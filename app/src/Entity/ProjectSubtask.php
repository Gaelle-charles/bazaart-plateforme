<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectSubtaskRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectSubtask — un élément de checklist à l'intérieur d'une tâche (ADR-0037).
 *
 * Exemple : tâche « Préparer la soirée d'ouverture » → sous-tâches
 * « Confirmer le DJ », « Imprimer les badges », « Commander les boissons ».
 *
 * Volontairement minimaliste (titre + coché) : si une sous-tâche a besoin d'une
 * échéance ou d'une personne dédiée, c'est qu'elle mérite d'être une vraie tâche.
 */
#[ORM\Entity(repositoryClass: ProjectSubtaskRepository::class)]
#[ORM\Table(name: 'project_subtasks')]
class ProjectSubtask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class, inversedBy: 'subtasks')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProjectTask $task;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title = '';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $done = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** Coche / décoche en tenant completedAt à jour. */
    public function setDone(bool $done): static
    {
        $this->done        = $done;
        $this->completedAt = $done ? new \DateTimeImmutable() : null;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use App\Repository\ProjectTaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectTask — une tâche de l'Espace projets (ADR-0037).
 *
 * Une tâche appartient (optionnellement) à un projet : une tâche « volante »
 * sans projet est autorisée pour les petites choses du quotidien
 * (« Rappeler le traiteur »), elle apparaît alors sous « Sans projet ».
 *
 * RELATIONS :
 *   - assignees   : ManyToMany vers User — une tâche peut être partagée à deux.
 *   - labels      : ManyToMany vers ProjectLabel (étiquettes libres : « Com », « Budget »…).
 *   - subtasks    : checklist interne (ProjectSubtask), supprimée avec la tâche.
 *   - comments    : fil de discussion signé (ProjectTaskComment).
 *   - attachments : fichiers Drive ou liens (ProjectAttachment).
 *
 * `position` sert à l'ordre manuel des cartes dans une colonne Kanban : quand on
 * glisse une carte, le front envoie l'ordre complet de la colonne et le serveur
 * renumérote (0, 1, 2…). Simple et robuste pour quelques centaines de tâches.
 */
#[ORM\Entity(repositoryClass: ProjectTaskRepository::class)]
#[ORM\Table(name: 'project_tasks')]
#[ORM\Index(name: 'idx_project_tasks_status', columns: ['status'])]
#[ORM\Index(name: 'idx_project_tasks_due_date', columns: ['due_date'])]
#[ORM\HasLifecycleCallbacks]
class ProjectTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectTaskStatus::class)]
    private ProjectTaskStatus $status = ProjectTaskStatus::Todo;

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectTaskPriority::class)]
    private ProjectTaskPriority $priority = ProjectTaskPriority::Medium;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    /** Ordre manuel dans la colonne Kanban (0 = en haut). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** Date de passage en « Terminé » (null tant que la tâche est ouverte). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * Personnes assignées. Table de jointure dédiée avec ON DELETE CASCADE des
     * deux côtés : supprimer une tâche ou un compte nettoie automatiquement la jointure.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'project_task_assignees')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $assignees;

    /** @var Collection<int, ProjectLabel> */
    #[ORM\ManyToMany(targetEntity: ProjectLabel::class)]
    #[ORM\JoinTable(name: 'project_task_labels')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'label_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $labels;

    /**
     * Checklist de la tâche. cascade persist/remove + orphanRemoval : la
     * sous-tâche n'existe pas sans sa tâche (composition au sens UML).
     *
     * @var Collection<int, ProjectSubtask>
     */
    #[ORM\OneToMany(targetEntity: ProjectSubtask::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $subtasks;

    /** @var Collection<int, ProjectTaskComment> */
    #[ORM\OneToMany(targetEntity: ProjectTaskComment::class, mappedBy: 'task')]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    /** @var Collection<int, ProjectAttachment> */
    #[ORM\OneToMany(targetEntity: ProjectAttachment::class, mappedBy: 'task')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $attachments;

    public function __construct()
    {
        $this->assignees   = new ArrayCollection();
        $this->labels      = new ArrayCollection();
        $this->subtasks    = new ArrayCollection();
        $this->comments    = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ─── Méthodes métier ─────────────────────────────────────────────────────

    /**
     * En retard = échéance strictement avant aujourd'hui ET tâche non terminée.
     * $today est injecté (plutôt que new DateTime() ici) pour que la règle soit
     * testable et que toute une page utilise la même date de référence.
     */
    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return $this->dueDate !== null
            && !$this->status->isDone()
            && $this->dueDate < $today;
    }

    public function isDone(): bool
    {
        return $this->status->isDone();
    }

    public function isAssignedTo(User $user): bool
    {
        foreach ($this->assignees as $assignee) {
            if ($assignee->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    /** Nombre de sous-tâches cochées (pour l'indicateur « 2/5 » des cartes). */
    public function countDoneSubtasks(): int
    {
        return $this->subtasks->filter(static fn (ProjectSubtask $s): bool => $s->isDone())->count();
    }

    // ─── Getters / setters ───────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): ProjectTaskStatus
    {
        return $this->status;
    }

    /**
     * Change le statut ET maintient completedAt cohérent :
     *   - passage à Terminé  → completedAt = maintenant (si pas déjà renseigné)
     *   - retour en arrière  → completedAt = null
     */
    public function setStatus(ProjectTaskStatus $status): static
    {
        if ($status->isDone() && $this->completedAt === null) {
            $this->completedAt = new \DateTimeImmutable();
        } elseif (!$status->isDone()) {
            $this->completedAt = null;
        }
        $this->status = $status;

        return $this;
    }

    public function getPriority(): ProjectTaskPriority
    {
        return $this->priority;
    }

    public function setPriority(ProjectTaskPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** @return Collection<int, User> */
    public function getAssignees(): Collection
    {
        return $this->assignees;
    }

    public function addAssignee(User $user): static
    {
        if (!$this->assignees->contains($user)) {
            $this->assignees->add($user);
        }

        return $this;
    }

    public function removeAssignee(User $user): static
    {
        $this->assignees->removeElement($user);

        return $this;
    }

    /** @return Collection<int, ProjectLabel> */
    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(ProjectLabel $label): static
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
        }

        return $this;
    }

    public function removeLabel(ProjectLabel $label): static
    {
        $this->labels->removeElement($label);

        return $this;
    }

    /** @return Collection<int, ProjectSubtask> */
    public function getSubtasks(): Collection
    {
        return $this->subtasks;
    }

    public function addSubtask(ProjectSubtask $subtask): static
    {
        if (!$this->subtasks->contains($subtask)) {
            $this->subtasks->add($subtask);
            $subtask->setTask($this);
        }

        return $this;
    }

    public function removeSubtask(ProjectSubtask $subtask): static
    {
        $this->subtasks->removeElement($subtask);

        return $this;
    }

    /** @return Collection<int, ProjectTaskComment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    /** @return Collection<int, ProjectAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }
}

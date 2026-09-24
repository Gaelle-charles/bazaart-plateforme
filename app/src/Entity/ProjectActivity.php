<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectActivityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectActivity — une ligne du journal d'activité de l'Espace projets (ADR-0037).
 *
 * « Gaëlle a déplacé « Réserver la salle » vers En cours » : chaque action
 * significative (création, changement de statut, assignation, commentaire,
 * pièce jointe…) laisse une trace datée et signée.
 *
 * POURQUOI stocker un message texte figé (`message`) plutôt que de le recalculer ?
 *   Le journal doit rester lisible même si la tâche est renommée ou supprimée
 *   ensuite : on « photographie » la phrase au moment de l'action.
 *   `action` (code technique) permet malgré tout de filtrer / compter
 *   (ex. l'étape d'onboarding « déplacer une tâche » = action task_moved).
 */
#[ORM\Entity(repositoryClass: ProjectActivityRepository::class)]
#[ORM\Table(name: 'project_activities')]
#[ORM\Index(name: 'idx_project_activities_created', columns: ['created_at'])]
class ProjectActivity
{
    // Codes d'action — constantes pour éviter les « chaînes magiques » dispersées.
    public const string PROJECT_CREATED   = 'project_created';
    public const string PROJECT_UPDATED   = 'project_updated';
    public const string TASK_CREATED      = 'task_created';
    public const string TASK_UPDATED      = 'task_updated';
    public const string TASK_MOVED        = 'task_moved';
    public const string TASK_ASSIGNED     = 'task_assigned';
    public const string TASK_COMPLETED    = 'task_completed';
    public const string TASK_DELETED      = 'task_deleted';
    public const string COMMENT_ADDED     = 'comment_added';
    public const string ATTACHMENT_ADDED  = 'attachment_added';
    public const string NOTE_ADDED        = 'note_added';
    public const string TASKS_IMPORTED    = 'tasks_imported';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** SET NULL : l'activité survit à la suppression de la tâche (le message reste lisible). */
    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProjectTask $task = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(type: 'string', length: 40)]
    private string $action = '';

    #[ORM\Column(type: 'string', length: 500)]
    private string $message = '';

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

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getTask(): ?ProjectTask
    {
        return $this->task;
    }

    public function setTask(?ProjectTask $task): static
    {
        $this->task = $task;

        return $this;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        // Garde-fou : la colonne fait 500 caractères. mb_substr évite de couper
        // un caractère accentué en plein milieu (UTF-8 multi-octets).
        $this->message = mb_substr($message, 0, 500);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

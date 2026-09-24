<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProjectStatus;
use App\Enum\ProjectTaskPriority;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Project — un projet de l'équipe Bazaart dans l'Espace projets (ADR-0037).
 *
 * Exemples : « Festival Bazaart 2027 », « Formation Studio — session d'hiver »,
 * « Candidature Afrique Créative ». Un projet regroupe des tâches (ProjectTask),
 * des notes (ProjectNote), des fichiers Drive (ProjectAttachment) et un journal
 * d'activité (ProjectActivity).
 *
 * CHOIX DE MODÉLISATION :
 *   - Les dates de début / d'échéance sont des `date_immutable` (jour sans heure) :
 *     pour un projet, l'heure n'a pas de sens et le type DATE évite les décalages
 *     de fuseau horaire à minuit.
 *   - `color` stocke un code hexadécimal choisi dans une palette fermée
 *     (ProjectService::COLORS) : la couleur sert de repère visuel partout
 *     (pastille sur les cartes Kanban, barre de la chronologie…).
 *   - La suppression d'un projet supprime ses tâches, pièces jointes et activités
 *     au niveau BDD (ON DELETE CASCADE sur les clés étrangères côté enfants).
 *     Les notes, elles, survivent (ON DELETE SET NULL) : une note écrite par
 *     une collègue ne doit pas disparaître silencieusement.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\Index(name: 'idx_projects_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Nom court du projet (affiché sur toutes les cartes). */
    #[ORM\Column(type: 'string', length: 150)]
    private string $name = '';

    /** Description libre : objectifs, contexte, partenaires… */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Couleur repère du projet, code hexadécimal (#RRGGBB). */
    #[ORM\Column(type: 'string', length: 7, options: ['default' => '#FFCB10'])]
    private string $color = '#FFCB10';

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectStatus::class)]
    private ProjectStatus $status = ProjectStatus::Active;

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectTaskPriority::class)]
    private ProjectTaskPriority $priority = ProjectTaskPriority::Medium;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    /**
     * Responsable du projet (la personne « pilote »).
     * SET NULL : si le compte disparaît, le projet reste mais sans responsable.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    /** Personne qui a créé le projet (traçabilité). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Dossier Google Drive rattaché au projet (ID Drive + nom mis en cache).
     * Sert de point de départ quand on parcourt le Drive depuis ce projet,
     * et de dossier de destination pour les téléversements.
     */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $driveFolderId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $driveFolderName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * Tâches du projet. Pas de cascade ORM : la suppression est gérée par la BDD
     * (ON DELETE CASCADE sur project_tasks.project_id), plus rapide qu'un
     * chargement de toutes les tâches en mémoire.
     *
     * @var Collection<int, ProjectTask>
     */
    #[ORM\OneToMany(targetEntity: ProjectTask::class, mappedBy: 'project')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tasks;

    public function __construct()
    {
        $this->tasks     = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** Met à jour automatiquement updatedAt à chaque modification persistée. */
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ─── Méthodes métier ─────────────────────────────────────────────────────

    /** Le projet est-il en retard (échéance passée et projet non terminé) ? */
    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return $this->dueDate !== null
            && $this->dueDate < $today
            && !in_array($this->status, [ProjectStatus::Completed, ProjectStatus::Archived], true);
    }

    public function isArchived(): bool
    {
        return $this->status === ProjectStatus::Archived;
    }

    // ─── Getters / setters ───────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getStatus(): ProjectStatus
    {
        return $this->status;
    }

    public function setStatus(ProjectStatus $status): static
    {
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

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

    public function getDriveFolderId(): ?string
    {
        return $this->driveFolderId;
    }

    public function getDriveFolderName(): ?string
    {
        return $this->driveFolderName;
    }

    /** Rattache (ou détache avec null) un dossier Drive au projet. */
    public function setDriveFolder(?string $folderId, ?string $folderName): static
    {
        $this->driveFolderId   = $folderId;
        $this->driveFolderName = $folderId !== null ? $folderName : null;

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

    /** @return Collection<int, ProjectTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProjectAttachmentSource;
use App\Enum\ProjectFileKind;
use App\Repository\ProjectAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectAttachment — pièce jointe d'un projet OU d'une tâche (ADR-0037).
 *
 * On ne stocke AUCUN fichier sur le serveur Bazaart : seulement une référence
 * (ID Drive, nom, type MIME, lien d'ouverture). Le Google Drive de l'équipe
 * (reinesdestempsmodernes@gmail.com) reste la source de vérité des documents.
 *
 * Exactement UN des deux parents est renseigné (project XOR task) — invariant
 * garanti par ProjectAttachmentService (les deux constructeurs nommés ci-dessous).
 */
#[ORM\Entity(repositoryClass: ProjectAttachmentRepository::class)]
#[ORM\Table(name: 'project_attachments')]
class ProjectAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ProjectTask $task = null;

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectAttachmentSource::class)]
    private ProjectAttachmentSource $source = ProjectAttachmentSource::Drive;

    /** ID du fichier dans Google Drive (null pour un simple lien). */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $driveFileId = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $mimeType = null;

    /** Lien d'ouverture (webViewLink Drive, ou URL saisie). Toujours http(s). */
    #[ORM\Column(type: 'string', length: 1024)]
    private string $url = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'added_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $addedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Famille du fichier (icône + libellé) déduite du type MIME. */
    public function getKind(): ProjectFileKind
    {
        if ($this->source === ProjectAttachmentSource::Link) {
            return ProjectFileKind::Link;
        }

        return ProjectFileKind::fromMimeType($this->mimeType);
    }

    public function isFolder(): bool
    {
        return $this->getKind() === ProjectFileKind::Folder;
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

    public function getSource(): ProjectAttachmentSource
    {
        return $this->source;
    }

    public function setSource(ProjectAttachmentSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getDriveFileId(): ?string
    {
        return $this->driveFileId;
    }

    public function setDriveFileId(?string $driveFileId): static
    {
        $this->driveFileId = $driveFileId;

        return $this;
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

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getAddedBy(): ?User
    {
        return $this->addedBy;
    }

    public function setAddedBy(?User $addedBy): static
    {
        $this->addedBy = $addedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

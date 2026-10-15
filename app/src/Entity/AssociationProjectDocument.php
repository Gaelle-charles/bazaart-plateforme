<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssociationProjectDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * AssociationProjectDocument : un document PDF joint à une fiche « Projets en cours »
 * (dossier de présentation, budget prévisionnel…), affiché dans « En pratique » (ADR-0039).
 *
 * Le fichier est stocké HORS du dossier public (AssociationProjectDocumentStorage) :
 * les partenaires le téléchargent par une adresse protégée par le jeton secret, et
 * seulement si la fiche est publiée. On ne garde ici que le nom de fichier aléatoire,
 * l'intitulé affiché et la taille (indiquée à côté du lien).
 */
#[ORM\Entity(repositoryClass: AssociationProjectDocumentRepository::class)]
#[ORM\Table(name: 'association_project_documents')]
#[ORM\Index(name: 'idx_association_project_documents_project_position', columns: ['project_id', 'position'])]
class AssociationProjectDocument
{
    /** Longueur maximale de l'intitulé affiché aux partenaires. */
    public const LABEL_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssociationProject::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssociationProject $project;

    /** Intitulé du lien (ex. « Dossier de présentation »). */
    #[ORM\Column(type: 'string', length: self::LABEL_MAX_LENGTH)]
    private string $label = '';

    /** Nom du fichier dans le dossier privé (aléatoire, non devinable). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $path = '';

    /** Taille en octets, affichée à côté du lien (« PDF, 2,3 Mo »). */
    #[ORM\Column(type: 'integer')]
    private int $sizeBytes = 0;

    /** Ordre d'affichage : ordre d'ajout. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(AssociationProject $project)
    {
        $this->project   = $project;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): AssociationProject
    {
        return $this->project;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

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
}

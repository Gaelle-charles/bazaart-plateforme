<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssociationProjectBlockRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * AssociationProjectBlock : un bloc de la présentation d'un projet de l'association
 * (ADR-0039) : ce que le partenaire lit en descendant sur la fiche.
 *
 * Un bloc = un titre facultatif + du texte + parfois UNE image (avec texte
 * alternatif et légende). Pour montrer trois images, l'équipe crée trois blocs.
 *
 * POURQUOI DES BLOCS plutôt qu'un éditeur de texte riche ?
 *   - Aucune dépendance à ajouter (pas d'éditeur WYSIWYG).
 *   - Aucun HTML stocké : le texte est affiché échappé, retours à la ligne conservés
 *     (même principe que le fil d'actualité), donc pas de risque d'injection de script.
 *   - L'équipe contrôle l'ordre texte / images avec des boutons Monter / Descendre.
 *
 * Règle métier (vérifiée dans le formulaire) : un bloc a au moins du texte OU une image.
 */
#[ORM\Entity(repositoryClass: AssociationProjectBlockRepository::class)]
#[ORM\Table(name: 'association_project_blocks')]
#[ORM\Index(name: 'idx_association_project_blocks_project_position', columns: ['project_id', 'position'])]
#[ORM\HasLifecycleCallbacks]
class AssociationProjectBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Projet auquel appartient le bloc.
     * ON DELETE CASCADE : supprimer un projet supprime ses blocs au niveau BDD.
     */
    #[ORM\ManyToOne(targetEntity: AssociationProject::class, inversedBy: 'blocks')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssociationProject $project;

    /** Ordre dans la présentation (plus petit = plus haut). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    /** Intertitre facultatif. */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $heading = null;

    /** Texte brut (sans HTML) ; les retours à la ligne sont conservés à l'affichage. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    /** Image du bloc : chemin RELATIF au dossier privé de stockage (ou null). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $imageAlt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageCaption = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageWidth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageHeight = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(AssociationProject $project)
    {
        $this->project   = $project;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** Met à jour automatiquement updatedAt à chaque modification persistée. */
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ─── Getters / setters ───────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): AssociationProject
    {
        return $this->project;
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

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    public function setHeading(?string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getImageAlt(): ?string
    {
        return $this->imageAlt;
    }

    public function setImageAlt(?string $imageAlt): static
    {
        $this->imageAlt = $imageAlt;

        return $this;
    }

    public function getImageCaption(): ?string
    {
        return $this->imageCaption;
    }

    public function setImageCaption(?string $imageCaption): static
    {
        $this->imageCaption = $imageCaption;

        return $this;
    }

    public function getImageWidth(): ?int
    {
        return $this->imageWidth;
    }

    public function getImageHeight(): ?int
    {
        return $this->imageHeight;
    }

    /** Dimensions mesurées au téléversement (null quand il n'y a pas d'image). */
    public function setImageSize(?int $width, ?int $height): static
    {
        $this->imageWidth  = $width;
        $this->imageHeight = $height;

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
}

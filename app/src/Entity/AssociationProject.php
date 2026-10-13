<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use App\Repository\AssociationProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * AssociationProject : un projet de l'association BazaArt présenté sur la page
 * « Projets en cours » envoyée aux partenaires (ADR-0039).
 *
 * À NE PAS CONFONDRE avec App\Entity\Project : ce dernier est l'outil interne de
 * gestion de projet de l'équipe (Espace projets, tâches, Kanban, ADR-0037).
 * Ici, c'est une FICHE DE PRÉSENTATION destinée à l'extérieur : aucune donnée
 * interne (budget, tâches) n'y figure.
 *
 * CHOIX DE MODÉLISATION :
 *   - La plupart des champs sont facultatifs (nullable) : les 9 fiches de départ
 *     n'ont qu'un titre, un type et un état ; l'équipe complète ensuite.
 *   - `slug` est calculé UNE FOIS à la création puis figé : un lien déjà envoyé
 *     à un partenaire reste valable même si le titre change.
 *   - `description` est limitée à 600 caractères au niveau BDD (VARCHAR(600) :
 *     PostgreSQL compte en caractères, pas en octets) ET dans le formulaire.
 *   - Le texte long de présentation vit dans les blocs (AssociationProjectBlock),
 *     pas ici : chaque bloc peut porter une image.
 *   - Les images sont stockées HORS du dossier public (cf. AssociationProjectImageStorage) :
 *     on ne garde ici que le chemin relatif + les dimensions (pour les attributs
 *     width/height des <img>, qui évitent que la page « saute » au chargement).
 *   - Aucun champ budget, volontairement (demande de l'association).
 */
#[ORM\Entity(repositoryClass: AssociationProjectRepository::class)]
#[ORM\Table(name: 'association_projects')]
#[ORM\UniqueConstraint(name: 'uniq_association_projects_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_association_projects_published_position', columns: ['is_published', 'position'])]
#[ORM\HasLifecycleCallbacks]
class AssociationProject
{
    /** Longueur maximale de la description (exigence de l'association). */
    public const DESCRIPTION_MAX_LENGTH = 600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Titre du projet, seul champ texte obligatoire. */
    #[ORM\Column(type: 'string', length: 150)]
    private string $title = '';

    /** Identifiant d'URL (ex. « an-ba-lanme-chapitre-2 »), unique et figé. */
    #[ORM\Column(type: 'string', length: 160)]
    private string $slug = '';

    #[ORM\Column(type: 'string', length: 30, enumType: AssociationProjectType::class)]
    private AssociationProjectType $type = AssociationProjectType::Residency;

    #[ORM\Column(type: 'string', length: 30, enumType: AssociationProjectState::class)]
    private AssociationProjectState $state = AssociationProjectState::Idea;

    /** Dates en texte libre (ex. « mai-juin 2027 ») : pas de type DATE, c'est voulu. */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $period = null;

    /** Lieux en texte libre. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $places = null;

    /** Artistes concernés (texte court). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $artists = null;

    /** Description courte, 600 caractères maximum. */
    #[ORM\Column(type: 'string', length: self::DESCRIPTION_MAX_LENGTH, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $confirmedPartners = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $prospectivePartners = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $solicitedFunders = null;

    /** « Ce qu'un partenaire peut apporter » : le bloc mis en avant sur la fiche. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $partnerContribution = null;

    /** Référente dans l'équipe : prénom et nom seulement (jamais d'e-mail personnel). */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $leadName = null;

    /** Visuel principal : chemin RELATIF au dossier privé de stockage (ou null). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imagePath = null;

    /** Texte alternatif du visuel (facultatif : à défaut, le titre est utilisé). */
    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $imageAlt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageWidth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageHeight = null;

    /** Ordre d'affichage : plus petit = plus à gauche dans le carrousel. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    /** Une fiche non publiée n'apparaît NULLE PART côté partenaires (page, fiche, API, images). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPublished = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * Blocs de présentation (texte et images), dans l'ordre d'affichage.
     * Pas de cascade ORM : la suppression des blocs est faite par la BDD
     * (ON DELETE CASCADE sur association_project_blocks.project_id) ; les fichiers
     * images des blocs sont supprimés par AssociationProjectService avant.
     *
     * EXTRA_LAZY : compter les blocs (liste du back-office) fait un simple COUNT
     * en base, sans charger les textes de tous les blocs.
     *
     * @var Collection<int, AssociationProjectBlock>
     */
    #[ORM\OneToMany(targetEntity: AssociationProjectBlock::class, mappedBy: 'project', fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $blocks;

    public function __construct()
    {
        $this->blocks    = new ArrayCollection();
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

    public function getType(): AssociationProjectType
    {
        return $this->type;
    }

    public function setType(AssociationProjectType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getState(): AssociationProjectState
    {
        return $this->state;
    }

    public function setState(AssociationProjectState $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getPeriod(): ?string
    {
        return $this->period;
    }

    public function setPeriod(?string $period): static
    {
        $this->period = $period;

        return $this;
    }

    public function getPlaces(): ?string
    {
        return $this->places;
    }

    public function setPlaces(?string $places): static
    {
        $this->places = $places;

        return $this;
    }

    public function getArtists(): ?string
    {
        return $this->artists;
    }

    public function setArtists(?string $artists): static
    {
        $this->artists = $artists;

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

    public function getConfirmedPartners(): ?string
    {
        return $this->confirmedPartners;
    }

    public function setConfirmedPartners(?string $confirmedPartners): static
    {
        $this->confirmedPartners = $confirmedPartners;

        return $this;
    }

    public function getProspectivePartners(): ?string
    {
        return $this->prospectivePartners;
    }

    public function setProspectivePartners(?string $prospectivePartners): static
    {
        $this->prospectivePartners = $prospectivePartners;

        return $this;
    }

    public function getSolicitedFunders(): ?string
    {
        return $this->solicitedFunders;
    }

    public function setSolicitedFunders(?string $solicitedFunders): static
    {
        $this->solicitedFunders = $solicitedFunders;

        return $this;
    }

    public function getPartnerContribution(): ?string
    {
        return $this->partnerContribution;
    }

    public function setPartnerContribution(?string $partnerContribution): static
    {
        $this->partnerContribution = $partnerContribution;

        return $this;
    }

    public function getLeadName(): ?string
    {
        return $this->leadName;
    }

    public function setLeadName(?string $leadName): static
    {
        $this->leadName = $leadName;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;

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

    /** @return Collection<int, AssociationProjectBlock> */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }
}

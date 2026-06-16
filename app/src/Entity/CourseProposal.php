<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CourseLevel;
use App\Enum\CourseProposalStatus;
use App\Repository\CourseProposalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * CourseProposal — proposition de formation soumise par un membre (Option B, ADR-0012).
 *
 * En V1, les formations (Course) sont créées par l'équipe Bazaart. Cette entité
 * permet à un membre de SOUMETTRE une idée de formation : elle est stockée avec le
 * statut « en attente », puis un admin l'accepte ou la refuse (avec un motif).
 * L'auteur est notifié par email à la décision.
 *
 * Ce n'est PAS une Course : pas de modules/leçons/Stripe ici. Si l'admin accepte,
 * il crée ensuite la vraie Course à partir des informations de la proposition.
 */
#[ORM\Entity(repositoryClass: CourseProposalRepository::class)]
#[ORM\Table(name: 'course_proposals')]
#[ORM\HasLifecycleCallbacks]
class CourseProposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Auteur de la proposition ─────────────────────────────────────────────

    /**
     * Membre qui propose la formation.
     * onDelete: 'CASCADE' → si le compte est supprimé (RGPD), ses propositions le sont aussi.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $proposedBy;

    // ─── Contenu saisi par l'utilisateur ──────────────────────────────────────

    /** Titre de la formation proposée. */
    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /** Description / programme envisagé (texte long). */
    #[ORM\Column(type: 'text')]
    private string $description;

    /** Public visé (ex : « artistes émergents », « porteurs de projet »). */
    #[ORM\Column(type: 'text')]
    private string $targetAudience;

    /** Expérience de l'auteur sur le sujet (mini bio / légitimité). */
    #[ORM\Column(type: 'text')]
    private string $experience;

    /**
     * Niveau visé (optionnel).
     * Réutilise l'enum CourseLevel des formations pour cohérence.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: CourseLevel::class, nullable: true)]
    private ?CourseLevel $level = null;

    /** Lien portfolio / exemple de travail (optionnel). */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $portfolioUrl = null;

    // ─── Suivi de la revue admin ──────────────────────────────────────────────

    /** Statut courant (en attente / acceptée / refusée). */
    #[ORM\Column(type: 'string', length: 20, enumType: CourseProposalStatus::class)]
    private CourseProposalStatus $status = CourseProposalStatus::Pending;

    /** Note de l'admin (motif d'acceptation/refus), affichée à l'auteur. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    /**
     * Admin ayant traité la proposition.
     * onDelete: 'SET NULL' → on conserve la proposition même si le compte admin disparaît.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    /** Date de traitement (null tant que la proposition est en attente). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reviewedAt = null;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        // isset() sur une propriété typée non initialisée retourne false → on ne fixe
        // la date que si elle ne l'a pas déjà été (idempotence).
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTime();
        }
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProposedBy(): User
    {
        return $this->proposedBy;
    }

    public function setProposedBy(User $proposedBy): static
    {
        $this->proposedBy = $proposedBy;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getTargetAudience(): string
    {
        return $this->targetAudience;
    }

    public function setTargetAudience(string $targetAudience): static
    {
        $this->targetAudience = $targetAudience;
        return $this;
    }

    public function getExperience(): string
    {
        return $this->experience;
    }

    public function setExperience(string $experience): static
    {
        $this->experience = $experience;
        return $this;
    }

    public function getLevel(): ?CourseLevel
    {
        return $this->level;
    }

    public function setLevel(?CourseLevel $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function getPortfolioUrl(): ?string
    {
        return $this->portfolioUrl;
    }

    public function setPortfolioUrl(?string $portfolioUrl): static
    {
        $this->portfolioUrl = $portfolioUrl;
        return $this;
    }

    public function getStatus(): CourseProposalStatus
    {
        return $this->status;
    }

    public function setStatus(CourseProposalStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): static
    {
        $this->adminNote = $adminNote;
        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getReviewedAt(): ?\DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeInterface $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}

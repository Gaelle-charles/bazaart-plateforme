<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScrapedResourceStatus;
use App\Repository\ScrapedResourceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ScrapedResource — Opportunité collectée automatiquement par le scraper.
 *
 * Cycle de vie :
 *   1. Le scraper insère une ligne avec status = 'pending' (À vérifier)
 *   2. L'admin consulte la page "Scraping" et clique "Vérifier"
 *   3. Le status passe à 'verified' et une Resource publiée est créée automatiquement
 *
 * La déduplication se fait sur l'URL : si l'URL existe déjà, on ne réinsère pas.
 */
#[ORM\Entity(repositoryClass: ScrapedResourceRepository::class)]
#[ORM\Table(name: 'scraped_resources')]
#[ORM\HasLifecycleCallbacks]
class ScrapedResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Titre de l'opportunité */
    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /** Description courte (peut être vide) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * URL source — sert aussi de clé de déduplication.
     * Si une opportunité avec la même URL est déjà en base, on ne la réinsère pas.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true, unique: true)]
    private ?string $url = null;

    /** Type de ressource tel que défini par le scraper (ex: "bourse", "résidence") */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $type = null;

    /** Nom du site source (ex: "cnap.fr", "cnm.fr") */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $sourceSite = null;

    /** Date limite sous forme de texte (format variable selon le site scraped) */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $deadline = null;

    /** Score de pertinence Afrodiaspora calculé par AfrodiasporaRelevanceScorer (0 à 5) */
    #[ORM\Column(type: 'integer')]
    private int $relevanceScore = 0;

    /** URLs de documents PDF séparées par des virgules */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $documents = null;

    /**
     * Statut de validation — voir App\Enum\ScrapedResourceStatus.
     * Stocké en BDD comme 'pending' ou 'verified' (string backed value).
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ScrapedResourceStatus::class)]
    private ScrapedResourceStatus $status = ScrapedResourceStatus::Pending;

    /** Date à laquelle le scraper a collecté cette opportunité */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $scrapedAt;

    // ── Lifecycle Callbacks ──────────────────────────────────────────────────

    #[ORM\PrePersist]
    public function initScrapedAt(): void
    {
        $this->scrapedAt = new \DateTime();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): static { $this->url = $url; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type; return $this; }

    public function getSourceSite(): ?string { return $this->sourceSite; }
    public function setSourceSite(?string $sourceSite): static { $this->sourceSite = $sourceSite; return $this; }

    public function getDeadline(): ?string { return $this->deadline; }
    public function setDeadline(?string $deadline): static { $this->deadline = $deadline; return $this; }

    public function getRelevanceScore(): int { return $this->relevanceScore; }
    public function setRelevanceScore(int $score): static { $this->relevanceScore = $score; return $this; }

    public function getDocuments(): ?string { return $this->documents; }
    public function setDocuments(?string $documents): static { $this->documents = $documents; return $this; }

    public function getStatus(): ScrapedResourceStatus { return $this->status; }
    public function setStatus(ScrapedResourceStatus $status): static { $this->status = $status; return $this; }

    public function isPending(): bool { return $this->status === ScrapedResourceStatus::Pending; }
    public function isVerified(): bool { return $this->status === ScrapedResourceStatus::Verified; }

    public function getScrapedAt(): \DateTimeInterface { return $this->scrapedAt; }
}

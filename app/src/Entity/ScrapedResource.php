<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExperienceLevel;
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

    /**
     * Date de clôture parsée depuis le champ `deadline` (string lisible).
     *
     * DUALITÉ INTENTIONNELLE — ces deux champs coexistent avec des rôles distincts :
     *   - deadline (string)        : affichage humain, format variable selon la source
     *                                ex: "31 mai 2026", "31/05/2026", "2026-05-31"
     *   - deadlineDate (datetime)  : logique métier uniquement — archivage automatique
     *                                et tri par date. Parsé depuis deadline à la sauvegarde
     *                                par ScrapedResourceListener (prePersist/preUpdate).
     *
     * Null si deadline est vide, non parseable, ou non renseignée.
     * Ne PAS modifier ce champ directement — il est géré par le listener.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deadlineDate = null;

    /** Score de pertinence Afrodiaspora calculé par AfrodiasporaRelevanceScorer (0 à 5) */
    #[ORM\Column(type: 'integer')]
    private int $relevanceScore = 0;

    /**
     * Disciplines artistiques concernées par cette opportunité.
     * Valeur libre (ex: "Musique, Arts plastiques", "Résidences", "Toutes disciplines").
     * Rempli par le scraper (AbstractRssScraper::getDisciplines(), GenericScraper, LlmExtractorService).
     * Null si non renseigné par la source.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $disciplines = null;

    /** URLs de documents PDF séparées par des virgules */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $documents = null;

    /**
     * Statut de validation — voir App\Enum\ScrapedResourceStatus.
     * Stocké en BDD comme 'pending' ou 'verified' (string backed value).
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ScrapedResourceStatus::class)]
    private ScrapedResourceStatus $status = ScrapedResourceStatus::Pending;

    /**
     * Date de publication d'origine de la ressource.
     *
     * SÉMANTIQUE : date à laquelle l'annonce a été publiée sur la source
     * (flux RSS <pubDate>, Atom <published> ou <updated>).
     *
     * DISTINCTION AVEC LES AUTRES CHAMPS TEMPORELS :
     *   - publishedAt  : date de publication de l'annonce SUR LA SOURCE
     *                    (ex : "cet appel à projets a été publié le 01/06/2026")
     *   - deadlineDate : date LIMITE de candidature, extraite du contenu textuel
     *                    (ex : "les dossiers doivent être déposés avant le 30/09/2026")
     *   - scrapedAt    : date à laquelle le BOT a collecté l'opportunité
     *
     * Null pour les opportunités issues de scrapers CSS ou LLM qui n'ont pas de
     * notion de date de publication structurée (pas de flux RSS à parser).
     *
     * Type datetime_immutable (comme deadlineDate) — cohérent avec le reste des
     * champs temporels de l'entité ; le DTO fournit un \DateTimeImmutable.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** Date à laquelle le scraper a collecté cette opportunité */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $scrapedAt;

    // ─── Champs ADR-0018 : candidature, financement, description enrichie ────
    // Ces trois champs sont remplis par le LLM (LlmExtractorService /
    // OpportunityEnrichmentService) et propagés vers Resource lors de la vérif.
    // Tous nullable : migration non-destructive (existantes restent à NULL).

    /**
     * Modalités de candidature extraites par le LLM.
     * Ex : "Déposez votre dossier avant le 30/06 via le formulaire en ligne.
     *       Joindre CV artistique, portfolio (10 images max) et note d'intention."
     *
     * Stocké en TEXT : peut être long (plusieurs paragraphes d'instructions).
     * Null si le LLM n'a pas trouvé d'information sur les modalités de candidature.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $howToApply = null;

    /**
     * Montant du financement ou de la dotation (valeur lisible humaine).
     * Ex : "5 000 €", "jusqu'à 10 000 €", "10 000 USD", "non précisé".
     *
     * String courte (≤ 255 chars) : c'est une information de synthèse, pas un chiffre
     * calculable. On stocke la forme lisible retournée par le LLM.
     * Null si le LLM n'a pas trouvé de montant.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $fundingAmount = null;

    /**
     * Nature du financement (type de soutien proposé).
     * Ex : "Bourse en argent", "Prise en charge des frais de résidence",
     *       "Prix (non monétaire)", "Avance sur production".
     *
     * String courte (≤ 255 chars). Null si non précisé ou indéterminable.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $fundingType = null;

    // ─── Champs ADR-0019 : lien de candidature + logo ────────────────────────
    // Même sémantique que sur Resource. Remplis par LogoFetcherService
    // (logoUrl, sans LLM) et LlmExtractorService / OpportunityEnrichmentService
    // (applicationUrl via LLM + garde anti-hallucination).
    // Tous nullable : migration non-destructive.

    /**
     * URL de candidature directe extraite par le LLM parmi les liens de la page.
     * Distincte de l'URL source ($url) qui pointe vers la page de présentation.
     *
     * Garde anti-hallucination : l'URL doit être présente dans les liens réels
     * de la page ; toute URL inventée par le LLM est rejetée (null).
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $applicationUrl = null;

    /**
     * URL du logo de l'organisme, récupérée par parsing HTML (sans LLM).
     * Domaine cible : applicationUrl si présent, sinon URL source.
     * Null si aucun logo trouvé ou si le fetch échoue.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $logoUrl = null;

    // ─── Champs ADR-0016 Lot 1 : localisation fine + niveau d'expérience ─────
    // Ces champs sont remplis par le LLM enrichi (LlmExtractorService) et
    // propagés vers l'entité Resource lors de la vérification admin.
    // Tous nullable : les ScrapedResource issues de l'ancien scraper (avant ADR-0016)
    // n'ont pas ces informations — la migration est donc non-destructive.

    /**
     * Ville où se déroule l'opportunité, déduite par le LLM (ex : "Paris", "Bruxelles").
     *
     * Rempli uniquement par LlmExtractorService — les scrapers RSS ne renseignent
     * pas ce champ (ils ne visitent pas la page de détail).
     */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $city = null;

    /**
     * Pays de l'opportunité, déduit par le LLM (nom en clair, ex : "France").
     *
     * Même source que $city : uniquement le LLM extracteur.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $country = null;

    /**
     * Niveau d'expérience requis, extrait par le LLM.
     *
     * Valeurs possibles : beginner / intermediate / experienced (backed values).
     * NULL = tous niveaux, ou non précisé dans la page source.
     *
     * Ce champ est copié tel quel vers Resource::$experienceLevel lors de la
     * publication admin (AdminController::verifyScrapedOpportunity).
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ExperienceLevel::class, nullable: true)]
    private ?ExperienceLevel $experienceLevel = null;

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

    public function getDeadlineDate(): ?\DateTimeImmutable { return $this->deadlineDate; }
    public function setDeadlineDate(?\DateTimeImmutable $deadlineDate): static { $this->deadlineDate = $deadlineDate; return $this; }

    public function getRelevanceScore(): int { return $this->relevanceScore; }
    public function setRelevanceScore(int $score): static { $this->relevanceScore = $score; return $this; }

    public function getDocuments(): ?string { return $this->documents; }
    public function setDocuments(?string $documents): static { $this->documents = $documents; return $this; }

    public function getDisciplines(): ?string { return $this->disciplines; }
    public function setDisciplines(?string $disciplines): static { $this->disciplines = $disciplines; return $this; }

    public function getStatus(): ScrapedResourceStatus { return $this->status; }
    public function setStatus(ScrapedResourceStatus $status): static { $this->status = $status; return $this; }

    public function isPending(): bool  { return $this->status === ScrapedResourceStatus::Pending; }
    public function isVerified(): bool { return $this->status === ScrapedResourceStatus::Verified; }

    /** Vrai si l'opportunité a été rejetée par un admin (hors sujet ou doublon). */
    public function isRejected(): bool { return $this->status === ScrapedResourceStatus::Rejected; }

    /**
     * Vrai si l'opportunité est archivée.
     *
     * L'archivage est automatique (deadline passée, détectée par ScrapedResourceRepository::archiveExpired())
     * ou manuel (futur bouton "Archiver" dans l'interface admin).
     *
     * Pourquoi un statut distinct de Rejected ?
     *   Une opportunité archivée était valide — elle était bien dans le scope Bazaart.
     *   La conserver avec un statut distinct permet à l'admin de distinguer
     *   "hors sujet" (Rejected) de "pertinente mais expirée" (Archived) dans les stats.
     */
    public function isArchived(): bool { return $this->status === ScrapedResourceStatus::Archived; }

    /**
     * Retourne la date de publication originale de l'annonce (source externe).
     * Null pour les opportunités CSS/LLM qui n'ont pas de date de publication structurée.
     */
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    /**
     * Définit la date de publication originale (issue du flux RSS/Atom).
     * Ne pas confondre avec scrapedAt (quand le bot a collecté) ou deadlineDate (limite).
     */
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getScrapedAt(): \DateTimeInterface { return $this->scrapedAt; }

    /**
     * Permet de rafraîchir la date de scraping lors d'une réactivation.
     *
     * Utilisé par ScrapeOpportunitiesCommand quand une opportunité archivée est retrouvée
     * sur le site scrappé : on remet scrapedAt à "maintenant" pour que la protection
     * 48h de archiveExpired() s'applique (empêche un ré-archivage immédiat).
     */
    public function setScrapedAt(\DateTimeInterface $scrapedAt): static
    {
        $this->scrapedAt = $scrapedAt;
        return $this;
    }

    // ─── Getters / Setters ADR-0018 ──────────────────────────────────────────

    /**
     * Retourne les modalités de candidature extraites par le LLM.
     * Null si l'information n'était pas disponible sur la page source.
     */
    public function getHowToApply(): ?string
    {
        return $this->howToApply;
    }

    /**
     * Définit les modalités de candidature.
     * Passer null efface l'information (opportunité sans instructions de candidature).
     */
    public function setHowToApply(?string $howToApply): static
    {
        $this->howToApply = $howToApply;
        return $this;
    }

    /**
     * Retourne le montant du financement sous forme lisible.
     * Ex : "5 000 €", "jusqu'à 10 000 €".
     * Null si le montant n'était pas mentionné sur la page.
     */
    public function getFundingAmount(): ?string
    {
        return $this->fundingAmount;
    }

    /**
     * Définit le montant du financement (forme lisible issue du LLM).
     */
    public function setFundingAmount(?string $fundingAmount): static
    {
        $this->fundingAmount = $fundingAmount;
        return $this;
    }

    /**
     * Retourne la nature du financement.
     * Ex : "Bourse en argent", "Prise en charge des frais".
     * Null si non précisé.
     */
    public function getFundingType(): ?string
    {
        return $this->fundingType;
    }

    /**
     * Définit la nature du financement.
     */
    public function setFundingType(?string $fundingType): static
    {
        $this->fundingType = $fundingType;
        return $this;
    }

    // ─── Getters / Setters ADR-0019 ──────────────────────────────────────────

    /**
     * Retourne l'URL de candidature directe, ou null si non trouvée / rejetée.
     * Distincte de getUrl() (page source de l'opportunité).
     */
    public function getApplicationUrl(): ?string
    {
        return $this->applicationUrl;
    }

    /**
     * Définit l'URL de candidature directe.
     * Null = aucun lien trouvé ou garde anti-hallucination déclenchée.
     */
    public function setApplicationUrl(?string $applicationUrl): static
    {
        $this->applicationUrl = $applicationUrl;
        return $this;
    }

    /**
     * Retourne l'URL du logo, ou null si le site ne fournit pas de logo récupérable.
     */
    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    /**
     * Définit l'URL du logo de l'organisme.
     */
    public function setLogoUrl(?string $logoUrl): static
    {
        $this->logoUrl = $logoUrl;
        return $this;
    }

    // ─── Getters / Setters ADR-0016 Lot 1 ────────────────────────────────────

    /**
     * Retourne la ville extraite par le LLM, ou null si non détectée.
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * Définit la ville de l'opportunité (rempli par LlmExtractorService).
     */
    public function setCity(?string $city): static
    {
        $this->city = $city;
        return $this;
    }

    /**
     * Retourne le pays extrait par le LLM (nom en clair), ou null.
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * Définit le pays de l'opportunité (rempli par LlmExtractorService).
     */
    public function setCountry(?string $country): static
    {
        $this->country = $country;
        return $this;
    }

    /**
     * Retourne le niveau d'expérience extrait par le LLM, ou null si tous niveaux.
     */
    public function getExperienceLevel(): ?ExperienceLevel
    {
        return $this->experienceLevel;
    }

    /**
     * Définit le niveau d'expérience requis (rempli par LlmExtractorService).
     * Null = tous niveaux (aucune restriction).
     */
    public function setExperienceLevel(?ExperienceLevel $experienceLevel): static
    {
        $this->experienceLevel = $experienceLevel;
        return $this;
    }
}

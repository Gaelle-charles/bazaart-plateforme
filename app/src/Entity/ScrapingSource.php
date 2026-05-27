<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScrapingRunStatus;
use App\Enum\ScrapingSourceType;
use App\Repository\ScrapingSourceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ScrapingSource — Source de scraping gérée depuis l'interface admin.
 *
 * Chaque ligne de cette table représente un site web (ou flux RSS) que le
 * scraper doit parcourir pour collecter des opportunités culturelles.
 *
 * Cycle de vie d'une source :
 *   1. Créée via app:seed-scraping-sources (sources existantes)
 *      ou via le formulaire /admin/scraping-sources (nouvelles sources admin)
 *   2. ScrapeOpportunitiesCommand l'interroge à chaque run
 *   3. markRunSuccess() / markRunError() sont appelés après chaque tentative
 *   4. L'admin peut désactiver (actif = false) ou archiver une source
 *
 * Relation avec le code PHP :
 *   - scraperSlug = null  → GenericScraper prend le relais (RSS ou HTML_LLM)
 *   - scraperSlug = 'cnap' → ScraperRegistry retourne CnapScraper
 *   - scraperSlug = 'xyz'  → erreur visible en admin si la classe n'existe pas
 */
#[ORM\Entity(repositoryClass: ScrapingSourceRepository::class)]
#[ORM\Table(name: 'scraping_sources')]
#[ORM\HasLifecycleCallbacks]
class ScrapingSource
{
    /**
     * Identifiant auto-incrémenté par PostgreSQL.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Libellé lisible de la source (ex: "CNM - Centre National de la Musique").
     * Affiché dans la liste admin et dans les logs du scraper.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $nom;

    /**
     * URL du flux RSS ou de la page HTML à scraper.
     * Clé de déduplication : pas deux sources avec la même URL.
     */
    #[ORM\Column(type: 'string', length: 500, unique: true)]
    private string $url;

    /**
     * Type de scraping — voir App\Enum\ScrapingSourceType.
     * Détermine si on parse du XML, on appelle le LLM, ou on utilise DomCrawler.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ScrapingSourceType::class)]
    private ScrapingSourceType $type;

    /**
     * Slug de la classe PHP custom (ex: "cnap", "on-the-move").
     * Si null → GenericScraper prend le relais.
     * Si renseigné mais classe introuvable → erreur visible en admin (pas d'exception silencieuse).
     *
     * La correspondance slug → classe est gérée par ScraperRegistry.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $scraperSlug = null;

    /**
     * Discipline artistique principale de la source (ex: "Musique", "Arts plastiques").
     * Utilisé dans GenericScraper pour enrichir le champ disciplines du DTO.
     */
    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $disciplinePrincipale = null;

    /**
     * Zone géographique de la source (ex: "France", "Europe", "Monde").
     * Affiché dans la liste admin pour filtrer visuellement.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $paysZone = null;

    /**
     * Indique si cette source est un agrégateur de ressources culturelles.
     *
     * Un agrégateur est un site qui liste et recense d'AUTRES organismes culturels,
     * et pas uniquement ses propres appels à projets. Ces sites sont utiles car
     * ils permettent de découvrir de nouvelles sources à ajouter au système.
     *
     * Exemples d'agrégateurs :
     *   - on-the-move.org     → liste des centaines d'organismes partenaires mondiaux
     *   - resartis.org        → réseau mondial de résidences, liste d'institutions membres
     *   - eacea.ec.europa.eu  → liste des programmes UE et partenaires éligibles
     *
     * Exemples de NON-agrégateurs (sources directes) :
     *   - cnap.fr, cnm.fr     → publient leurs propres opportunités uniquement
     *   - prohelvetia.ch      → publie ses propres bourses uniquement
     *
     * Utilisé par app:discover-sources pour cibler quelles pages analyser.
     * N'a aucun impact sur app:scrape-opportunities (isolation totale).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $estAgregateur = false;

    /**
     * Si false, la source est ignorée par ScrapeOpportunitiesCommand.
     * L'admin peut désactiver une source temporairement (site en maintenance, quota épuisé...)
     * sans la supprimer.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    /**
     * Date et heure du dernier run (succès ou erreur).
     * Null si la source n'a jamais été scrapée.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $derniereExecution = null;

    /**
     * Nombre d'opportunités trouvées lors du dernier run.
     * Remis à 0 à chaque run (pas cumulatif).
     */
    #[ORM\Column(type: 'integer')]
    private int $nbItemsDernierRun = 0;

    /**
     * Statut du dernier run — voir App\Enum\ScrapingRunStatus.
     * Affiché sous forme de badge coloré dans l'interface admin.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ScrapingRunStatus::class)]
    private ScrapingRunStatus $statutDernierRun = ScrapingRunStatus::NeverRun;

    /**
     * Message d'erreur du dernier run (null si pas d'erreur).
     * Affiché en tooltip dans l'admin quand statutDernierRun = Error.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $messageErreur = null;

    /**
     * Date de création — remplie automatiquement par initCreatedAt() via PrePersist.
     * Initialisé par le callback #[ORM\PrePersist] — jamais null après persist()
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Date de dernière modification — remplie automatiquement par touchUpdatedAt() via PreUpdate.
     * Null tant que l'entité n'a pas encore été modifiée après sa création.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    // ── Lifecycle Callbacks ──────────────────────────────────────────────────

    /**
     * Définit createdAt à la création de l'entité (INSERT en BDD).
     * Le hook PrePersist est déclenché automatiquement par Doctrine
     * juste avant d'exécuter la requête INSERT SQL.
     */
    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTime();
    }

    /**
     * Met à jour updatedAt à chaque modification de l'entité (UPDATE en BDD).
     * Le hook PreUpdate est déclenché automatiquement par Doctrine
     * juste avant d'exécuter la requête UPDATE SQL.
     */
    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ── Méthodes utilitaires ────────────────────────────────────────────────

    /**
     * Indique si cette source a un scraper PHP custom associé.
     * Si true → ScraperRegistry sera consulté pour trouver la classe.
     * Si false → GenericScraper prend le relais.
     */
    public function hasCustomScraper(): bool
    {
        return $this->scraperSlug !== null;
    }

    /**
     * Enregistre un run réussi sur cette source.
     *
     * Met à jour :
     *   - derniereExecution : maintenant (DateTime)
     *   - nbItemsDernierRun : nombre d'opportunités trouvées
     *   - statutDernierRun  : Success
     *   - messageErreur     : effacé (null — plus d'erreur)
     *
     * @param int $nbItems Nombre d'opportunités trouvées lors de ce run
     */
    public function markRunSuccess(int $nbItems): void
    {
        $this->derniereExecution  = new \DateTime();
        $this->nbItemsDernierRun  = $nbItems;
        $this->statutDernierRun   = ScrapingRunStatus::Success;
        $this->messageErreur      = null;
    }

    /**
     * Enregistre un run en erreur sur cette source.
     *
     * Met à jour :
     *   - derniereExecution : maintenant (DateTime)
     *   - statutDernierRun  : Error
     *   - messageErreur     : message d'erreur visible dans l'admin
     *
     * Note : nbItemsDernierRun n'est PAS remis à 0 — on garde le chiffre
     * du dernier run réussi pour référence.
     *
     * @param string $message Message d'erreur (ex: "Slug 'xyz' inconnu", "HTTP 503")
     */
    public function markRunError(string $message): void
    {
        $this->derniereExecution = new \DateTime();
        $this->statutDernierRun  = ScrapingRunStatus::Error;
        $this->messageErreur     = $message;
    }

    // ── Getters / Setters ───────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
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

    public function getType(): ScrapingSourceType
    {
        return $this->type;
    }

    public function setType(ScrapingSourceType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getScraperSlug(): ?string
    {
        return $this->scraperSlug;
    }

    public function setScraperSlug(?string $scraperSlug): static
    {
        $this->scraperSlug = $scraperSlug;
        return $this;
    }

    public function getDisciplinePrincipale(): ?string
    {
        return $this->disciplinePrincipale;
    }

    public function setDisciplinePrincipale(?string $disciplinePrincipale): static
    {
        $this->disciplinePrincipale = $disciplinePrincipale;
        return $this;
    }

    public function getPaysZone(): ?string
    {
        return $this->paysZone;
    }

    public function setPaysZone(?string $paysZone): static
    {
        $this->paysZone = $paysZone;
        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
        return $this;
    }

    /**
     * Indique si cette source est un agrégateur (liste d'autres organismes).
     *
     * Les agrégateurs sont analysés par app:discover-sources pour trouver
     * de nouvelles sources à ajouter. Cette méthode suit la convention Symfony
     * is* pour les booléens.
     */
    public function isEstAgregateur(): bool
    {
        return $this->estAgregateur;
    }

    /**
     * Définit si cette source est un agrégateur.
     *
     * Retourne $this pour permettre le chaînage :
     *   $source->setEstAgregateur(true)->setActif(false);
     */
    public function setEstAgregateur(bool $estAgregateur): static
    {
        $this->estAgregateur = $estAgregateur;
        return $this;
    }

    public function getDerniereExecution(): ?\DateTimeInterface
    {
        return $this->derniereExecution;
    }

    public function getNbItemsDernierRun(): int
    {
        return $this->nbItemsDernierRun;
    }

    public function getStatutDernierRun(): ScrapingRunStatus
    {
        return $this->statutDernierRun;
    }

    public function getMessageErreur(): ?string
    {
        return $this->messageErreur;
    }

    public function setMessageErreur(?string $messageErreur): static
    {
        $this->messageErreur = $messageErreur;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}

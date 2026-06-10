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

    // ── Nouveaux champs WS1 (pipeline multi-méthodes) ───────────────────────

    /**
     * URL du flux RSS/Atom de la source. Distincte de `url` qui reste la page humaine
     * de référence (ex: page d'accueil du site).
     *
     * Ce champ est renseigné :
     *   - manuellement par l'admin depuis le formulaire d'édition,
     *   - ou automatiquement par la future commande app:detect-feeds (WS2).
     *
     * Null tant qu'aucun flux n'est connu pour cette source.
     * Uniquement pertinent quand type = ScrapingSourceType::RSS,
     * mais on ne pose pas de contrainte SQL car une source peut changer de type.
     *
     * Longueur 500 : cohérente avec `url` (certains flux ont des URLs longues
     * avec des tokens de tracking).
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $feedUrl = null;

    /**
     * Date/heure du dernier fetch RÉUSSI de cette source.
     *
     * Différent de `derniereExecution` qui enregistre TOUS les runs (succès + erreur).
     * Ce champ ne progresse que lors des runs sans erreur, ce qui permet à
     * l'orchestrateur (WS3) de savoir depuis combien de temps la source n'a pas
     * fourni de données exploitables.
     *
     * Mis à jour par l'orchestrateur du pipeline (WS3) — ne pas toucher ici.
     * Null si la source n'a jamais été fetchée avec succès.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastSuccessfulFetch = null;

    /**
     * Compteur d'échecs consécutifs depuis le dernier succès.
     *
     * Comportement attendu (géré côté service, pas ici) :
     *   - Incrémenté de 1 à chaque run en erreur.
     *   - Remis à 0 dès qu'un run réussit.
     *   - Quand il atteint 5 : l'orchestrateur (WS3) désactivera automatiquement
     *     la source (actif = false) et notifiera l'admin.
     *
     * Défaut : 0 (une nouvelle source n'a encore jamais échoué).
     * Colonne avec `default: 0` pour éviter un NOT NULL sans valeur par défaut.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $consecutiveFailures = 0;

    /**
     * Indique si les ressources collectées depuis cette source peuvent être publiées
     * automatiquement sans passer par la file de modération.
     *
     * ⚠️ CHAMP PRÉPARATOIRE — NON EXPLOITÉ EN V1.
     * En V1, toute ressource collectée part systématiquement en file de modération
     * (statut `pending`), quelle que soit la valeur de ce champ.
     * Ce champ existe pour préparer une future option de confiance par source ;
     * ne pas l'utiliser dans aucune logique de V1.
     *
     * Défaut : false (publication manuelle = comportement prudent par défaut).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $autoPublish = false;

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
     * Seuil d'échecs consécutifs déclenchant l'auto-désactivation de la source.
     *
     * Quand consecutiveFailures atteint cette valeur, hasReachedFailureThreshold()
     * retourne true. C'est la commande (Command) qui décide alors de désactiver la source
     * et de logger le warning — l'entité expose uniquement l'état, elle ne prend pas
     * de décision de désactivation (elle ne connaît pas le logger ni le contexte métier).
     *
     * Valeur centralisée ici pour éviter la divergence entre les pipelines RSS et scrape.
     * Auparavant chaque commande avait sa propre constante AUTO_DISABLE_THRESHOLD = 5.
     */
    public const AUTO_DISABLE_THRESHOLD = 5;

    /**
     * Enregistre un run réussi sur cette source.
     *
     * Met à jour TOUS les champs de santé + les champs de stats :
     *   - derniereExecution      : maintenant (DateTime)
     *   - nbItemsDernierRun      : nombre d'opportunités trouvées
     *   - statutDernierRun       : Success
     *   - messageErreur          : effacé (null — plus d'erreur)
     *   - lastSuccessfulFetch    : maintenant (DateTime) — le dernier fetch réussi
     *   - consecutiveFailures    : remis à 0 — la chaîne d'échecs est brisée
     *
     * Pourquoi centraliser lastSuccessfulFetch et consecutiveFailures ici ?
     * Avant cette version, les deux commandes (ReadFeedsCommand, ScrapeOpportunitiesCommand)
     * appelaient setLastSuccessfulFetch() / resetConsecutiveFailures() SÉPARÉMENT, ce qui
     * créait un risque de divergence si une commande oubliait l'un des appels.
     * En regroupant tout dans markRunSuccess(), on garantit la cohérence.
     *
     * @param int $nbItems Nombre d'opportunités trouvées lors de ce run
     */
    public function markRunSuccess(int $nbItems): void
    {
        // Champs stats standard (existants)
        $this->derniereExecution = new \DateTime();
        $this->nbItemsDernierRun = $nbItems;
        $this->statutDernierRun  = ScrapingRunStatus::Success;
        $this->messageErreur     = null;

        // Champs de santé — mis à jour uniquement lors d'un succès
        // Un succès réinitialise la chaîne d'échecs : on repart à zéro.
        $this->lastSuccessfulFetch  = new \DateTime();
        $this->consecutiveFailures  = 0;
    }

    /**
     * Enregistre un run en erreur sur cette source.
     *
     * Met à jour les champs stats ET incrémente le compteur de santé :
     *   - derniereExecution   : maintenant (DateTime)
     *   - statutDernierRun    : Error
     *   - messageErreur       : message d'erreur visible dans l'admin
     *   - consecutiveFailures : incrémenté de 1 (santé dégradée)
     *
     * Note : nbItemsDernierRun n'est PAS remis à 0 — on conserve le chiffre
     * du dernier run réussi pour référence dans l'interface admin.
     *
     * Note : la décision de désactiver la source (actif = false) n'est PAS prise ici.
     * L'entité expose l'état via hasReachedFailureThreshold() — c'est la commande
     * qui décide de désactiver et de logger le warning avec le bon contexte.
     *
     * @param string $message Message d'erreur (ex: "Slug 'xyz' inconnu", "HTTP 503")
     */
    public function markRunError(string $message): void
    {
        // Champs stats standard (existants)
        $this->derniereExecution = new \DateTime();
        $this->statutDernierRun  = ScrapingRunStatus::Error;
        $this->messageErreur     = $message;

        // Santé : chaque échec consécutif dégrade le compteur d'un cran.
        // La commande vérifiera ensuite hasReachedFailureThreshold() pour
        // décider de désactiver la source.
        $this->consecutiveFailures++;
    }

    /**
     * Indique si la source a atteint le seuil d'échecs consécutifs.
     *
     * Retourne true quand consecutiveFailures >= AUTO_DISABLE_THRESHOLD (5).
     * Dans ce cas, la commande appelante DOIT :
     *   1. Appeler $source->setActif(false) pour désactiver la source
     *   2. Logger un warning avec le nom de la source
     *
     * Cette méthode ne modifie PAS l'état de la source — elle lit seulement.
     * La responsabilité de la désactivation reste côté commande (séparation
     * des responsabilités : l'entité ne connaît pas le logger).
     */
    public function hasReachedFailureThreshold(): bool
    {
        return $this->consecutiveFailures >= self::AUTO_DISABLE_THRESHOLD;
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

    // ── Getters / Setters — champs WS1 ──────────────────────────────────────

    /**
     * Retourne l'URL du flux RSS/Atom de cette source.
     * Null si aucun flux n'est encore connu.
     */
    public function getFeedUrl(): ?string
    {
        return $this->feedUrl;
    }

    /**
     * Définit l'URL du flux RSS/Atom.
     * Passer null pour effacer le flux connu (ex: si le flux a été supprimé).
     */
    public function setFeedUrl(?string $feedUrl): static
    {
        $this->feedUrl = $feedUrl;
        return $this;
    }

    /**
     * Retourne la date du dernier fetch réussi.
     * Null si la source n'a jamais fourni de données exploitables.
     */
    public function getLastSuccessfulFetch(): ?\DateTimeInterface
    {
        return $this->lastSuccessfulFetch;
    }

    /**
     * Définit la date du dernier fetch réussi.
     * Appelé par l'orchestrateur du pipeline (WS3) — pas depuis le contrôleur admin.
     */
    public function setLastSuccessfulFetch(?\DateTimeInterface $lastSuccessfulFetch): static
    {
        $this->lastSuccessfulFetch = $lastSuccessfulFetch;
        return $this;
    }

    /**
     * Retourne le nombre d'échecs consécutifs depuis le dernier succès.
     */
    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    /**
     * Définit le compteur d'échecs consécutifs.
     * Préférer incrementConsecutiveFailures() et resetConsecutiveFailures()
     * pour les manipulations courantes.
     */
    public function setConsecutiveFailures(int $consecutiveFailures): static
    {
        $this->consecutiveFailures = $consecutiveFailures;
        return $this;
    }

    /**
     * Incrémente le compteur d'échecs consécutifs de 1.
     *
     * À appeler depuis l'orchestrateur du pipeline (WS3) lors d'un run en erreur.
     * L'orchestrateur doit ensuite vérifier si le seuil (5 par défaut) est atteint
     * pour désactiver automatiquement la source.
     *
     * Note : ce mécanisme sera câblé dans WS3 — ne pas appeler cette méthode
     * en dehors du service d'orchestration.
     */
    public function incrementConsecutiveFailures(): static
    {
        $this->consecutiveFailures++;
        return $this;
    }

    /**
     * Remet le compteur d'échecs à zéro.
     *
     * À appeler depuis l'orchestrateur (WS3) après un run réussi.
     * Un succès = la chaîne d'échecs est brisée, on repart de zéro.
     */
    public function resetConsecutiveFailures(): static
    {
        $this->consecutiveFailures = 0;
        return $this;
    }

    /**
     * Indique si la publication automatique est activée pour cette source.
     *
     * ⚠️ Toujours false en V1 (champ préparatoire — sans effet).
     */
    public function isAutoPublish(): bool
    {
        return $this->autoPublish;
    }

    /**
     * Active ou désactive la publication automatique pour cette source.
     *
     * ⚠️ N'a aucun effet en V1 — champ préparatoire uniquement.
     */
    public function setAutoPublish(bool $autoPublish): static
    {
        $this->autoPublish = $autoPublish;
        return $this;
    }
}

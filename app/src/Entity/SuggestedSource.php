<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SuggestedSourceStatus;
use App\Repository\SuggestedSourceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * SuggestedSource — Source suggérée automatiquement par app:discover-sources.
 *
 * Cycle de vie complet :
 *   1. La commande app:discover-sources analyse les pages HTML des agrégateurs
 *      (sources avec estAgregateur = true dans scraping_sources)
 *   2. Le LLM (via LlmExtractorService::discoverSources) extrait des organismes
 *      potentiellement intéressants depuis le HTML de chaque agrégateur
 *   3. Pour chaque organisme détecté non-doublon, une SuggestedSource est créée
 *      avec statut = AValider
 *   4. L'admin consulte /admin/suggested-sources et prend une décision :
 *      → Valider  : une ScrapingSource est créée, la suggestion passe à Validee
 *      → Rejeter  : la suggestion passe à Rejetee (aucune ScrapingSource créée)
 *
 * Règle de déduplication :
 *   Avant de créer une SuggestedSource, app:discover-sources vérifie que l'URL
 *   n'est pas déjà dans scraping_sources (via ScrapingSourceRepository::findByUrl)
 *   NI dans suggested_sources (via SuggestedSourceRepository::existsByUrl),
 *   peu importe le statut de la suggestion existante.
 *   Cette règle évite de soumettre plusieurs fois le même organisme à l'admin.
 *
 * Principe d'isolation absolu :
 *   Cette entité est TOTALEMENT SÉPARÉE de ScrapedResource.
 *   La commande de découverte ne modifie JAMAIS ScrapedResource.
 *   Elle ne lance JAMAIS le scraping. Elle popule UNIQUEMENT SuggestedSource.
 *
 * Table BDD : suggested_sources
 */
#[ORM\Entity(repositoryClass: SuggestedSourceRepository::class)]
#[ORM\Table(name: 'suggested_sources')]
// Index sur url : requête existsByUrl() appelée à chaque candidat LLM (20×/agrégateur)
// Index sur statut : requêtes findAllByStatut() + countByStatut() sur la page admin
#[ORM\Index(columns: ['url'], name: 'idx_suggested_sources_url')]
#[ORM\Index(columns: ['statut'], name: 'idx_suggested_sources_statut')]
#[ORM\HasLifecycleCallbacks]
class SuggestedSource
{
    /**
     * Identifiant auto-incrémenté par PostgreSQL.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Nom de l'organisme suggéré, tel qu'identifié par le LLM.
     *
     * Exemples : "Institut Français de Berlin", "Arts Council England",
     *            "Fondation Jan Michalski", "Villa Kujoyama"
     *
     * Ce champ est le nom brut retourné par le LLM — l'admin peut corriger
     * lors de la validation avant de créer la ScrapingSource.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $nomOrganisme;

    /**
     * URL du site de l'organisme, telle que détectée par le LLM.
     *
     * Peut être null si le LLM n'a pas trouvé d'URL dans le contexte HTML.
     * Dans ce cas, la validation admin sera bloquée (une URL est requise pour
     * créer une ScrapingSource).
     *
     * Cette URL doit pointer vers le site de l'ORGANISME (pas la page agrégateur).
     * Ex: "https://www.institut-francais.fr/" et non "https://on-the-move.org/calls"
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $url = null;

    /**
     * Type de source pressenti par le LLM.
     *
     * Valeurs possibles (non contraintes par un enum — c'est une suggestion) :
     *   "RSS"      → le LLM pense que le site a un flux RSS
     *   "HTML_LLM" → le LLM pense que le contenu est en HTML sans RSS
     *
     * Ce champ est indicatif. L'admin choisit le type définitif lors de la validation
     * via le dropdown du formulaire /admin/suggested-sources.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $typePressenti = null;

    /**
     * Zone géographique pressentie.
     *
     * Exemples : "France", "Europe", "Allemagne", "International", "Afrique du Sud"
     * Ce champ sera copié dans ScrapingSource::paysZone lors de la validation.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $paysZone = null;

    /**
     * Discipline artistique principale pressentie par le LLM.
     *
     * Exemples : "Musique", "Arts plastiques", "Pluridisciplinaire", "Danse"
     * Ce champ sera copié dans ScrapingSource::disciplinePrincipale lors de la validation.
     */
    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $disciplinePressentie = null;

    /**
     * Explication du LLM : pourquoi cet organisme semble pertinent.
     *
     * Exemples : "Le Fonds des Arts de Berlin finance des résidences de longue durée
     *             pour artistes internationaux, notamment dans les arts visuels et la musique."
     *
     * Cette explication aide l'admin à décider rapidement sans avoir à visiter le site.
     * Elle est générée directement par le LLM lors de l'analyse de la page agrégateur.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $raisonSuggestion = null;

    /**
     * Origine de la suggestion.
     *
     * En V1, toujours 'AGREGATEUR' (source découverte depuis l'analyse d'un agrégateur).
     * Ce champ est prévu pour d'éventuels autres modes de découverte en V2
     * (ex: 'MANUEL' si l'admin soumet une URL directement, 'API' depuis une source externe).
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $origine = 'AGREGATEUR';

    /**
     * Statut courant de la suggestion.
     *
     * Voir App\Enum\SuggestedSourceStatus pour le cycle de vie complet.
     * Stocké en BDD sous forme de string (valeur de l'enum case).
     * Initialisé à AValider lors de la création par app:discover-sources.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: SuggestedSourceStatus::class)]
    private SuggestedSourceStatus $statut;

    /**
     * URL de la page agrégateur depuis laquelle cette suggestion a été extraite.
     *
     * Permet à l'admin de retracer l'origine de la suggestion et de vérifier
     * le contexte sur la page source si nécessaire.
     *
     * Exemple : "https://on-the-move.org/calls"
     */
    #[ORM\Column(type: 'string', length: 500)]
    private string $sourceOrigine;

    /**
     * Date et heure à laquelle app:discover-sources a trouvé cet organisme.
     *
     * Toujours renseignée lors de la création (DateTime de l'exécution de la commande).
     * Distincte de createdAt qui est la date de persist BDD (pratiquement identique,
     * mais sémantiquement différent : dateDecouverte = "moment de détection par le LLM").
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $dateDecouverte;

    /**
     * Date de création de l'enregistrement en BDD.
     *
     * Remplie automatiquement par le lifecycle callback #[ORM\PrePersist].
     * Jamais null après persist().
     *
     * Convention du projet (CLAUDE.md §5) : utiliser des lifecycle callbacks
     * plutôt que Gedmo Timestampable pour rester sans dépendance externe.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // ── Constructeur ─────────────────────────────────────────────────────────

    /**
     * Initialise une nouvelle suggestion avec le statut AValider.
     *
     * Le statut est forcé à AValider en construction pour éviter de créer
     * une suggestion dans un état incohérent (Validee sans ScrapingSource, etc.).
     */
    public function __construct()
    {
        // Le statut est TOUJOURS AValider à la création
        // (seul app:discover-sources crée des SuggestedSource)
        $this->statut = SuggestedSourceStatus::AValider;

        // dateDecouverte par défaut = maintenant
        // (app:discover-sources peut l'écraser si besoin)
        $this->dateDecouverte = new \DateTime();
    }

    // ── Lifecycle Callbacks ──────────────────────────────────────────────────

    /**
     * Définit createdAt à la création de l'entité (INSERT en BDD).
     *
     * Ce callback est déclenché automatiquement par Doctrine
     * juste avant d'exécuter la requête INSERT SQL.
     * Ne pas appeler manuellement — laisser Doctrine le gérer.
     */
    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTime();
    }

    // ── Méthodes utilitaires (helpers de statut) ─────────────────────────────

    /**
     * Indique si la suggestion est en attente de validation.
     *
     * Utile dans les templates Twig pour conditionner l'affichage des actions.
     * Ex: {% if suggestion.aValider %} ... boutons Valider/Rejeter ... {% endif %}
     */
    public function isAValider(): bool
    {
        return $this->statut === SuggestedSourceStatus::AValider;
    }

    /**
     * Indique si la suggestion a été validée et transformée en ScrapingSource.
     */
    public function isValidee(): bool
    {
        return $this->statut === SuggestedSourceStatus::Validee;
    }

    /**
     * Indique si la suggestion a été rejetée par l'admin.
     */
    public function isRejetee(): bool
    {
        return $this->statut === SuggestedSourceStatus::Rejetee;
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomOrganisme(): string
    {
        return $this->nomOrganisme;
    }

    public function setNomOrganisme(string $nomOrganisme): static
    {
        $this->nomOrganisme = $nomOrganisme;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getTypePressenti(): ?string
    {
        return $this->typePressenti;
    }

    public function setTypePressenti(?string $typePressenti): static
    {
        $this->typePressenti = $typePressenti;
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

    public function getDisciplinePressentie(): ?string
    {
        return $this->disciplinePressentie;
    }

    public function setDisciplinePressentie(?string $disciplinePressentie): static
    {
        $this->disciplinePressentie = $disciplinePressentie;
        return $this;
    }

    public function getRaisonSuggestion(): ?string
    {
        return $this->raisonSuggestion;
    }

    public function setRaisonSuggestion(?string $raisonSuggestion): static
    {
        $this->raisonSuggestion = $raisonSuggestion;
        return $this;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $this->origine = $origine;
        return $this;
    }

    public function getStatut(): SuggestedSourceStatus
    {
        return $this->statut;
    }

    public function setStatut(SuggestedSourceStatus $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getSourceOrigine(): string
    {
        return $this->sourceOrigine;
    }

    public function setSourceOrigine(string $sourceOrigine): static
    {
        $this->sourceOrigine = $sourceOrigine;
        return $this;
    }

    public function getDateDecouverte(): \DateTimeInterface
    {
        return $this->dateDecouverte;
    }

    public function setDateDecouverte(\DateTimeInterface $dateDecouverte): static
    {
        $this->dateDecouverte = $dateDecouverte;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}

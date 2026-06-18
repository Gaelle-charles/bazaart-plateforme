<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExperienceLevel;
use App\Enum\ResourceStatus;
use App\Enum\SubmitterRole;
use App\Repository\ResourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Resource est l'entité centrale de la plateforme.
 * Elle représente une opportunité soumise par une organisation :
 * résidence, appel à projets, financement, formation, etc.
 *
 * Cycle de vie : pending → published (ou rejected) via validation admin.
 */
#[ORM\Entity(repositoryClass: ResourceRepository::class)]
#[ORM\Table(name: 'resources')]
#[ORM\HasLifecycleCallbacks]
class Resource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Titre de la ressource.
     * Ex : "Résidence de création — Villa Médicis 2026"
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * Description complète de la ressource.
     */
    #[ORM\Column(type: 'text')]
    private string $description;

    /**
     * URL externe vers la ressource originale (site de l'organisme).
     * Peut être null si la ressource n'a pas de lien externe.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $externalUrl = null;

    /**
     * Date limite pour candidater ou postuler (optionnelle).
     */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $deadline = null;

    /**
     * Localisation géographique de l'opportunité.
     * Ex: "Paris", "Lyon", "International", "En ligne"
     */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $location = null;

    /**
     * Statut de modération de la ressource — voir App\Enum\ResourceStatus.
     * Par défaut : Pending — toute soumission attend une validation admin.
     *
     * ⚠️ Note V1 : cet enum sera étendu en J3-J5 selon le CDC V3 §5.2
     * (ajout de Draft, PendingValidation, Archived). Pour l'instant on garde
     * les 3 valeurs historiques pour ne pas mélanger deux refactorings.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ResourceStatus::class)]
    private ResourceStatus $status = ResourceStatus::PendingValidation;

    /**
     * Type de la ressource (résidence, financement, appel à projets...).
     * ManyToOne : plusieurs ressources peuvent avoir le même type.
     */
    #[ORM\ManyToOne(targetEntity: ResourceType::class, inversedBy: 'resources')]
    #[ORM\JoinColumn(nullable: false)]
    private ResourceType $resourceType;

    /**
     * Organisation qui soumet la ressource.
     * nullable: true pour les ressources importées automatiquement depuis le scraping
     * (elles n'ont pas d'organisation BazaArt associée).
     */
    #[ORM\ManyToOne(targetEntity: OrganizationProfile::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?OrganizationProfile $organization = null;

    /**
     * Utilisateur qui a soumis la ressource (le compte connecté au moment de la soumission).
     * Utile pour le suivi et les notifications.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $submittedBy;

    /**
     * Disciplines artistiques concernées par cette ressource.
     * ManyToMany : une ressource peut concerner plusieurs disciplines
     * et une discipline peut apparaître dans plusieurs ressources.
     *
     * La table de jointure s'appellera "resource_disciplines".
     * "owningside" = c'est Resource qui gère la relation (elle a le JoinTable).
     */
    #[ORM\ManyToMany(targetEntity: Discipline::class, inversedBy: 'resources')]
    #[ORM\JoinTable(name: 'resource_disciplines')]
    private Collection $disciplines;

    /**
     * Date de création de la ressource — remplie automatiquement via PrePersist.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Date de dernière modification — mise à jour via PreUpdate.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    // ─── Champs V1 ajoutés selon CDC V3 §5.2 ─────────────────────────────────

    /**
     * Rôle du contributeur ayant créé cette ressource (cf. App\Enum\SubmitterRole).
     * Sert à déterminer si la ressource est auto-publiée ou doit passer par
     * la validation admin (voir $autoPublished).
     *
     * Par défaut Artist : c'est le comportement le plus restrictif (validation
     * requise). Les controllers admin/structure devront le forcer explicitement.
     *
     * Pourquoi default SQL = 'artist' ? Cette colonne est ajoutée à une table
     * qui contient peut-être déjà des lignes — le default évite le NOT NULL
     * violation lors de l'ALTER TABLE.
     */
    #[ORM\Column(
        type: 'string',
        length: 20,
        enumType: SubmitterRole::class,
        options: ['default' => 'artist'],
    )]
    private SubmitterRole $submitterRole = SubmitterRole::Artist;

    /**
     * true  → la ressource est publiée sans validation manuelle (admin/structure)
     * false → soumise à validation admin avant publication (artiste)
     *
     * C'est une donnée dérivée de $submitterRole, mais on la stocke pour
     * deux raisons :
     *   1. Performance : pas besoin de recalculer à chaque requête de filtre.
     *   2. Audit : on garde une trace claire de la décision d'auto-publication
     *      même si la logique métier évolue à l'avenir.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $autoPublished = false;

    /**
     * Date à laquelle la ressource est devenue Published.
     * Null tant que le statut n'a jamais été Published.
     * Reste figée même si la ressource passe ensuite en Archived.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

    /**
     * Date à laquelle un admin a explicitement validé la ressource.
     * Null si la ressource n'a jamais été validée manuellement (cas des
     * ressources auto-publiées par les structures).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $validatedAt = null;

    /**
     * Admin (User) qui a validé la ressource. Null si auto-publiée ou jamais validée.
     *
     * onDelete: SET NULL — si l'admin est supprimé en BDD, la ressource n'est
     * pas perdue ; on garde juste l'historique « validée par un admin maintenant
     * supprimé ». C'est plus sûr qu'un CASCADE qui supprimerait la ressource.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    // ─── Champs ADR-0016 Lot 1 : localisation fine + niveau d'expérience ─────
    // Ces trois champs enrichissent le filtrage de la Ressourcerie.
    // Tous nullable : beaucoup d'opportunités existantes n'ont pas ces données ;
    // les nouveaux scrapers les rempliront via le LLM enrichi.

    /**
     * Ville où se déroule l'opportunité (ex : "Paris", "Dakar", "Lyon").
     *
     * Complémentaire du champ $location (libre) qui pouvait mélanger ville,
     * région et pays. Ici on isole la ville pour faciliter les filtres géo.
     *
     * Nullable : toutes les opportunités n'ont pas de localisation précise
     * (ex : bourses nationales, opportunités 100 % en ligne).
     */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $city = null;

    /**
     * Pays de l'opportunité sous forme lisible (ex : "France", "Belgique", "Sénégal").
     *
     * On stocke le nom en clair (pas un code ISO) car les contenus LLM et
     * les formulaires travaillent avec des noms ; la traduction reste hors scope V1.
     *
     * Nullable : opportunités internationales ou sans pays précisé.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $country = null;

    /**
     * Niveau d'expérience requis pour cette opportunité.
     *
     * Utilise l'enum ExperienceLevel (beginner / intermediate / experienced).
     * NULL = tous niveaux (pas de restriction déclarée, ou non précisé).
     *
     * Stocké comme VARCHAR en BDD via enumType — Doctrine mappe automatiquement
     * la valeur backed de l'enum (ex : 'beginner') vers le case PHP correspondant.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ExperienceLevel::class, nullable: true)]
    private ?ExperienceLevel $experienceLevel = null;

    // ─────────────────────────────────────────────────────────────────────────

    public function __construct()
    {
        // ArrayCollection est l'implémentation Doctrine de Collection.
        // On l'initialise ici pour pouvoir appeler ->add() sans null check.
        $this->disciplines = new ArrayCollection();
    }

    // --- Lifecycle Callbacks ---

    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // --- Getters / Setters ---

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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $externalUrl): static
    {
        $this->externalUrl = $externalUrl;
        return $this;
    }

    /**
     * Retourne l'URL externe NORMALISÉE pour un usage en lien (href).
     *
     * Pourquoi ? Beaucoup d'URL issues du scraping (ou saisies à la main) sont
     * stockées SANS schéma, ex : "www.exemple.com/appel". Dans un href, le
     * navigateur interprète alors l'URL comme RELATIVE → il préfixe le domaine
     * courant → "https://app.bazaart.fr/www.exemple.com/appel" → 404.
     * C'est la cause des « liens Candidater qui ne fonctionnent pas ».
     *
     * Cette méthode garantit un schéma absolu, SANS jamais casser une URL déjà
     * valide (si elle commence déjà par http:// ou https://, on n'y touche pas) :
     *   - "www.exemple.com/x"   → "https://www.exemple.com/x"
     *   - "exemple.com"         → "https://exemple.com"
     *   - "//exemple.com/x"     → "https://exemple.com/x" (URL protocole-relative)
     *   - "http://exemple.com"  → inchangé
     *   - "mailto:x@y.fr"       → inchangé
     *
     * On l'utilise dans les templates pour l'attribut href (bouton « Candidater »,
     * preview admin). La donnée brute en BDD reste inchangée.
     */
    public function getExternalUrlNormalized(): ?string
    {
        if ($this->externalUrl === null) {
            return null;
        }

        $url = trim($this->externalUrl);
        if ($url === '') {
            return null;
        }

        // Déjà un schéma http(s) → on ne touche à rien
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        // URL « protocole-relative » (//exemple.com) → on force https
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        // Schémas non-web légitimes (mailto:, tel:) → on les laisse intacts
        $lower = strtolower($url);
        if (str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:')) {
            return $url;
        }

        // Sinon : URL sans schéma → on préfixe https:// (défaut le plus sûr aujourd'hui)
        return 'https://' . $url;
    }

    public function getDeadline(): ?\DateTimeInterface
    {
        return $this->deadline;
    }

    public function setDeadline(?\DateTimeInterface $deadline): static
    {
        $this->deadline = $deadline;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getStatus(): ResourceStatus
    {
        return $this->status;
    }

    public function setStatus(ResourceStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === ResourceStatus::Published;
    }

    /**
     * Une ressource "pending" attend la validation d'un admin.
     * Helper conservé sous le nom court `isPending()` (UX-friendly côté Twig)
     * même si le case enum a été renommé `PendingValidation` pour suivre le CDC.
     */
    public function isPending(): bool
    {
        return $this->status === ResourceStatus::PendingValidation;
    }

    /**
     * Helper ajouté pour cohérence avec les autres `is*()` — utilisé en Twig
     * (templates) pour afficher conditionnellement les ressources rejetées
     * sans manipuler de chaîne magique.
     */
    public function isRejected(): bool
    {
        return $this->status === ResourceStatus::Rejected;
    }

    public function getResourceType(): ResourceType
    {
        return $this->resourceType;
    }

    public function setResourceType(ResourceType $resourceType): static
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function getOrganization(): ?OrganizationProfile
    {
        return $this->organization;
    }

    public function setOrganization(?OrganizationProfile $organization): static
    {
        $this->organization = $organization;
        return $this;
    }

    public function getSubmittedBy(): User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(User $user): static
    {
        $this->submittedBy = $user;
        return $this;
    }

    public function getDisciplines(): Collection
    {
        return $this->disciplines;
    }

    public function addDiscipline(Discipline $discipline): static
    {
        if (!$this->disciplines->contains($discipline)) {
            $this->disciplines->add($discipline);
        }
        return $this;
    }

    public function removeDiscipline(Discipline $discipline): static
    {
        $this->disciplines->removeElement($discipline);
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    // ─── Getters / Setters V1 (champs CDC §5.2) ──────────────────────────────

    public function getSubmitterRole(): SubmitterRole
    {
        return $this->submitterRole;
    }

    public function setSubmitterRole(SubmitterRole $submitterRole): static
    {
        $this->submitterRole = $submitterRole;
        return $this;
    }

    public function isAutoPublished(): bool
    {
        return $this->autoPublished;
    }

    public function setAutoPublished(bool $autoPublished): static
    {
        $this->autoPublished = $autoPublished;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeInterface $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getValidatedAt(): ?\DateTimeInterface
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeInterface $validatedAt): static
    {
        $this->validatedAt = $validatedAt;
        return $this;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function setValidatedBy(?User $validatedBy): static
    {
        $this->validatedBy = $validatedBy;
        return $this;
    }

    // ─── Getters / Setters ADR-0016 Lot 1 ────────────────────────────────────

    /**
     * Retourne la ville de l'opportunité, ou null si non renseignée.
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * Définit la ville de l'opportunité.
     * Passer null efface la valeur (opportunité sans localisation ville).
     */
    public function setCity(?string $city): static
    {
        $this->city = $city;
        return $this;
    }

    /**
     * Retourne le pays de l'opportunité (nom en clair), ou null si non renseigné.
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * Définit le pays de l'opportunité.
     * Ex : "France", "Belgique", "Sénégal".
     */
    public function setCountry(?string $country): static
    {
        $this->country = $country;
        return $this;
    }

    /**
     * Retourne le niveau d'expérience requis, ou null si tous niveaux / non précisé.
     */
    public function getExperienceLevel(): ?ExperienceLevel
    {
        return $this->experienceLevel;
    }

    /**
     * Définit le niveau d'expérience requis.
     * Passer null signifie « tous niveaux » (pas de restriction).
     */
    public function setExperienceLevel(?ExperienceLevel $experienceLevel): static
    {
        $this->experienceLevel = $experienceLevel;
        return $this;
    }
}

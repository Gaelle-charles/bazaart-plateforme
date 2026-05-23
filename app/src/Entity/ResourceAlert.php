<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AlertFrequency;
use App\Repository\ResourceAlertRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * ResourceAlert représente les préférences d'alertes email d'un utilisateur
 * concernant les nouvelles ressources (opportunités) publiées sur la plateforme.
 *
 * Relation OneToOne avec User : un seul profil d'alertes par utilisateur.
 * Si l'utilisateur n'a jamais configuré ses alertes, ce profil n'existe pas
 * (null dans le repository) — les alertes sont alors désactivées par défaut.
 *
 * Filtrage optionnel :
 *   - $filterDisciplines vide = toutes les disciplines
 *   - $filterResourceTypes vide = tous les types
 *
 * Le job d'envoi (n8n ou Messenger) lira ces préférences pour construire
 * les emails personnalisés selon la fréquence choisie.
 */
#[ORM\Entity(repositoryClass: ResourceAlertRepository::class)]
#[ORM\Table(name: 'resource_alerts')]
#[ORM\HasLifecycleCallbacks]
class ResourceAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * L'utilisateur propriétaire de ces préférences d'alertes.
     *
     * OneToOne : un seul profil d'alertes par utilisateur.
     * onDelete: CASCADE — si l'utilisateur est supprimé, ses préférences le sont aussi.
     * unique = true est implicite pour un OneToOne avec JoinColumn.
     */
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * true  → l'utilisateur veut recevoir des alertes (actif)
     * false → alertes désactivées (l'utilisateur peut se désabonner sans supprimer le profil)
     *
     * Défaut : true — à la première configuration, on suppose que l'utilisateur
     * veut recevoir des alertes (sinon il ne configurerait pas ce profil).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $notifyOnNewResource = true;

    /**
     * Fréquence d'envoi des emails (IMMEDIATE | DAILY | WEEKLY).
     * Voir App\Enum\AlertFrequency pour les valeurs et leur signification.
     *
     * Défaut : DAILY — bon équilibre entre information et respect de l'inbox.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: AlertFrequency::class)]
    private AlertFrequency $frequency = AlertFrequency::Daily;

    /**
     * Disciplines artistiques filtrées.
     * Si la collection est vide → toutes les disciplines matchent.
     * Si non vide → seules les ressources ayant AU MOINS UNE des disciplines matchent.
     *
     * ManyToMany sans inversedBy car la relation est "privée" à cette entité :
     * on n'a pas besoin d'accéder aux alertes depuis une Discipline.
     * La table de jointure s'appelle 'resource_alert_disciplines'.
     */
    #[ORM\ManyToMany(targetEntity: Discipline::class)]
    #[ORM\JoinTable(name: 'resource_alert_disciplines')]
    private Collection $filterDisciplines;

    /**
     * Types de ressources filtrés (résidence, financement, appel à projets...).
     * Si vide → tous les types matchent.
     * La table de jointure s'appelle 'resource_alert_resource_types'.
     */
    #[ORM\ManyToMany(targetEntity: ResourceType::class)]
    #[ORM\JoinTable(name: 'resource_alert_resource_types')]
    private Collection $filterResourceTypes;

    /**
     * Date de création du profil d'alertes — remplie automatiquement (PrePersist).
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Date de dernière modification — mise à jour automatiquement (PreUpdate).
     * Utile pour savoir quand l'utilisateur a modifié ses préférences pour la dernière fois.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    // ─── Constructeur ──────────────────────────────────────────────────────────

    public function __construct()
    {
        // On initialise les deux Collections pour pouvoir appeler ->add() sans null check
        $this->filterDisciplines  = new ArrayCollection();
        $this->filterResourceTypes = new ArrayCollection();
    }

    // ─── Lifecycle Callbacks ───────────────────────────────────────────────────

    /**
     * Initialise createdAt et updatedAt au premier INSERT SQL.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now             = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Rafraîchit updatedAt à chaque UPDATE SQL.
     * Permet de tracer quand l'utilisateur a modifié ses préférences.
     */
    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ─── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function isNotifyOnNewResource(): bool
    {
        return $this->notifyOnNewResource;
    }

    public function setNotifyOnNewResource(bool $notifyOnNewResource): static
    {
        $this->notifyOnNewResource = $notifyOnNewResource;
        return $this;
    }

    public function getFrequency(): AlertFrequency
    {
        return $this->frequency;
    }

    public function setFrequency(AlertFrequency $frequency): static
    {
        $this->frequency = $frequency;
        return $this;
    }

    /**
     * Retourne la collection des disciplines filtrées.
     * Collection vide = pas de filtre (toutes les disciplines).
     *
     * @return Collection<int, Discipline>
     */
    public function getFilterDisciplines(): Collection
    {
        return $this->filterDisciplines;
    }

    public function addFilterDiscipline(Discipline $discipline): static
    {
        if (!$this->filterDisciplines->contains($discipline)) {
            $this->filterDisciplines->add($discipline);
        }
        return $this;
    }

    public function removeFilterDiscipline(Discipline $discipline): static
    {
        $this->filterDisciplines->removeElement($discipline);
        return $this;
    }

    /**
     * Retourne la collection des types de ressources filtrés.
     * Collection vide = pas de filtre (tous les types).
     *
     * @return Collection<int, ResourceType>
     */
    public function getFilterResourceTypes(): Collection
    {
        return $this->filterResourceTypes;
    }

    public function addFilterResourceType(ResourceType $type): static
    {
        if (!$this->filterResourceTypes->contains($type)) {
            $this->filterResourceTypes->add($type);
        }
        return $this;
    }

    public function removeFilterResourceType(ResourceType $type): static
    {
        $this->filterResourceTypes->removeElement($type);
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
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * ForumCategory représente une catégorie du forum communautaire Bazaart.
 *
 * Le forum est organisé en catégories (ex: "Actualités & Annonces", "Ressources & Opportunités").
 * Chaque catégorie contient des threads (sujets) créés par les membres.
 *
 * Structure hiérarchique :
 *   ForumCategory → ForumThread → ForumReply
 */
#[ORM\Entity(repositoryClass: ForumCategoryRepository::class)]
#[ORM\Table(name: 'forum_categories')]
#[ORM\HasLifecycleCallbacks]
class ForumCategory
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Informations de la catégorie ─────────────────────────────────────────

    /**
     * Nom affiché de la catégorie (ex: "Ressources & Opportunités").
     */
    #[ORM\Column(type: 'string', length: 100, nullable: false)]
    private string $name;

    /**
     * Slug URL-friendly généré depuis le nom (ex: "ressources-opportunites").
     * Utilisé dans les URLs : /forum/ressources-opportunites
     * Doit être unique car il sert d'identifiant dans les routes.
     */
    #[ORM\Column(type: 'string', length: 120, unique: true, nullable: false)]
    private string $slug;

    /**
     * Description courte visible sous le nom dans la liste des catégories.
     * Optionnel (nullable) car certaines catégories sont auto-explicatives.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $description = null;

    /**
     * Icône représentant la catégorie.
     * Peut être un emoji (ex: "🎵") ou un nom de classe CSS pour une icône SVG.
     * Optionnel — la catégorie s'affiche sans icône si ce champ est null.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $icon = null;

    /**
     * Couleur d'accentuation de la catégorie au format hexadécimal (ex: "#C8503A").
     * Utilisée pour personnaliser visuellement chaque catégorie dans l'interface.
     * Optionnel — une couleur par défaut est appliquée si null.
     */
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    /**
     * Position d'affichage dans la liste des catégories.
     * Les catégories sont triées par orderPosition ASC (0 = premier).
     * Permet de réordonner les catégories sans modifier leurs IDs.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orderPosition = 0;

    /**
     * Indique si la catégorie est active (visible dans le forum).
     * Une catégorie désactivée (false) n'apparaît pas aux utilisateurs,
     * mais ses threads sont conservés en base de données.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Date de création de la catégorie.
     * Initialisée automatiquement par le callback @PrePersist.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Threads (sujets) de cette catégorie.
     *
     * orphanRemoval: true → si on retire un thread de la catégorie, il est supprimé.
     * En pratique, c'est la suppression de la ForumCategory qui déclenche la cascade.
     *
     * Note : la suppression d'une catégorie est gérée au niveau SQL par
     * onDelete: 'CASCADE' dans ForumThread — les deux mécanismes sont cohérents.
     */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: ForumThread::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['isPinned' => 'DESC', 'createdAt' => 'DESC'])]
    private Collection $threads;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    public function __construct()
    {
        // ArrayCollection est l'implémentation Doctrine des collections.
        // Elle doit être initialisée dans le constructeur pour éviter
        // les erreurs "collection not initialized" avant le premier accès.
        $this->threads = new ArrayCollection();
    }

    // ─── Callbacks de cycle de vie Doctrine ───────────────────────────────────

    /**
     * Appelé automatiquement par Doctrine juste avant l'INSERT en base.
     * Initialise createdAt à la date/heure courante.
     */
    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTime();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getOrderPosition(): int
    {
        return $this->orderPosition;
    }

    public function setOrderPosition(int $orderPosition): static
    {
        $this->orderPosition = $orderPosition;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    // ─── Méthodes de collection ───────────────────────────────────────────────

    /**
     * Retourne tous les threads de cette catégorie.
     *
     * @return Collection<int, ForumThread>
     */
    public function getThreads(): Collection
    {
        return $this->threads;
    }

    /**
     * Ajoute un thread à la catégorie et synchronise la relation inverse.
     *
     * La synchronisation bidirectionnelle est importante en Doctrine :
     * même si la colonne FK est dans forum_threads, Doctrine doit connaître
     * les deux côtés de la relation pour que les collections restent cohérentes
     * sans avoir à recharger depuis la base.
     */
    public function addThread(ForumThread $thread): static
    {
        if (!$this->threads->contains($thread)) {
            $this->threads->add($thread);
            // Synchronise le côté propriétaire (ForumThread.$category)
            $thread->setCategory($this);
        }
        return $this;
    }

    /**
     * Retire un thread de la catégorie.
     * Grâce à orphanRemoval: true, Doctrine supprimera le thread en BDD
     * au prochain flush si personne d'autre ne le référence.
     */
    public function removeThread(ForumThread $thread): static
    {
        if ($this->threads->removeElement($thread)) {
            // Rompt la relation côté propriétaire si ce thread pointait vers cette catégorie
            if ($thread->getCategory() === $this) {
                // On ne peut pas mettre la catégorie à null (FK NOT NULL dans ForumThread),
                // donc en pratique on supprime le thread via orphanRemoval.
            }
        }
        return $this;
    }

    /**
     * Compte le nombre de threads dans cette catégorie.
     * Utilise count() sur la Collection — si la collection est déjà chargée
     * en mémoire (via FETCH EAGER ou jointure), aucune requête supplémentaire n'est faite.
     */
    public function getThreadsCount(): int
    {
        return $this->threads->count();
    }
}

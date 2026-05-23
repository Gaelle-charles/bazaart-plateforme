<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumThreadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * ForumThread représente un sujet (discussion) dans une catégorie du forum.
 *
 * Un thread est créé par un membre (author), appartient à une catégorie,
 * et peut recevoir des réponses (ForumReply).
 *
 * Fonctionnalités de modération :
 *   - isPinned : épinglé en haut de la liste (réservé aux admins)
 *   - isLocked : verrouillé (plus de nouvelles réponses, sauf admin/modo)
 *
 * Compteurs dénormalisés (performance) :
 *   - viewsCount  : incrémenté à chaque affichage du thread
 *   - repliesCount : maintenu à jour lors des ajouts/suppressions de réponses
 *   - lastReplyAt  : date de la dernière réponse pour le tri
 *
 * Ces compteurs évitent des COUNT(*) coûteux à chaque affichage de la liste.
 */
#[ORM\Entity(repositoryClass: ForumThreadRepository::class)]
#[ORM\Table(name: 'forum_threads')]
#[ORM\HasLifecycleCallbacks]
class ForumThread
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Catégorie à laquelle appartient ce thread.
     *
     * ManyToOne : plusieurs threads peuvent appartenir à une catégorie.
     * nullable: false → un thread doit toujours avoir une catégorie.
     * onDelete: 'CASCADE' → si la catégorie est supprimée en BDD, les threads le sont aussi.
     */
    #[ORM\ManyToOne(targetEntity: ForumCategory::class, inversedBy: 'threads')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ForumCategory $category;

    /**
     * Auteur du thread — l'utilisateur qui a créé le sujet.
     *
     * onDelete: 'CASCADE' → si le compte utilisateur est supprimé,
     * ses threads sont également supprimés (cohérence RGPD).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    /**
     * Réponses à ce thread.
     *
     * orphanRemoval: true → les réponses supprimées de la collection
     * sont automatiquement supprimées en base par Doctrine.
     */
    #[ORM\OneToMany(mappedBy: 'thread', targetEntity: ForumReply::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $replies;

    // ─── Contenu ──────────────────────────────────────────────────────────────

    /**
     * Titre du thread (255 caractères max).
     * Utilisé comme titre de page H1 et dans les listes de catégories.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $title;

    /**
     * Slug URL-friendly généré depuis le titre (ex: "recherche-graphiste-diaspora").
     * Combiné au slug de catégorie pour former l'URL : /forum/projets/recherche-graphiste
     * Unique en base pour éviter les collisions de routes.
     */
    #[ORM\Column(type: 'string', length: 280, unique: true, nullable: false)]
    private string $slug;

    /**
     * Corps du thread (texte principal posté par l'auteur).
     * Type 'text' pour des contenus longs (sans limite de longueur).
     */
    #[ORM\Column(type: 'text', nullable: false)]
    private string $content;

    // ─── Modération ───────────────────────────────────────────────────────────

    /**
     * Indique si le thread est épinglé en haut de la liste de sa catégorie.
     * Seuls les admins peuvent épingler (voir ForumVoter::FORUM_PIN).
     * Un thread épinglé reste en tête quelle que soit sa date.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPinned = false;

    /**
     * Indique si le thread est verrouillé (no new replies).
     * Admin et modérateurs peuvent verrouiller (voir ForumVoter::FORUM_LOCK).
     * Les admin/modo peuvent toujours répondre même sur un thread verrouillé.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isLocked = false;

    // ─── Compteurs dénormalisés ───────────────────────────────────────────────

    /**
     * Nombre de fois que ce thread a été consulté.
     * Incrémenté à chaque accès via ForumService::incrementViews().
     * Attention : pas de déduplication par user/IP en V1 (compteur brut).
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $viewsCount = 0;

    /**
     * Nombre de réponses à ce thread.
     * Maintenu à jour manuellement par ForumService (incrementReplies / decrementReplies).
     * Évite un COUNT(*) sur forum_replies à chaque affichage de la liste des threads.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $repliesCount = 0;

    /**
     * Date de la dernière réponse.
     * Utilisée pour trier les threads (les plus actifs remontent en tête).
     * Null si aucune réponse n'a encore été postée.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastReplyAt = null;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date/heure de création du thread.
     * Initialisée par @PrePersist — jamais modifiable ensuite.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Date/heure de la dernière modification du thread (titre ou contenu).
     * Mise à jour par @PrePersist et @PreUpdate.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->replies = new ArrayCollection();
    }

    // ─── Callbacks de cycle de vie ────────────────────────────────────────────

    /**
     * Appelé avant l'INSERT initial en base.
     * Initialise les deux timestamps à la date courante.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Appelé avant chaque UPDATE en base.
     * Met à jour updatedAt pour suivre les modifications de contenu.
     */
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ─── Méthodes métier ──────────────────────────────────────────────────────

    /**
     * Incrémente le compteur de vues.
     * Appelé par ForumService::incrementViews() à chaque affichage du thread.
     */
    public function incrementViews(): void
    {
        $this->viewsCount++;
    }

    /**
     * Incrémente le compteur de réponses et met à jour lastReplyAt.
     * Appelé par ForumService::addReply() après ajout d'une nouvelle réponse.
     */
    public function incrementReplies(): void
    {
        $this->repliesCount++;
        $this->lastReplyAt = new \DateTime();
    }

    /**
     * Décrémente le compteur de réponses (minimum 0 pour éviter les négatifs).
     * Appelé par ForumService::deleteReply() après suppression d'une réponse.
     * Note : lastReplyAt n'est PAS mis à jour ici (cela nécessiterait une requête
     * supplémentaire pour trouver la date de la réponse précédente — non critique en V1).
     */
    public function decrementReplies(): void
    {
        $this->repliesCount = max(0, $this->repliesCount - 1);
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ForumCategory
    {
        return $this->category;
    }

    public function setCategory(ForumCategory $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function setIsPinned(bool $isPinned): static
    {
        $this->isPinned = $isPinned;
        return $this;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function setIsLocked(bool $isLocked): static
    {
        $this->isLocked = $isLocked;
        return $this;
    }

    public function getViewsCount(): int
    {
        return $this->viewsCount;
    }

    public function getRepliesCount(): int
    {
        return $this->repliesCount;
    }

    public function getLastReplyAt(): ?\DateTimeInterface
    {
        return $this->lastReplyAt;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    // ─── Méthodes de collection ───────────────────────────────────────────────

    /**
     * @return Collection<int, ForumReply>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    /**
     * Ajoute une réponse à ce thread et synchronise la relation inverse.
     */
    public function addReply(ForumReply $reply): static
    {
        if (!$this->replies->contains($reply)) {
            $this->replies->add($reply);
            $reply->setThread($this);
        }
        return $this;
    }

    /**
     * Retire une réponse du thread.
     */
    public function removeReply(ForumReply $reply): static
    {
        $this->replies->removeElement($reply);
        return $this;
    }
}

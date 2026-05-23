<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumReplyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ForumReply représente une réponse à un thread du forum.
 *
 * Une réponse est postée par un membre (author) sur un thread.
 * Les réponses sont affichées dans l'ordre chronologique (createdAt ASC).
 *
 * Réponses imbriquées (optionnel V1) :
 *   La propriété $parentReply permet de référencer une autre réponse comme "parent".
 *   En V1, cette fonctionnalité est prévue dans le schéma mais pas forcément
 *   exploitée dans l'UI (préparation pour V2).
 *
 * Marquage "solution" :
 *   $isSolution permet à l'auteur du thread (ou aux admins) de marquer
 *   une réponse comme étant la solution — feature prévue mais non exposée en V1.
 */
#[ORM\Entity(repositoryClass: ForumReplyRepository::class)]
#[ORM\Table(name: 'forum_replies')]
#[ORM\HasLifecycleCallbacks]
class ForumReply
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Thread auquel appartient cette réponse.
     *
     * nullable: false → une réponse est toujours rattachée à un thread.
     * onDelete: 'CASCADE' → si le thread est supprimé, les réponses le sont aussi.
     */
    #[ORM\ManyToOne(targetEntity: ForumThread::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ForumThread $thread;

    /**
     * Auteur de la réponse.
     *
     * onDelete: 'CASCADE' → si le compte est supprimé, ses réponses le sont aussi (RGPD).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    /**
     * Réponse parente (pour les réponses imbriquées).
     *
     * Relation auto-référentielle : une ForumReply peut répondre à une autre ForumReply.
     * Exemple : l'utilisateur A répond au commentaire de l'utilisateur B → parentReply = B.
     *
     * nullable: true car la plupart des réponses sont des réponses directes au thread (root level).
     * onDelete: 'SET NULL' → si la réponse parente est supprimée, les enfants restent
     *   mais avec parentReply = null (ils "remontent" au niveau racine).
     *   On utilise SET NULL plutôt que CASCADE pour ne pas perdre tout un fil de conversation.
     *
     * En V1, cette relation est stockée en BDD mais l'UI ne gère pas l'imbrication.
     * Elle sera exploitée en V2.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ForumReply $parentReply = null;

    // ─── Contenu ──────────────────────────────────────────────────────────────

    /**
     * Contenu textuel de la réponse.
     * Type 'text' → pas de limite de longueur en BDD (contrairement à varchar).
     */
    #[ORM\Column(type: 'text', nullable: false)]
    private string $content;

    /**
     * Marque cette réponse comme étant la "solution" au thread.
     * En V1, cette info est stockée mais pas encore exposée dans l'interface.
     * Prévue pour une fonctionnalité "Marquer comme résolu" en V2.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSolution = false;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date/heure de création de la réponse.
     * Initialisée une seule fois par @PrePersist.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Date/heure de la dernière modification du contenu.
     * Mise à jour par @PrePersist (initial) et @PreUpdate (modifications).
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    // ─── Callbacks de cycle de vie ────────────────────────────────────────────

    /**
     * Appelé juste avant l'INSERT initial.
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
     * Appelé juste avant chaque UPDATE en base.
     * Permet de suivre les modifications de contenu (éditions).
     */
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThread(): ForumThread
    {
        return $this->thread;
    }

    public function setThread(ForumThread $thread): static
    {
        $this->thread = $thread;
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

    public function getParentReply(): ?ForumReply
    {
        return $this->parentReply;
    }

    public function setParentReply(?ForumReply $parentReply): static
    {
        $this->parentReply = $parentReply;
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

    public function isSolution(): bool
    {
        return $this->isSolution;
    }

    public function setIsSolution(bool $isSolution): static
    {
        $this->isSolution = $isSolution;
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

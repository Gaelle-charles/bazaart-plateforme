<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationParticipantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ConversationParticipant — table de jointure enrichie entre User et Conversation.
 *
 * Pourquoi une entité séparée plutôt qu'une simple relation ManyToMany ?
 * Parce qu'on a besoin de stocker des données supplémentaires :
 *   - joinedAt : date d'ajout à la conversation
 *   - lastReadAt : date du dernier "vu" — permet de calculer les messages non lus
 *
 * Un ManyToMany simple ne peut pas porter ces champs ; on doit créer une entité
 * intermédiaire (pattern "ManyToMany avec payload").
 *
 * Contrainte d'unicité :
 * Un utilisateur ne peut être participant qu'une seule fois à une conversation.
 * On l'applique au niveau SQL via #[ORM\UniqueConstraint] ET au niveau applicatif
 * dans MessagingService::initiateConversation() (on vérifie avant d'insérer).
 */
#[ORM\Entity(repositoryClass: ConversationParticipantRepository::class)]
#[ORM\Table(name: 'conversation_participants')]
#[ORM\UniqueConstraint(
    name: 'unique_conversation_participant',
    columns: ['conversation_id', 'user_id']
)]
#[ORM\HasLifecycleCallbacks]
class ConversationParticipant
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * La conversation à laquelle ce participant appartient.
     *
     * inversedBy: 'participants' → c'est ici que la colonne conversation_id est stockée
     * (ce côté est le "propriétaire" de la relation dans Doctrine).
     *
     * onDelete: 'CASCADE' → si la conversation est supprimée directement en SQL
     * (par exemple via une commande admin), les participants orphelins sont supprimés.
     * C'est un filet de sécurité complémentaire à orphanRemoval: true côté Conversation.
     */
    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    /**
     * L'utilisateur participant.
     *
     * onDelete: 'CASCADE' → si le compte utilisateur est supprimé,
     * ses participations aux conversations sont aussi supprimées (cohérence RGPD).
     * Les conversations sans participants deviennent des "fantômes" — acceptable en V1.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date à laquelle l'utilisateur a rejoint la conversation.
     * Initialisé par #[ORM\PrePersist] — jamais modifié.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $joinedAt = null;

    /**
     * Date à laquelle l'utilisateur a ouvert la conversation pour la dernière fois.
     *
     * Nullable → null signifie "n'a jamais ouvert cette conversation".
     * Mis à jour par MessagingService::markAsRead() à chaque ouverture du fil.
     *
     * Permet de calculer les messages non lus :
     * COUNT(messages WHERE createdAt > lastReadAt AND author != currentUser)
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastReadAt = null;

    // ─── Lifecycle callbacks ───────────────────────────────────────────────────

    /**
     * Initialisé automatiquement juste avant l'INSERT SQL.
     */
    #[ORM\PrePersist]
    public function initJoinedAt(): void
    {
        $this->joinedAt = new \DateTime();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): static
    {
        $this->conversation = $conversation;
        return $this;
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

    public function getJoinedAt(): ?\DateTimeInterface
    {
        return $this->joinedAt;
    }

    public function getLastReadAt(): ?\DateTimeInterface
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(?\DateTimeInterface $lastReadAt): static
    {
        $this->lastReadAt = $lastReadAt;
        return $this;
    }

    // ─── Méthodes métier ──────────────────────────────────────────────────────

    /**
     * Marque la conversation comme "lue maintenant" pour cet utilisateur.
     *
     * Appelé par MessagingService::markAsRead() à chaque ouverture du fil de conversation.
     * Après cet appel, les messages existants ne compteront plus comme "non lus"
     * (car leur createdAt < now = nouveau lastReadAt).
     */
    public function markAsRead(): void
    {
        $this->lastReadAt = new \DateTime();
    }
}

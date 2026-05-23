<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conversation — représente un fil de messages privés entre deux utilisateurs.
 *
 * En V1, une conversation est TOUJOURS entre exactement 2 participants.
 * Les groupes sont réservés à une version future.
 *
 * Structure de la messagerie :
 *   - Conversation  → contient les participants et les messages
 *   - ConversationParticipant → lie un User à une Conversation (avec lastReadAt)
 *   - Message → un message envoyé dans une conversation par un auteur
 *
 * lastMessageAt est mis à jour à chaque nouveau message pour permettre
 * de trier les conversations par activité récente (la plus active en premier).
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversations')]
#[ORM\HasLifecycleCallbacks]
class Conversation
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date de création de la conversation.
     * Initialisé automatiquement par #[ORM\PrePersist] — jamais modifié ensuite.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $createdAt = null;

    /**
     * Date du dernier message envoyé dans cette conversation.
     * Mis à jour par MessagingService::sendMessage() à chaque nouveau message.
     * Nullable : null si la conversation vient d'être créée et n'a pas encore de message.
     *
     * Utilisé pour trier les conversations par activité (plus récente en premier).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastMessageAt = null;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Liste des participants à cette conversation.
     *
     * orphanRemoval: true → si on retire un ConversationParticipant de cette collection,
     * il est automatiquement supprimé en BDD (pas de participants orphelins).
     *
     * cascade: ['persist', 'remove'] → quand on persiste/supprime une Conversation,
     * les participants sont automatiquement persistés/supprimés aussi.
     *
     * mappedBy: 'conversation' → indique que c'est ConversationParticipant qui "possède"
     * la relation (c'est lui qui a la colonne conversation_id en BDD).
     */
    #[ORM\OneToMany(
        mappedBy: 'conversation',
        targetEntity: ConversationParticipant::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $participants;

    /**
     * Messages de cette conversation, triés chronologiquement (du plus ancien au plus récent).
     *
     * orderBy: ['createdAt' => 'ASC'] → les messages s'affichent dans l'ordre naturel
     * d'une conversation (on lit du haut vers le bas, du plus ancien au plus récent).
     *
     * cascade: ['persist', 'remove'] + orphanRemoval: true → même logique que participants.
     */
    #[ORM\OneToMany(
        mappedBy: 'conversation',
        targetEntity: Message::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
        indexBy: null
    )]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    public function __construct()
    {
        // ArrayCollection est l'implémentation Doctrine d'une collection PHP.
        // Elle agit comme un tableau mais avec des méthodes utilitaires (filter, map, etc.)
        // et une intégration transparente avec le lazy loading de Doctrine.
        $this->participants = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    // ─── Lifecycle callbacks ───────────────────────────────────────────────────

    /**
     * Initialisé automatiquement à la création en BDD.
     * #[ORM\PrePersist] est appelé juste avant l'INSERT SQL.
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getLastMessageAt(): ?\DateTimeInterface
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeInterface $lastMessageAt): static
    {
        $this->lastMessageAt = $lastMessageAt;
        return $this;
    }

    /**
     * @return Collection<int, ConversationParticipant>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(ConversationParticipant $participant): static
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
            // Synchronise le côté propriétaire de la relation
            $participant->setConversation($this);
        }
        return $this;
    }

    public function removeParticipant(ConversationParticipant $participant): static
    {
        $this->participants->removeElement($participant);
        // orphanRemoval: true sur la relation OneToMany gère automatiquement
        // la suppression SQL du ConversationParticipant retiré de la collection.
        // Pas besoin de toucher au côté propriétaire : ConversationParticipant.conversation
        // est NOT NULL, donc on ne peut pas le mettre à null sans erreur de contrainte.
        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }
        return $this;
    }

    public function removeMessage(Message $message): static
    {
        $this->messages->removeElement($message);
        return $this;
    }

    // ─── Méthodes métier ──────────────────────────────────────────────────────

    /**
     * Retourne l'autre participant (pas l'utilisateur courant).
     *
     * Utilisé dans les templates pour afficher "Conversation avec [nom]"
     * et pour identifier l'interlocuteur dans la liste des conversations.
     *
     * Retourne null si l'user n'est pas dans la conversation (cas anormal)
     * ou si la conversation n'a qu'un seul participant (cas transitoire).
     */
    public function getOtherParticipant(User $currentUser): ?User
    {
        foreach ($this->participants as $participant) {
            // On compare les objets User par identité (===).
            // Doctrine garantit l'unicité des objets dans son Unit of Work :
            // si deux variables pointent sur le même user en BDD, c'est le même objet PHP.
            if ($participant->getUser() !== $currentUser) {
                return $participant->getUser();
            }
        }
        return null;
    }

    /**
     * Vérifie si un utilisateur est participant à cette conversation.
     *
     * Utilisé dans MessagingVoter pour vérifier l'autorisation de lecture/envoi.
     * On itère sur la collection de participants (en mémoire, pas de requête SQL supplémentaire
     * si les participants ont déjà été chargés via JOIN FETCH dans le repository).
     */
    public function hasParticipant(User $user): bool
    {
        foreach ($this->participants as $participant) {
            if ($participant->getUser() === $user) {
                return true;
            }
        }
        return false;
    }
}

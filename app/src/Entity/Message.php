<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Message — un message envoyé dans une conversation privée.
 *
 * Contenu en texte brut uniquement (pas de HTML) pour :
 *   - Simplicité de validation (pas de sanitisation HTML nécessaire)
 *   - Sécurité (pas de risque XSS via le contenu)
 *   - Cohérence avec le modèle V1 (les riches fonctionnalités de formatage sont V2+)
 *
 * La limite de 5000 caractères est appliquée dans MessagingService::sendMessage().
 *
 * Suppression en cascade :
 *   Si la conversation est supprimée → tous ses messages le sont aussi (onDelete: 'CASCADE').
 *   Si l'auteur est supprimé → ses messages sont supprimés (cohérence RGPD).
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
#[ORM\HasLifecycleCallbacks]
class Message
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * La conversation dans laquelle ce message a été envoyé.
     *
     * inversedBy: 'messages' → correspond à la propriété $messages dans Conversation.
     * onDelete: 'CASCADE' → si la conversation est supprimée en SQL, les messages le sont aussi.
     */
    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    /**
     * L'utilisateur qui a envoyé ce message.
     *
     * onDelete: 'CASCADE' → si l'utilisateur supprime son compte, ses messages
     * sont supprimés (conformité RGPD — droit à l'effacement).
     *
     * Note : dans une messagerie plus avancée (V2), on pourrait choisir de conserver
     * les messages avec un auteur anonymisé "Utilisateur supprimé". En V1, la suppression
     * en cascade est plus simple et suffisante.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    // ─── Champs de données ────────────────────────────────────────────────────

    /**
     * Contenu du message en texte brut.
     *
     * Type 'text' (vs 'string') car un message peut dépasser les 255 caractères.
     * En PostgreSQL, 'text' est illimité. La limite de 5000 caractères est
     * appliquée en PHP dans MessagingService, pas au niveau de la colonne BDD.
     */
    #[ORM\Column(type: 'text', nullable: false)]
    private string $content = '';

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date d'envoi du message.
     * Initialisé par #[ORM\PrePersist] — jamais modifié (un message envoyé ne change pas de date).
     *
     * Utilisé pour :
     *   - Afficher la date sous chaque message dans le fil
     *   - Calculer les messages non lus (messages.createdAt > participant.lastReadAt)
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $createdAt = null;

    // ─── Lifecycle callbacks ───────────────────────────────────────────────────

    /**
     * Initialisé automatiquement juste avant l'INSERT SQL.
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

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): static
    {
        $this->conversation = $conversation;
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

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}

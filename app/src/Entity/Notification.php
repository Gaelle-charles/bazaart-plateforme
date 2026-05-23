<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification — représente une notification destinée à un utilisateur Bazaart.
 *
 * Une notification est créée lorsqu'un événement concerne un utilisateur :
 *   - Il reçoit un message privé
 *   - Son thread forum reçoit une réponse
 *   - Sa ressource soumise est publiée par l'admin
 *   - etc.
 *
 * Chaque notification est liée à un destinataire (recipient) et peut pointer
 * optionnellement vers une entité liée (conversation, thread, ressource)
 * via relatedEntityType + relatedEntityId.
 *
 * Le champ `data` (JSON) stocke des informations complémentaires variables
 * selon le type (ex: {"senderEmail": "marie@ex.com"} pour NewMessage).
 *
 * Convention Doctrine :
 *   - Attributs PHP 8 uniquement (jamais @ORM en commentaire)
 *   - Table en snake_case : 'notifications'
 *   - Colonnes en snake_case (mappées automatiquement par Doctrine)
 *   - onDelete: 'CASCADE' sur le lien vers User → si l'user est supprimé, ses notifs aussi
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['recipient_id', 'is_read'], name: 'idx_notifications_recipient_read')]
#[ORM\Index(columns: ['created_at'], name: 'idx_notifications_created_at')]
class Notification
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Destinataire ─────────────────────────────────────────────────────────

    /**
     * L'utilisateur qui reçoit cette notification.
     *
     * onDelete: 'CASCADE' au niveau SQL → si l'user est supprimé,
     * toutes ses notifications sont supprimées automatiquement par PostgreSQL.
     *
     * nullable: false → une notification DOIT avoir un destinataire.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    // ─── Type ─────────────────────────────────────────────────────────────────

    /**
     * Le type de notification, stocké comme une string en BDD.
     *
     * enumType: NotificationType::class → Doctrine désérialise automatiquement
     * la valeur string de la BDD vers l'enum PHP lors de la lecture.
     *
     * Exemple BDD : 'new_message' → PHP : NotificationType::NewMessage
     */
    #[ORM\Column(type: 'string', length: 50, enumType: NotificationType::class)]
    private NotificationType $type;

    // ─── Statut de lecture ────────────────────────────────────────────────────

    /**
     * Indique si la notification a été lue par le destinataire.
     * Par défaut false → toute nouvelle notification est "non lue".
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRead = false;

    // ─── Entité liée (optionnel) ──────────────────────────────────────────────

    /**
     * Le type d'entité liée à cette notification.
     * Valeurs possibles V1 : 'conversation' | 'forum_thread' | 'resource' | 'live'
     *
     * Combiné avec relatedEntityId, permet de générer un lien vers l'entité.
     * Nullable car certaines notifs peuvent ne pas pointer vers une entité précise.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $relatedEntityType = null;

    /**
     * L'ID de l'entité liée (conversation, thread, resource, live).
     * null si la notification n'est pas liée à une entité précise.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $relatedEntityId = null;

    // ─── Données complémentaires ──────────────────────────────────────────────

    /**
     * Données JSON complémentaires, variables selon le type de notification.
     *
     * Exemples :
     *   NewMessage       → {"senderEmail": "marie@ex.com"}
     *   NewReply         → {"threadTitle": "Recherche graphiste", "replyAuthorEmail": "jean@ex.com"}
     *   ResourceValidated → {"resourceTitle": "Atelier sérigraphie"}
     *
     * On stocke en JSON pour rester flexible sans multiplier les colonnes
     * pour chaque type de notification.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    // ─── Timestamp ────────────────────────────────────────────────────────────

    /**
     * Date de création de la notification.
     * Rempli automatiquement par le lifecycle callback PrePersist (ci-dessous).
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $createdAt = null;

    // ─── Lifecycle callbacks ──────────────────────────────────────────────────

    /**
     * Initialise createdAt juste avant l'INSERT SQL.
     * Le # [ORM\PrePersist] est déclenché automatiquement par Doctrine.
     */
    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTime();
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function getRelatedEntityType(): ?string
    {
        return $this->relatedEntityType;
    }

    public function getRelatedEntityId(): ?int
    {
        return $this->relatedEntityId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    // ─── Setters ──────────────────────────────────────────────────────────────

    public function setRecipient(User $recipient): static
    {
        $this->recipient = $recipient;
        return $this;
    }

    public function setType(NotificationType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function setRelatedEntityType(?string $relatedEntityType): static
    {
        $this->relatedEntityType = $relatedEntityType;
        return $this;
    }

    public function setRelatedEntityId(?int $relatedEntityId): static
    {
        $this->relatedEntityId = $relatedEntityId;
        return $this;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function setData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }

    // ─── Méthodes métier ──────────────────────────────────────────────────────

    /**
     * Marque cette notification comme lue.
     *
     * Retourne $this (fluent interface) pour pouvoir chaîner si besoin :
     *   $notification->markAsRead();
     *   $em->flush();
     */
    public function markAsRead(): static
    {
        $this->isRead = true;
        return $this;
    }

    /**
     * Retourne le chemin (path) vers l'entité liée, selon le type d'entité.
     *
     * Note : cette méthode retourne des chemins statiques en V1.
     * Elle ne connaît pas le Router Symfony (l'entité n'a pas accès au container).
     * Pour des routes avec paramètres, c'est le template Twig qui construit le lien
     * via path() en utilisant getRelatedEntityType() et getRelatedEntityId().
     *
     * Valeurs retournées V1 :
     *   'conversation'  → '/messages/{id}'
     *   'forum_thread'  → '/forum' (pas de slug disponible ici en V1)
     *   'resource'      → '/resources/{id}'
     *   autre / null    → null
     */
    public function getLink(): ?string
    {
        if ($this->relatedEntityType === null || $this->relatedEntityId === null) {
            return null;
        }

        return match($this->relatedEntityType) {
            // Lien vers la conversation spécifique
            'conversation' => '/messages/' . $this->relatedEntityId,
            // Simplification V1 : pas de slug sur ForumThread disponible ici
            // → on redirige vers l'index du forum (le template Twig fait mieux)
            'forum_thread' => '/forum',
            // Lien vers la ressource spécifique
            'resource'     => '/resources/' . $this->relatedEntityId,
            // Fallback pour les types non reconnus
            default        => null,
        };
    }
}

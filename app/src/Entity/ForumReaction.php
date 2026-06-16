<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ForumReactionType;
use App\Repository\ForumReactionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ForumReaction — réaction emoji d'un utilisateur sur un thread ou une réponse.
 *
 * Modèle "polymorphe simple" : deux FK nullables (thread_id et reply_id).
 * Exactement l'une des deux est renseignée — l'autre est NULL.
 * Cet invariant est garanti par la logique applicative (ForumService::toggleReaction),
 * pas par une contrainte CHECK SQL (PostgreSQL le supporterait, mais ce serait
 * plus complexe à maintenir pour un gain marginal en V1).
 *
 * Pourquoi ne pas avoir une seule FK + un discriminator de type ?
 *   La FK double est plus simple à requêter avec Doctrine, plus lisible,
 *   et permet les contraintes d'unicité composées directement en BDD.
 *
 * Contraintes d'unicité (empêchent le doublon en BDD) :
 *   - (user_id, thread_id, type) pour les réactions sur les threads
 *   - (user_id, reply_id, type)  pour les réactions sur les réponses
 *   Ces contraintes sont le dernier rempart — la logique applicative gère le
 *   toggle AVANT d'arriver là, mais la BDD protège en cas de bug ou race condition.
 *
 *   NB : en base, ce sont des INDEX UNIQUE COMPLETS (sans clause WHERE), générés par
 *   doctrine:migrations:diff à partir de ces UniqueConstraint. En PostgreSQL, deux
 *   NULL sont considérés comme distincts dans un index UNIQUE : l'index sur
 *   (user_id, thread_id, type) autorise donc plusieurs lignes où thread_id est NULL
 *   (les réactions sur réponses) tout en empêchant le doublon quand thread_id est
 *   renseigné. doctrine:schema:validate est en phase (vérifié).
 *
 * onDelete: 'CASCADE' partout → si l'entité cible ou le user est supprimé,
 * les réactions associées disparaissent automatiquement (RGPD + cohérence).
 */
#[ORM\Entity(repositoryClass: ForumReactionRepository::class)]
#[ORM\Table(name: 'forum_reactions')]
// Contrainte d'unicité pour les réactions sur les THREADS
// Un user ne peut poser qu'une seule fois chaque type de réaction sur un thread donné
#[ORM\UniqueConstraint(
    name: 'uniq_forum_reaction_user_thread_type',
    columns: ['user_id', 'thread_id', 'type']
)]
// Contrainte d'unicité pour les réactions sur les RÉPONSES
// Même principe : un seul (user, reply, type) possible
#[ORM\UniqueConstraint(
    name: 'uniq_forum_reaction_user_reply_type',
    columns: ['user_id', 'reply_id', 'type']
)]
class ForumReaction
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Qui a réagi ? ────────────────────────────────────────────────────────

    /**
     * Utilisateur qui a posé la réaction.
     *
     * nullable: false → une réaction appartient toujours à un utilisateur identifié.
     * onDelete: 'CASCADE' → si le compte est supprimé (RGPD), ses réactions disparaissent.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // ─── Quel type de réaction ? ──────────────────────────────────────────────

    /**
     * Type de réaction (like / fire / bravo / heart / idea).
     *
     * Doctrine stocke la valeur backed de l'enum ('like', 'fire', etc.)
     * dans une colonne VARCHAR(20). Il désérialise automatiquement vers
     * l'enum PHP grâce à enumType: ForumReactionType::class.
     *
     * Exemple en BDD : type = 'fire' → en PHP : ForumReactionType::Fire
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ForumReactionType::class)]
    private ForumReactionType $type;

    // ─── Sur quoi ? (cible polymorphe) ────────────────────────────────────────

    /**
     * Thread cible de la réaction (null si la réaction porte sur une réponse).
     *
     * Invariant : exactement l'une des deux FK (thread / reply) est renseignée.
     * nullable: true car la réaction peut porter sur une réponse (reply_id renseigné).
     * onDelete: 'CASCADE' → si le thread est supprimé, ses réactions disparaissent.
     */
    #[ORM\ManyToOne(targetEntity: ForumThread::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ForumThread $thread = null;

    /**
     * Réponse cible de la réaction (null si la réaction porte sur un thread).
     *
     * Invariant : exactement l'une des deux FK (thread / reply) est renseignée.
     * nullable: true car la réaction peut porter sur un thread (thread_id renseigné).
     * onDelete: 'CASCADE' → si la réponse est supprimée, ses réactions disparaissent.
     */
    #[ORM\ManyToOne(targetEntity: ForumReply::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ForumReply $reply = null;

    // ─── Quand ? ──────────────────────────────────────────────────────────────

    /**
     * Date/heure de création de la réaction.
     * Initialisée dans le constructeur — jamais modifiée (une réaction est créée ou supprimée).
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    /**
     * Constructeur avec les paramètres obligatoires.
     *
     * On force le type et l'utilisateur à la construction car ces données
     * ne changent jamais — une réaction "like" ne peut pas devenir "fire".
     * Si l'utilisateur veut changer de réaction, l'ancienne est supprimée
     * et une nouvelle est créée (logique toggle dans ForumService).
     *
     * Le constructeur n'accepte PAS la cible (thread/reply) pour éviter
     * les erreurs de double-renseignement. On appelle ensuite setThread()
     * ou setReply() explicitement dans le service.
     */
    public function __construct(User $user, ForumReactionType $type)
    {
        $this->user      = $user;
        $this->type      = $type;
        $this->createdAt = new \DateTime();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): ForumReactionType
    {
        return $this->type;
    }

    public function getThread(): ?ForumThread
    {
        return $this->thread;
    }

    public function setThread(?ForumThread $thread): static
    {
        $this->thread = $thread;
        return $this;
    }

    public function getReply(): ?ForumReply
    {
        return $this->reply;
    }

    public function setReply(?ForumReply $reply): static
    {
        $this->reply = $reply;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}

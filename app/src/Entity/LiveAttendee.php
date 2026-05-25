<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LiveAttendeeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * LiveAttendee — inscription d'un utilisateur à un live planifié.
 *
 * Rôle de cette entité :
 *   1. Mémoriser qui est inscrit à quel live (table de jointure enrichie)
 *   2. Permettre l'envoi de rappels email 24h avant le live (flag reminderSent)
 *   3. Tracker la date d'inscription pour des statistiques futures
 *
 * C'est une "entité de jointure enrichie" (pas une simple table M2M) car elle
 * porte des données propres (registeredAt, reminderSent) au-delà de la simple
 * relation User × Live. Doctrine ORM recommande une entité dédiée dans ce cas.
 *
 * Contrainte d'unicité :
 *   Un utilisateur ne peut s'inscrire qu'une seule fois à un live donné.
 *   Cela est garanti à la fois côté base de données (index unique composé)
 *   et côté service (LiveService::registerAttendee vérifie le doublon).
 */
#[ORM\Entity(repositoryClass: LiveAttendeeRepository::class)]
#[ORM\Table(name: 'live_attendees')]
#[ORM\UniqueConstraint(
    name: 'uq_live_attendee',
    columns: ['live_id', 'user_id'],
)]
class LiveAttendee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Le live concerné.
     *
     * ManyToOne : un live peut avoir de nombreux inscrits.
     * nullable: false : un LiveAttendee sans live n'a pas de sens.
     *
     * Pas de cascade ici — c'est le côté propriétaire de la relation.
     * La suppression en cascade est gérée côté Live (orphanRemoval: true).
     */
    #[ORM\ManyToOne(targetEntity: Live::class, inversedBy: 'attendees')]
    #[ORM\JoinColumn(name: 'live_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Live $live;

    /**
     * L'utilisateur inscrit.
     *
     * ManyToOne : un utilisateur peut être inscrit à plusieurs lives.
     * nullable: false : un LiveAttendee sans utilisateur n'a pas de sens.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Date et heure d'inscription au live.
     * Initialisée dans le constructeur — lecture seule ensuite.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $registeredAt;

    /**
     * Flag indiquant si le rappel email 24h avant a déjà été envoyé.
     *
     * Pourquoi ce flag ?
     *   La commande app:live:send-reminders tourne toutes les heures ou toutes les 24h.
     *   Sans ce flag, chaque exécution enverrait un nouveau rappel. Ce serait spam.
     *   Avec ce flag, la commande marque reminderSent = true après l'envoi,
     *   et les prochaines exécutions ignorent les inscrits déjà notifiés.
     *
     * Reset : si la date du live change, l'admin devrait remettre ce flag à false
     * pour que les inscrits reçoivent un nouveau rappel. (En V1 : manuel via admin.)
     */
    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $reminderSent = false;

    public function __construct()
    {
        // La date d'inscription est fixée à la création de l'objet
        $this->registeredAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLive(): Live
    {
        return $this->live;
    }

    public function setLive(Live $live): static
    {
        $this->live = $live;
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

    public function getRegisteredAt(): \DateTimeInterface
    {
        return $this->registeredAt;
    }

    public function isReminderSent(): bool
    {
        return $this->reminderSent;
    }

    public function setReminderSent(bool $reminderSent): static
    {
        $this->reminderSent = $reminderSent;
        return $this;
    }
}

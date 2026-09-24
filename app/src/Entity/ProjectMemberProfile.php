<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectMemberProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectMemberProfile — préférences d'une membre de l'Espace projets (ADR-0037).
 *
 * L'ACCÈS à l'Espace projets est porté par le rôle ROLE_PROJECT (dans users.roles).
 * Ce profil ne sert qu'à mémoriser des préférences et l'onboarding :
 *   - color              : couleur d'avatar, pour reconnaître chacune d'un coup d'œil
 *                          (colonnes de la vue « Par personne », pastilles…)
 *   - tourCompletedAt    : visite guidée terminée (ou passée)
 *   - checklistDismissedAt : checklist « Bien démarrer » masquée
 *   - lastView           : dernière vue des tâches utilisée (kanban, calendrier…)
 *   - emailNotifications : recevoir un email quand on m'assigne une tâche, quand on
 *                          commente une de mes tâches, et le récap quotidien
 *
 * Il est créé à la volée à la première visite (ProjectMemberService::getProfile()).
 * On évite ainsi d'ajouter des colonnes spécifiques au module dans la table users.
 */
#[ORM\Entity(repositoryClass: ProjectMemberProfileRepository::class)]
#[ORM\Table(name: 'project_member_profiles')]
class ProjectMemberProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 7)]
    private string $color = '#FF6B2C';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $tourCompletedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $checklistDismissedAt = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $lastView = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $emailNotifications = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $color)
    {
        $this->user      = $user;
        $this->color     = $color;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getTourCompletedAt(): ?\DateTimeImmutable
    {
        return $this->tourCompletedAt;
    }

    public function isTourCompleted(): bool
    {
        return $this->tourCompletedAt !== null;
    }

    public function completeTour(): static
    {
        $this->tourCompletedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function getChecklistDismissedAt(): ?\DateTimeImmutable
    {
        return $this->checklistDismissedAt;
    }

    public function isChecklistDismissed(): bool
    {
        return $this->checklistDismissedAt !== null;
    }

    public function dismissChecklist(): static
    {
        $this->checklistDismissedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function restoreChecklist(): static
    {
        $this->checklistDismissedAt = null;

        return $this;
    }

    public function getLastView(): ?string
    {
        return $this->lastView;
    }

    public function setLastView(?string $lastView): static
    {
        $this->lastView = $lastView;

        return $this;
    }

    public function wantsEmailNotifications(): bool
    {
        return $this->emailNotifications;
    }

    public function setEmailNotifications(bool $emailNotifications): static
    {
        $this->emailNotifications = $emailNotifications;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

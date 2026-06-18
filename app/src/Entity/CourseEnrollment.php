<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EnrollmentStatus;
use App\Repository\CourseEnrollmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * CourseEnrollment — Inscription d'un utilisateur à une formation.
 *
 * Chaque ligne représente le lien entre un apprenant (User) et une formation (Course).
 * En V1 les formations sont gratuites (pas de paiement), donc l'inscription
 * se fait directement sur clic, sans validation.
 *
 * Contrainte d'unicité :
 *   Un utilisateur ne peut s'inscrire qu'une seule fois à la même formation.
 *   Garantie par la contrainte SQL `unique_enrollment` sur (user_id, course_id).
 *   Le service CourseEnrollmentService::enroll() vérifie aussi en PHP avant
 *   l'INSERT pour renvoyer un message d'erreur lisible plutôt qu'une exception SQL.
 *
 * Suivi de la progression :
 *   progressPercent est calculé par CourseEnrollmentService::recalculateProgress()
 *   à chaque fois qu'une leçon est marquée comme terminée (LessonProgress).
 *   completedAt est renseigné quand progressPercent atteint 100.
 *
 * Phase 3 (juin 2026) : ajout du statut d'inscription (ACTIVE / CANCELLED)
 * et de cancelledAt pour la logique d'annulation et remboursement.
 */
#[ORM\Entity(repositoryClass: CourseEnrollmentRepository::class)]
#[ORM\Table(name: 'course_enrollments')]
#[ORM\UniqueConstraint(name: 'unique_enrollment', columns: ['user_id', 'course_id'])]
#[ORM\HasLifecycleCallbacks]
class CourseEnrollment
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Statut ───────────────────────────────────────────────────────────────

    /**
     * Statut de l'inscription.
     *
     * Valeurs possibles : voir App\Enum\EnrollmentStatus.
     *   ACTIVE    → inscription valide, la place est comptée (défaut)
     *   CANCELLED → inscription annulée, la place est libérée
     *
     * Stocké en colonne VARCHAR(20) NOT NULL avec défaut 'active'.
     * La valeur par défaut SQL garantit la rétro-compatibilité :
     * les inscriptions existantes (créées avant Phase 3) héritent du statut 'active'
     * via la valeur DEFAULT de la migration — aucune rupture de données.
     *
     * enumType: 'string' indique à Doctrine de stocker la valeur backing de l'enum,
     * pas son nom (ex : 'active' et non 'ACTIVE').
     */
    #[ORM\Column(type: 'string', length: 20, nullable: false, options: ['default' => 'active'], enumType: EnrollmentStatus::class)]
    private EnrollmentStatus $status = EnrollmentStatus::ACTIVE;

    /**
     * Date et heure de l'annulation de l'inscription.
     *
     * null tant que l'inscription est ACTIVE.
     * Renseigné par EventCancellationService::cancelByMember() ou cancelByAdmin()
     * au moment de l'annulation.
     *
     * Utile pour l'audit, les statistiques d'annulation, et les emails
     * de confirmation d'annulation.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $cancelledAt = null;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date et heure de l'inscription.
     * Initialisée automatiquement par #[ORM\PrePersist].
     * Sert à trier les inscriptions et à afficher "inscrit le…" dans le dashboard.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $enrolledAt;

    /**
     * Date de complétion de la formation (100% des leçons terminées).
     * null tant que la formation n'est pas terminée.
     * Renseigné par CourseEnrollmentService::recalculateProgress() quand
     * progressPercent passe à 100.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    // ─── Progression ──────────────────────────────────────────────────────────

    /**
     * Pourcentage de complétion de la formation, de 0 à 100.
     * Calculé : (nb leçons complétées / nb total leçons de la formation) × 100.
     * Mis à jour par CourseEnrollmentService::recalculateProgress() à chaque
     * marquage d'une leçon comme complétée.
     *
     * Stocké dénormalisé pour éviter un COUNT coûteux à chaque affichage
     * du tableau de bord apprenant.
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $progressPercent = 0;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * L'apprenant inscrit à la formation.
     *
     * nullable: false → une inscription doit toujours avoir un utilisateur.
     * onDelete: 'CASCADE' → si le compte est supprimé (RGPD), ses inscriptions
     * le sont aussi (pas de données orphelines).
     *
     * Pas de relation inverse sur User pour éviter de surcharger l'entité User
     * qui a déjà plusieurs relations (artistProfile, organizationProfile).
     * On passera par le repository : CourseEnrollmentRepository::findByUser($user).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * La formation à laquelle l'utilisateur est inscrit.
     *
     * nullable: false → une inscription sans formation n'a aucun sens.
     * onDelete: 'CASCADE' → si la formation est supprimée, les inscriptions le sont aussi.
     */
    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'enrollments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    // ─── Callbacks de cycle de vie ────────────────────────────────────────────

    /**
     * Initialise enrolledAt à l'instant de l'inscription (INSERT).
     * On utilise un PrePersist dédié plutôt qu'une valeur default SQL
     * pour rester cohérent avec le pattern du reste du projet.
     */
    #[ORM\PrePersist]
    public function initEnrolledAt(): void
    {
        $this->enrolledAt = new \DateTime();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): EnrollmentStatus
    {
        return $this->status;
    }

    public function setStatus(EnrollmentStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Raccourci : l'inscription est-elle encore active ?
     * Utilisé dans les templates et dans EventCancellationService pour les gardes.
     */
    public function isActive(): bool
    {
        return $this->status === EnrollmentStatus::ACTIVE;
    }

    /**
     * Raccourci : l'inscription a-t-elle été annulée ?
     */
    public function isCancelled(): bool
    {
        return $this->status === EnrollmentStatus::CANCELLED;
    }

    public function getCancelledAt(): ?\DateTimeInterface
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeInterface $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getEnrolledAt(): \DateTimeInterface
    {
        return $this->enrolledAt;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getProgressPercent(): int
    {
        return $this->progressPercent;
    }

    public function setProgressPercent(int $progressPercent): static
    {
        // Contrainte défensive : le pourcentage ne peut pas dépasser 0–100
        $this->progressPercent = max(0, min(100, $progressPercent));
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

    public function getCourse(): Course
    {
        return $this->course;
    }

    public function setCourse(Course $course): static
    {
        $this->course = $course;
        return $this;
    }

    // ─── Méthodes utilitaires ─────────────────────────────────────────────────

    /**
     * Retourne true si l'apprenant a terminé la formation (100%).
     * Utilisé dans les templates Twig pour afficher le badge "Terminé".
     */
    public function isCompleted(): bool
    {
        return $this->progressPercent === 100;
    }
}

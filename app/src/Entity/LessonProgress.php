<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LessonProgressRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * LessonProgress — Progression d'un apprenant sur une leçon donnée.
 *
 * Chaque ligne représente l'avancement d'une inscription (CourseEnrollment)
 * sur une leçon spécifique (Lesson). On crée un enregistrement au premier
 * accès à la leçon, et on le met à jour au fil du visionnage.
 *
 * Fonctionnalité de reprise de lecture :
 *   lastPositionSeconds stocke la dernière position dans la vidéo.
 *   Lors du chargement de la leçon, le player Bunny Stream (ou iframe générique)
 *   est initialisé à cette position via JavaScript — expérience "reprendre là
 *   où j'en étais".
 *
 * Marquage comme "terminée" :
 *   completedAt != null → la leçon est considérée comme terminée.
 *   Le service CourseEnrollmentService::markLessonCompleted() renseigne
 *   completedAt et recalcule CourseEnrollment.progressPercent.
 *
 * Lien avec l'inscription :
 *   LessonProgress.enrollment → CourseEnrollment
 *   Pas de relation inverse sur CourseEnrollment (pour ne pas surcharger
 *   l'entité). On interroge via LessonProgressRepository::findByEnrollment($enrollment).
 *
 * Note performance :
 *   Une ligne par (enrollment × lesson). Pour une formation de 30 leçons
 *   avec 1 000 apprenants = 30 000 lignes maximum. Tout à fait gérable en PG16.
 */
#[ORM\Entity(repositoryClass: LessonProgressRepository::class)]
#[ORM\Table(name: 'lesson_progresses')]
class LessonProgress
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Timestamps de progression ────────────────────────────────────────────

    /**
     * Date et heure du premier accès à la leçon.
     * null si la leçon n'a pas encore été commencée (la ligne est créée
     * à la première ouverture du player — startedAt alors renseigné).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    /**
     * Date et heure à laquelle la leçon a été marquée comme terminée.
     * null si la leçon est en cours ou pas encore commencée.
     *
     * Renseigné par CourseEnrollmentService::markLessonCompleted().
     * Une fois renseigné, on considère la leçon définitivement terminée
     * (pas de logique de "décomplétion" en V1).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    // ─── Position de reprise ──────────────────────────────────────────────────

    /**
     * Dernière position de lecture dans la vidéo, en secondes.
     * Ex : 342 = l'apprenant s'est arrêté à 5 min 42 secondes.
     *
     * Mis à jour par un appel AJAX périodique depuis le player (toutes les N secondes).
     * Route : POST /formations/{slug}/learn/{lessonId}/progress
     *
     * Default : 0 (début de la vidéo).
     * nullable: false → on veut toujours une valeur valide pour initialiser le player.
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $lastPositionSeconds = 0;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Inscription (CourseEnrollment) à laquelle est rattachée cette progression.
     *
     * L'inscription identifie à la fois l'apprenant et la formation.
     * nullable: false → une progression sans inscription n'a aucun sens métier.
     * onDelete: 'CASCADE' → si l'inscription est supprimée (désabonnement ou RGPD),
     * toutes ses lignes de progression le sont aussi (cohérence + pas d'orphelins).
     *
     * Pas de relation inverse sur CourseEnrollment pour garder cette entité légère.
     */
    #[ORM\ManyToOne(targetEntity: CourseEnrollment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CourseEnrollment $enrollment;

    /**
     * Leçon sur laquelle porte cette progression.
     *
     * nullable: false → une progression doit toujours référencer une leçon.
     * onDelete: 'CASCADE' → si la leçon est supprimée (refonte du module),
     * ses lignes de progression disparaissent aussi.
     */
    #[ORM\ManyToOne(targetEntity: Lesson::class, inversedBy: 'progresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Lesson $lesson;

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
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

    public function getLastPositionSeconds(): int
    {
        return $this->lastPositionSeconds;
    }

    public function setLastPositionSeconds(int $lastPositionSeconds): static
    {
        // Contrainte défensive : la position ne peut pas être négative
        $this->lastPositionSeconds = max(0, $lastPositionSeconds);
        return $this;
    }

    public function getEnrollment(): CourseEnrollment
    {
        return $this->enrollment;
    }

    public function setEnrollment(CourseEnrollment $enrollment): static
    {
        $this->enrollment = $enrollment;
        return $this;
    }

    public function getLesson(): Lesson
    {
        return $this->lesson;
    }

    public function setLesson(Lesson $lesson): static
    {
        $this->lesson = $lesson;
        return $this;
    }

    // ─── Méthodes utilitaires ─────────────────────────────────────────────────

    /**
     * Retourne true si la leçon a été commencée (player ouvert au moins une fois).
     */
    public function isStarted(): bool
    {
        return $this->startedAt !== null;
    }

    /**
     * Retourne true si la leçon est terminée.
     * Utilisé dans les templates Twig pour afficher l'icône checkmark.
     */
    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }
}

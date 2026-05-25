<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CourseModuleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * CourseModule — Un module (chapitre) à l'intérieur d'une formation.
 *
 * Une formation est découpée en modules, chaque module regroupant
 * plusieurs leçons liées par une thématique ou une progression.
 *
 * Exemple pour une formation "Introduction à l'Afrobeats" :
 *   Module 1 — Histoire et origines (leçons : Nigeria, Fela Kuti, exportation)
 *   Module 2 — Rythme et percussion (leçons : Clave, Shekere, Talking drum)
 *   Module 3 — Production moderne (leçons : DAW, samples, mixage)
 *
 * L'ordre d'affichage est contrôlé par orderPosition (trié ASC).
 * La relation Course → modules est déclarée avec #[ORM\OrderBy] côté Course.
 *
 * Pas de timestamps sur cette entité : les timestamps de la formation parente
 * suffisent pour l'audit. Ajouter createdAt/updatedAt ici serait du sur-engineering
 * pour la V1. À réévaluer si besoin de logs granulaires (V2).
 */
#[ORM\Entity(repositoryClass: CourseModuleRepository::class)]
#[ORM\Table(name: 'course_modules')]
class CourseModule
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Contenu ──────────────────────────────────────────────────────────────

    /**
     * Titre du module, affiché dans la barre de navigation de l'espace apprenant.
     * Ex : "Rythme et percussion"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $title;

    /**
     * Description optionnelle du module.
     * Affichée comme introduction avant la liste des leçons.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Position d'affichage parmi les autres modules de la même formation.
     * Trié ASC : 0 = premier, 1 = deuxième, etc.
     * L'admin peut réordonner les modules via le dashboard (mise à jour des positions).
     *
     * Default : 0 — pratique pour les insertions unitaires sans spécifier la position.
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $orderPosition = 0;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Formation parente à laquelle ce module appartient.
     *
     * ManyToOne : plusieurs modules peuvent appartenir à une même formation.
     * nullable: false → un module ne peut exister sans formation (contrainte métier).
     * onDelete: 'CASCADE' → si la formation est supprimée directement en SQL,
     * ses modules le sont aussi. Doctrine gère déjà la suppression via
     * orphanRemoval sur Course, mais la contrainte SQL protège aussi les
     * suppressions directes en BDD ou via des scripts.
     */
    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'modules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    /**
     * Leçons contenues dans ce module.
     *
     * cascade: ['persist', 'remove'] :
     *   - persist → sauvegarder un module sauvegarde ses leçons attachées
     *   - remove  → supprimer un module supprime ses leçons
     *
     * orphanRemoval: true → une leçon retirée de la collection est supprimée en base.
     *
     * @var Collection<int, Lesson>
     */
    #[ORM\OneToMany(
        mappedBy: 'module',
        targetEntity: Lesson::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['orderPosition' => 'ASC'])]
    private Collection $lessons;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->lessons = new ArrayCollection();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getOrderPosition(): int
    {
        return $this->orderPosition;
    }

    public function setOrderPosition(int $orderPosition): static
    {
        $this->orderPosition = $orderPosition;
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

    // ─── Méthodes de collection : leçons ──────────────────────────────────────

    /**
     * @return Collection<int, Lesson>
     */
    public function getLessons(): Collection
    {
        return $this->lessons;
    }

    /**
     * Ajoute une leçon au module et synchronise la relation inverse.
     */
    public function addLesson(Lesson $lesson): static
    {
        if (!$this->lessons->contains($lesson)) {
            $this->lessons->add($lesson);
            $lesson->setModule($this);
        }
        return $this;
    }

    /**
     * Retire une leçon du module.
     * orphanRemoval: true → la leçon sera supprimée en base au prochain flush().
     */
    public function removeLesson(Lesson $lesson): static
    {
        $this->lessons->removeElement($lesson);
        return $this;
    }
}

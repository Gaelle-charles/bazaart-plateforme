<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LessonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lesson — Une leçon individuelle à l'intérieur d'un module de formation.
 *
 * Une leçon est l'unité atomique de contenu : elle correspond typiquement
 * à une vidéo (hébergée sur Bunny Stream ou via URL iframe générique),
 * éventuellement accompagnée de ressources téléchargeables (LessonResource).
 *
 * Deux modes vidéo supportés en V1 (cf. CDC §5.7 — Intégration Bunny Stream) :
 *
 *   1. videoBunnyId : identifiant Bunny Stream (lecture via player iframe Bunny
 *      avec token signé, protection contre le hotlinking). Mode principal V1.
 *
 *   2. videoUrl : URL iframe générique (YouTube embed, Vimeo embed, etc.).
 *      Mode de fallback ou pour les leçons de preview publique non-Bunny.
 *
 * Les deux champs sont nullable : une leçon peut être en cours de création
 * avant que la vidéo soit uploadée (brouillon de module).
 *
 * Accès libre (isFreePreview) :
 *   true  → la leçon est accessible sans inscription (teaser, extrait gratuit)
 *   false → réservée aux apprenants inscrits
 */
#[ORM\Entity(repositoryClass: LessonRepository::class)]
#[ORM\Table(name: 'lessons')]
class Lesson
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Contenu ──────────────────────────────────────────────────────────────

    /**
     * Titre de la leçon.
     * Ex : "Les fondements du rythme clavé" ou "Introduction au logiciel Ableton"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $title;

    /**
     * Description courte ou objectifs pédagogiques de la leçon (optionnelle).
     * Affichée sous le titre dans la liste des leçons du module.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    // ─── Vidéo ────────────────────────────────────────────────────────────────

    /**
     * Identifiant de la vidéo sur Bunny Stream.
     * Ex : "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
     *
     * Utilisé pour construire l'URL du player iframe Bunny et obtenir
     * des tokens signés via l'API (protection contre le hotlinking).
     * null = vidéo pas encore uploadée, ou leçon sans vidéo Bunny.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $videoBunnyId = null;

    /**
     * URL iframe générique pour les vidéos non-hébergées sur Bunny.
     * Ex : "https://www.youtube.com/embed/dQw4w9WgXcQ"
     *       "https://player.vimeo.com/video/123456789"
     *
     * Utilisé quand videoBunnyId est null (YouTube, Vimeo, ou URL Bunny
     * directement si on n'a pas besoin de token signé).
     * length: 500 pour absorber les URLs longues avec paramètres.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $videoUrl = null;

    /**
     * Durée de la leçon en secondes.
     * Ex : 720 = 12 minutes
     * Utilisée pour afficher "12 min" dans la liste des leçons et calculer
     * durationMinutesTotal de la formation parente.
     * null si la durée n'est pas encore renseignée (pendant l'upload).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationSeconds = null;

    // ─── Ordre et accès ───────────────────────────────────────────────────────

    /**
     * Position d'affichage parmi les leçons du même module (trié ASC).
     * 0 = première leçon. L'admin peut réordonner depuis le dashboard.
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $orderPosition = 0;

    /**
     * Si true, cette leçon est accessible sans inscription.
     * Permet d'offrir un aperçu gratuit pour convaincre les visiteurs
     * de s'inscrire (logique freemium / teaser).
     *
     * Vérifié dans LessonVoter::VIEW avant d'afficher le contenu.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isFreePreview = false;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Module auquel appartient cette leçon.
     *
     * nullable: false → une leçon ne peut exister sans module (logique métier).
     * onDelete: 'CASCADE' → protection SQL si le module est supprimé directement.
     */
    #[ORM\ManyToOne(targetEntity: CourseModule::class, inversedBy: 'lessons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CourseModule $module;

    /**
     * Ressources téléchargeables attachées à cette leçon (PDFs, supports de cours...).
     *
     * cascade: ['persist', 'remove'] + orphanRemoval: true :
     *   Doctrine gère la suppression des ressources quand la leçon est supprimée.
     *
     * @var Collection<int, LessonResource>
     */
    #[ORM\OneToMany(
        mappedBy: 'lesson',
        targetEntity: LessonResource::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $resources;

    /**
     * Enregistrements de progression des apprenants sur cette leçon.
     *
     * Relation inverse de LessonProgress.lesson.
     * orphanRemoval: true → nettoyage automatique si la leçon est supprimée.
     *
     * @var Collection<int, LessonProgress>
     */
    #[ORM\OneToMany(
        mappedBy: 'lesson',
        targetEntity: LessonProgress::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $progresses;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->resources  = new ArrayCollection();
        $this->progresses = new ArrayCollection();
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

    public function getVideoBunnyId(): ?string
    {
        return $this->videoBunnyId;
    }

    public function setVideoBunnyId(?string $videoBunnyId): static
    {
        $this->videoBunnyId = $videoBunnyId;
        return $this;
    }

    public function getVideoUrl(): ?string
    {
        return $this->videoUrl;
    }

    public function setVideoUrl(?string $videoUrl): static
    {
        $this->videoUrl = $videoUrl;
        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;
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

    public function isFreePreview(): bool
    {
        return $this->isFreePreview;
    }

    public function setIsFreePreview(bool $isFreePreview): static
    {
        $this->isFreePreview = $isFreePreview;
        return $this;
    }

    public function getModule(): CourseModule
    {
        return $this->module;
    }

    public function setModule(CourseModule $module): static
    {
        $this->module = $module;
        return $this;
    }

    // ─── Méthodes de collection : ressources ──────────────────────────────────

    /**
     * @return Collection<int, LessonResource>
     */
    public function getResources(): Collection
    {
        return $this->resources;
    }

    public function addResource(LessonResource $resource): static
    {
        if (!$this->resources->contains($resource)) {
            $this->resources->add($resource);
            $resource->setLesson($this);
        }
        return $this;
    }

    public function removeResource(LessonResource $resource): static
    {
        $this->resources->removeElement($resource);
        return $this;
    }

    // ─── Méthodes de collection : progressions ────────────────────────────────

    /**
     * @return Collection<int, LessonProgress>
     */
    public function getProgresses(): Collection
    {
        return $this->progresses;
    }

    public function addProgress(LessonProgress $progress): static
    {
        if (!$this->progresses->contains($progress)) {
            $this->progresses->add($progress);
            $progress->setLesson($this);
        }
        return $this;
    }

    public function removeProgress(LessonProgress $progress): static
    {
        $this->progresses->removeElement($progress);
        return $this;
    }

    // ─── Méthodes utilitaires ─────────────────────────────────────────────────

    /**
     * Retourne la durée de la leçon en minutes (arrondi supérieur).
     * Retourne null si la durée n'est pas encore définie.
     * Utile pour l'affichage dans les templates Twig.
     */
    public function getDurationMinutes(): ?int
    {
        if ($this->durationSeconds === null) {
            return null;
        }
        return (int) ceil($this->durationSeconds / 60);
    }
}

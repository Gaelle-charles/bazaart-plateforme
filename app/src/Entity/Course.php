<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CourseLevel;
use App\Repository\CourseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Course — La formation complète sur la plateforme Bazaart.
 *
 * Une formation est la brique de contenu principale du module Formation (CDC V3 §5.7).
 * Elle regroupe :
 *   - Des informations de présentation (titre, sous-titre, description, visuels)
 *   - Des informations sur le formateur
 *   - Un catalogue de modules (CourseModule) contenant des leçons (Lesson)
 *   - Une liste d'inscriptions (CourseEnrollment) pour le suivi des apprenants
 *
 * Cycle de vie :
 *   is_published = false  → formation en construction, invisible publiquement
 *   is_published = true   → publiée, accessible aux inscrits (et en preview pour isFreePreview)
 *
 * Le slug est généré par CourseService::generateSlug() — jamais dans l'entité,
 * pour respecter le principe "thin entity" et permettre la gestion des doublons.
 *
 * Intégration vidéo :
 *   - trailerVideoUrl : URL iframe pour le teaser (YouTube, Vimeo, Bunny Stream embed)
 *   - Les vidéos de leçons individuelles sont dans Lesson.videoBunnyId / videoUrl
 */
#[ORM\Entity(repositoryClass: CourseRepository::class)]
#[ORM\Table(name: 'courses')]
#[ORM\HasLifecycleCallbacks]
class Course
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Identifiants et métadonnées ──────────────────────────────────────────

    /**
     * Slug URL-friendly unique, ex : "initiation-musique-afrobeats".
     * Généré depuis le titre par CourseService::generateSlug().
     * Unique en base pour éviter les collisions de routes /formations/{slug}.
     *
     * length: 255 = cohérent avec les autres slugs du projet (ForumThread).
     */
    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: false)]
    private string $slug;

    /**
     * Titre principal affiché sur la page de vente et dans le catalogue.
     * Ex : "Introduction à la musique afrobeats : rythme et composition"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $title;

    /**
     * Accroche courte sous le titre principal (optionnelle).
     * Ex : "Apprends les bases des percussions et de la production en 5 semaines"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $subtitle = null;

    /**
     * Description complète de la formation (objectifs, programme, public cible).
     * Type text = pas de limite de longueur côté base.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    // ─── Visuels et médias ────────────────────────────────────────────────────

    /**
     * Chemin relatif de l'image de couverture (thumbnail du catalogue).
     * Ex : "uploads/courses/covers/afrobeats-cover.jpg"
     * null si aucune image uploadée (affichage d'un placeholder côté Twig).
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $coverImage = null;

    /**
     * URL du trailer/teaser vidéo de la formation.
     * Accepte les URLs iframe : YouTube embed, Vimeo embed, Bunny Stream embed.
     * Ex : "https://iframe.mediadelivery.net/embed/123456/video-id"
     * length: 500 pour les URLs signées Bunny qui peuvent être longues.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $trailerVideoUrl = null;

    // ─── Informations sur le formateur ────────────────────────────────────────

    /**
     * Nom affiché du formateur / de la formatrice.
     * Ex : "Wendie Zahibo" ou "Collectif Goumies_créatives"
     * Champ libre pour permettre des noms de collectif ou de scène.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $instructorName = null;

    /**
     * Biographie courte du formateur, présentée sur la page de la formation.
     * Type text pour permettre un contenu plus long (parcours, références).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $instructorBio = null;

    /**
     * Chemin relatif de la photo de profil du formateur.
     * Ex : "uploads/courses/instructors/wendie-zahibo.jpg"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $instructorAvatar = null;

    // ─── Caractéristiques pédagogiques ────────────────────────────────────────

    /**
     * Durée totale de la formation en minutes (somme des durées des leçons).
     * Calculée manuellement par l'admin via CourseService::recalculateDuration().
     * Nullable car elle peut ne pas être encore calculée lors de la création.
     *
     * Pourquoi stocker et ne pas calculer à la volée ?
     * Ça évite une jointure récursive coûteuse (Course → Modules → Lessons)
     * à chaque affichage du catalogue. Une dénormalisation acceptable.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationMinutesTotal = null;

    /**
     * Niveau requis pour suivre la formation — cf. App\Enum\CourseLevel.
     *
     * Doctrine stocke la valeur backing string de l'enum : 'beginner', etc.
     * Le paramètre enumType indique à Doctrine de reconstruire l'enum à la lecture.
     * Default : BEGINNER (le niveau le plus accessible = comportement par défaut sûr).
     */
    #[ORM\Column(type: 'string', length: 20, enumType: CourseLevel::class, options: ['default' => 'beginner'])]
    private CourseLevel $level = CourseLevel::BEGINNER;

    // ─── Publication ──────────────────────────────────────────────────────────

    /**
     * La formation est-elle visible publiquement ?
     * false → brouillon (admin seulement)
     * true  → publiée, visible dans le catalogue et accessible aux inscrits
     *
     * La publication se fait via CourseService::publish() qui valide les prérequis
     * (au moins un module avec une leçon) avant de passer is_published à true.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPublished = false;

    /**
     * Horodatage de la première publication.
     * null tant que la formation n'a jamais été publiée.
     * Reste figé même si la formation est dépubliée puis republiée.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date/heure de création de l'enregistrement.
     * Initialisée par #[ORM\PrePersist] — ne change jamais ensuite.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $createdAt;

    /**
     * Date/heure de la dernière modification.
     * Initialisée au moment de la création, mise à jour à chaque modification.
     * nullable: true → compat avec des lignes éventuellement importées sans updatedAt.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * Modules composant cette formation, dans l'ordre défini par orderPosition.
     *
     * cascade: ['persist', 'remove'] :
     *   - persist → sauvegarder un Course sauvegarde aussi ses modules attachés
     *   - remove  → supprimer un Course supprime aussi ses modules (et par cascade
     *               leurs leçons, via orphanRemoval de CourseModule)
     *
     * orphanRemoval: true → si un module est retiré de cette collection
     * (ex : $course->getModules()->removeElement($module)), Doctrine le
     * supprime physiquement en base lors du flush(). Évite les "orphelins".
     *
     * @var Collection<int, CourseModule>
     */
    #[ORM\OneToMany(
        mappedBy: 'course',
        targetEntity: CourseModule::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['orderPosition' => 'ASC'])]
    private Collection $modules;

    /**
     * Inscriptions des apprenants à cette formation.
     *
     * orphanRemoval: true → supprimer une inscription de la collection
     * la supprime aussi en base (utile pour le désabonnement).
     *
     * @var Collection<int, CourseEnrollment>
     */
    #[ORM\OneToMany(
        mappedBy: 'course',
        targetEntity: CourseEnrollment::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $enrollments;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    public function __construct()
    {
        // Initialiser les collections évite les null check partout dans le code
        $this->modules     = new ArrayCollection();
        $this->enrollments = new ArrayCollection();
    }

    // ─── Callbacks de cycle de vie ────────────────────────────────────────────

    /**
     * Appelé automatiquement par Doctrine juste avant l'INSERT initial.
     * On initialise les deux timestamps au même instant.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Appelé automatiquement par Doctrine juste avant chaque UPDATE.
     * Met à jour updatedAt pour refléter la date de la dernière modification.
     */
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
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

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function setSubtitle(?string $subtitle): static
    {
        $this->subtitle = $subtitle;
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

    public function getCoverImage(): ?string
    {
        return $this->coverImage;
    }

    public function setCoverImage(?string $coverImage): static
    {
        $this->coverImage = $coverImage;
        return $this;
    }

    public function getTrailerVideoUrl(): ?string
    {
        return $this->trailerVideoUrl;
    }

    public function setTrailerVideoUrl(?string $trailerVideoUrl): static
    {
        $this->trailerVideoUrl = $trailerVideoUrl;
        return $this;
    }

    public function getInstructorName(): ?string
    {
        return $this->instructorName;
    }

    public function setInstructorName(?string $instructorName): static
    {
        $this->instructorName = $instructorName;
        return $this;
    }

    public function getInstructorBio(): ?string
    {
        return $this->instructorBio;
    }

    public function setInstructorBio(?string $instructorBio): static
    {
        $this->instructorBio = $instructorBio;
        return $this;
    }

    public function getInstructorAvatar(): ?string
    {
        return $this->instructorAvatar;
    }

    public function setInstructorAvatar(?string $instructorAvatar): static
    {
        $this->instructorAvatar = $instructorAvatar;
        return $this;
    }

    public function getDurationMinutesTotal(): ?int
    {
        return $this->durationMinutesTotal;
    }

    public function setDurationMinutesTotal(?int $durationMinutesTotal): static
    {
        $this->durationMinutesTotal = $durationMinutesTotal;
        return $this;
    }

    public function getLevel(): CourseLevel
    {
        return $this->level;
    }

    public function setLevel(CourseLevel $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeInterface $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    // ─── Méthodes de collection : modules ─────────────────────────────────────

    /**
     * @return Collection<int, CourseModule>
     */
    public function getModules(): Collection
    {
        return $this->modules;
    }

    /**
     * Ajoute un module à la formation et synchronise la relation inverse
     * (CourseModule.$course) pour que les deux côtés restent cohérents.
     */
    public function addModule(CourseModule $module): static
    {
        if (!$this->modules->contains($module)) {
            $this->modules->add($module);
            $module->setCourse($this);
        }
        return $this;
    }

    /**
     * Retire un module de la collection.
     * Grâce à orphanRemoval: true, Doctrine le supprimera en base au prochain flush().
     */
    public function removeModule(CourseModule $module): static
    {
        $this->modules->removeElement($module);
        return $this;
    }

    // ─── Méthodes de collection : enrollments ─────────────────────────────────

    /**
     * @return Collection<int, CourseEnrollment>
     */
    public function getEnrollments(): Collection
    {
        return $this->enrollments;
    }

    /**
     * Crée l'association entre un apprenant et cette formation.
     * En pratique, l'inscription se fait via CourseEnrollmentService pour
     * vérifier les doublons et envoyer l'email de confirmation.
     */
    public function addEnrollment(CourseEnrollment $enrollment): static
    {
        if (!$this->enrollments->contains($enrollment)) {
            $this->enrollments->add($enrollment);
            $enrollment->setCourse($this);
        }
        return $this;
    }

    /**
     * Retire une inscription de la collection.
     * orphanRemoval: true → la ligne sera supprimée en base au flush().
     */
    public function removeEnrollment(CourseEnrollment $enrollment): static
    {
        $this->enrollments->removeElement($enrollment);
        return $this;
    }
}

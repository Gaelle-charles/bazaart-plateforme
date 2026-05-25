<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LessonResourceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * LessonResource — Fichier téléchargeable attaché à une leçon.
 *
 * Permet aux formateurs de joindre des supports pédagogiques à leurs leçons :
 *   - Support de cours au format PDF
 *   - Partition musicale (PDF)
 *   - Fiche de travaux pratiques
 *   - Fichier de projet (ex : fichier Ableton, preset)
 *
 * Les fichiers sont stockés dans /uploads/ sur le serveur (chemin relatif
 * stocké dans filePath). En V2, on pourrait migrer vers un bucket S3/DigitalOcean
 * Spaces sans toucher à cette entité (seul le service d'upload changerait).
 *
 * Cette entité n'a pas de lifecycle callbacks car elle n'a pas de timestamps V1.
 * L'audit se fait via la leçon parente (qui n'en a pas non plus — via la formation).
 */
#[ORM\Entity(repositoryClass: LessonResourceRepository::class)]
#[ORM\Table(name: 'lesson_resources')]
class LessonResource
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Contenu ──────────────────────────────────────────────────────────────

    /**
     * Nom affiché du fichier dans l'interface apprenant.
     * Ex : "Support de cours — Module 2" ou "Partition : Clave 6/8"
     * Différent du nom du fichier physique (qui peut être un UUID anonymisé).
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $title;

    /**
     * Chemin relatif du fichier dans le répertoire /uploads/.
     * Ex : "uploads/lessons/resources/support-module-2-a1b2c3.pdf"
     *
     * length: 500 pour absorber les chemins avec sous-répertoires.
     * Stocké en relatif pour permettre de changer le chemin racine (migration
     * serveur) sans mettre à jour la BDD.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: false)]
    private string $filePath;

    /**
     * Taille du fichier en octets.
     * Ex : 1048576 = 1 Mo
     * null si non renseignée (cas d'import ou d'erreur à l'upload).
     * Affichée formatée dans l'interface : LessonResourceService::formatFileSize().
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fileSize = null;

    /**
     * Type MIME du fichier.
     * Ex : "application/pdf", "audio/mpeg", "application/zip"
     * Utilisé pour afficher l'icône appropriée et vérifier le type avant download.
     * length: 100 couvre tous les MIME types standards (le plus long officiel : ~50 chars).
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $mimeType = null;

    // ─── Relation ─────────────────────────────────────────────────────────────

    /**
     * Leçon à laquelle est rattachée cette ressource.
     *
     * nullable: false → une ressource de leçon doit toujours appartenir à une leçon.
     * onDelete: 'CASCADE' → si la leçon est supprimée directement en SQL,
     * ses ressources attachées le sont aussi (protection BDD en complément
     * de l'orphanRemoval géré côté Lesson).
     */
    #[ORM\ManyToOne(targetEntity: Lesson::class, inversedBy: 'resources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Lesson $lesson;

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

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;
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
}

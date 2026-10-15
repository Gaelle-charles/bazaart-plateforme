<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use App\Entity\AssociationProject;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AssociationProjectTextImages : images insérées dans le texte « Le projet en
 * détail » d'une fiche (ADR-0039, complément d'octobre 2026).
 *
 * Chaque fiche a SON dossier (texte/<numéro de la fiche>/, dans le dossier privé
 * des images). Le texte ne cite une image que par son nom de fichier ; c'est la
 * présence du fichier dans le dossier de LA fiche qui prouve qu'elle lui
 * appartient. Conséquences :
 *   - une image collée depuis le texte d'une autre fiche est retirée à
 *     l'enregistrement (son fichier n'est pas dans ce dossier) ;
 *   - effacer les images retirées d'un texte ne peut jamais toucher une autre fiche ;
 *   - supprimer une fiche supprime son dossier entier.
 *
 * Validation (5 Mo, JPEG/PNG/WebP, vrai type lu dans le fichier) : celle de
 * AssociationProjectImageStorage, réutilisée telle quelle.
 */
final class AssociationProjectTextImages
{
    public function __construct(
        private readonly AssociationProjectImageStorage $imageStorage,
    ) {
    }

    /**
     * Enregistre une image pour le texte de la fiche.
     * Renvoie son nom de fichier (à écrire dans data-ap-image) et ses dimensions,
     * ou un message d'erreur en français.
     */
    public function store(AssociationProject $project, UploadedFile $file): StoredImage|string
    {
        $stored = $this->imageStorage->store($file, $this->directory($project));
        if (is_string($stored)) {
            return $stored;
        }

        return new StoredImage(basename($stored->path), $stored->width, $stored->height);
    }

    /** Chemin absolu d'une image du texte de la fiche, ou null si elle n'existe pas. */
    public function absolutePath(AssociationProject $project, string $name): ?string
    {
        if (preg_match(AssociationProjectRichText::IMAGE_NAME_PATTERN, $name) !== 1) {
            return null;
        }

        return $this->imageStorage->absolutePath($this->directory($project) . '/' . $name);
    }

    public function exists(AssociationProject $project, string $name): bool
    {
        return $this->absolutePath($project, $name) !== null;
    }

    /**
     * Efface du disque les images qui étaient dans l'ancien texte et n'y sont plus.
     *
     * @param list<string> $before noms présents avant l'enregistrement
     * @param list<string> $after  noms présents après
     */
    public function deleteRemoved(AssociationProject $project, array $before, array $after): void
    {
        foreach (array_diff($before, $after) as $name) {
            $absolute = $this->absolutePath($project, $name);
            if ($absolute !== null) {
                unlink($absolute);
            }
        }
    }

    /** Efface toutes les images du texte de la fiche, et son dossier (suppression de la fiche). */
    public function deleteAll(AssociationProject $project): void
    {
        if ($project->getId() === null) {
            return;
        }
        $directory = $this->imageStorage->absoluteDirectory($this->directory($project));
        if ($directory === null) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            $absolute = $this->absolutePath($project, $entry);
            if ($absolute !== null) {
                unlink($absolute);
            }
        }
        // Le dossier n'est retiré que s'il est vide (rien d'autre n'y est jamais écrit).
        @rmdir($directory);
    }

    private function directory(AssociationProject $project): string
    {
        $id = $project->getId();
        if ($id === null) {
            throw new \LogicException("La fiche doit être enregistrée avant d'y ajouter des images.");
        }

        return 'texte/' . $id;
    }
}

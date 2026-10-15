<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AssociationProjectDocumentStorage : stockage PRIVÉ des documents PDF joints aux
 * fiches « Projets en cours » (ADR-0039, complément d'octobre 2026).
 *
 * Même principe que AssociationProjectImageStorage (et que CreatorDocumentStorage
 * pour les PDF) : fichier rangé HORS du dossier public, nom aléatoire, vrai type
 * contrôlé sur le contenu du fichier. Seul PHP le renvoie, après avoir vérifié le
 * jeton secret (page partenaires) ou le rôle admin (back-office).
 *
 * POURQUOI 10 Mo (et pas 5 comme les images) ?
 *   Un dossier de présentation avec photos dépasse vite 5 Mo. 10 Mo est la limite
 *   d'envoi déjà réglée sur le serveur de production (upload_max_filesize).
 */
final class AssociationProjectDocumentStorage
{
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        /**
         * Dossier privé, injecté par services.yaml :
         * %association_projects.storage_dir%/documents (un dossier par environnement).
         */
        private readonly string $storageDir,
    ) {
    }

    /**
     * Valide puis enregistre un PDF téléversé.
     *
     * Contrat de retour :
     *   - StoredDocument → document enregistré ;
     *   - string         → message d'erreur en français, à afficher à l'utilisateur.
     */
    public function store(UploadedFile $file): StoredDocument|string
    {
        if (!$file->isValid()) {
            return "Le document n'a pas pu être envoyé (fichier trop lourd ou envoi interrompu). Il doit faire moins de 10 Mo.";
        }

        $size = $file->getSize();
        if ($size > self::MAX_SIZE_BYTES) {
            return 'Le document ne doit pas dépasser 10 Mo.';
        }
        if ($size < 5) {
            return 'Ce fichier est vide.';
        }

        // Deux contrôles indépendants du nom du fichier : le type lu par finfo,
        // puis la signature « %PDF- » au tout début du fichier.
        if ($file->getMimeType() !== 'application/pdf' || !$this->startsLikePdf($file->getPathname())) {
            return 'Format non accepté : seuls les fichiers PDF sont acceptés.';
        }

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            return "Impossible d'enregistrer le document (dossier de stockage inaccessible).";
        }

        $filename = bin2hex(random_bytes(16)) . '.pdf';

        try {
            $file->move($this->storageDir, $filename);
        } catch (FileException) {
            return "Impossible d'enregistrer le document. Merci de réessayer.";
        }

        return new StoredDocument($filename, (int) $size);
    }

    /**
     * Chemin ABSOLU d'un document enregistré, ou null s'il n'existe pas.
     * Même garde-fou que AssociationProjectImageStorage::absolutePath() :
     * le fichier doit être STRICTEMENT dans le dossier privé.
     */
    public function absolutePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $baseReal = realpath($this->storageDir);
        $fileReal = realpath($this->storageDir . DIRECTORY_SEPARATOR . $path);

        if ($baseReal === false || $fileReal === false || !is_file($fileReal)) {
            return null;
        }

        if (!str_starts_with($fileReal, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $fileReal;
    }

    /** Supprime un document du disque (rien ne se passe s'il n'existe plus). */
    public function delete(?string $path): void
    {
        $absolute = $this->absolutePath($path);
        if ($absolute !== null) {
            unlink($absolute);
        }
    }

    private function startsLikePdf(string $pathname): bool
    {
        $handle = @fopen($pathname, 'rb');
        if ($handle === false) {
            return false;
        }
        $start = fread($handle, 5);
        fclose($handle);

        return $start === '%PDF-';
    }
}

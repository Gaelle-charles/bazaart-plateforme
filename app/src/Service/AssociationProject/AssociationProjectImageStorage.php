<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AssociationProjectImageStorage : stockage PRIVÉ des images des projets de
 * l'association (visuel principal + images des blocs de présentation), ADR-0039.
 *
 * Réutilise les deux mécanismes d'upload déjà présents dans le projet :
 *   1. la VALIDATION de ForumImageService : on contrôle le vrai type du fichier
 *      (les premiers octets, lus par finfo) et non son extension ; 5 Mo maximum ;
 *   2. le STOCKAGE HORS DU DOSSIER PUBLIC de CreatorDocumentStorage
 *      (var/secure_uploads/…) : Nginx ne peut jamais servir ces fichiers
 *      directement, seul PHP les envoie, après avoir vérifié le jeton secret
 *      (page partenaires) ou le rôle admin (back-office).
 *
 * POURQUOI PAS public/uploads/ comme le forum ?
 *   En développement, public/uploads est servi tel quel : un visuel serait visible
 *   sans jeton. Hors du dossier public, la règle est la même partout.
 *
 * POURQUOI UN NOUVEAU SERVICE plutôt que de modifier ForumImageService ?
 *   Pour ne pas toucher au forum (consigne : aucune autre page modifiée).
 *
 * Pas de redimensionnement : l'extension GD n'est pas installée dans l'image Docker.
 * On mesure seulement les dimensions avec getimagesize() (fonction de base de PHP,
 * sans GD) pour écrire width/height sur les <img> et éviter que la page « saute ».
 */
final class AssociationProjectImageStorage
{
    /** 5 Mo, comme les images du forum et les avatars (CDC V3 §4.4). */
    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    /**
     * Garde-fou : au-delà, l'image ferait ramer les navigateurs (une photo de
     * 8000 px décompressée pèse plus de 200 Mo en mémoire). Une photo de téléphone
     * (environ 4000 px) passe.
     */
    public const MAX_DIMENSION_PX = 8000;

    /**
     * Types acceptés → extension du fichier enregistré.
     * Pas de GIF ni de SVG (le SVG peut contenir du JavaScript).
     *
     * @var array<string, string>
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        /**
         * Dossier privé, injecté par services.yaml :
         * %kernel.project_dir%/var/secure_uploads/association-projects/%kernel.environment%
         * (un sous-dossier par environnement : les tests n'écrivent jamais dans le dossier de dev).
         */
        private readonly string $storageDir,
    ) {
    }

    /**
     * Valide puis enregistre une image téléversée.
     *
     * Contrat de retour :
     *   - StoredImage → image enregistrée (nom de fichier + dimensions) ;
     *   - string      → message d'erreur en français, à afficher à l'utilisateur.
     */
    public function store(UploadedFile $file): StoredImage|string
    {
        // ── 1. Le téléversement s'est-il bien passé ? ────────────────────────
        // isValid() est faux si PHP a refusé le fichier (trop gros pour php.ini,
        // envoi interrompu…). Dans ce cas, getMimeType() n'aurait rien à lire.
        if (!$file->isValid()) {
            return "L'image n'a pas pu être envoyée (fichier trop lourd ou envoi interrompu). Elle doit faire moins de 5 Mo.";
        }

        // ── 2. Taille ─────────────────────────────────────────────────────────
        // getSize() lit la taille réelle sur le disque du serveur (fiable).
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return "L'image ne doit pas dépasser 5 Mo.";
        }

        // ── 3. Vrai type du fichier ───────────────────────────────────────────
        // getMimeType() lit les premiers octets (finfo) : un fichier PHP renommé
        // « photo.jpg » est détecté comme text/x-php et refusé.
        $mimeType = $file->getMimeType();
        if ($mimeType === null || !isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            return 'Format non accepté. Formats acceptés : JPEG, PNG, WebP.';
        }

        // ── 4. Dimensions ─────────────────────────────────────────────────────
        // getimagesize() échoue sur un fichier qui n'est pas vraiment une image :
        // second contrôle, indépendant du premier.
        $size = @getimagesize($file->getPathname());
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return "Ce fichier n'est pas une image lisible.";
        }
        [$width, $height] = $size;
        if ($width > self::MAX_DIMENSION_PX || $height > self::MAX_DIMENSION_PX) {
            return sprintf(
                "L'image est trop grande (%d × %d px). Maximum : %d px de côté ; 1600 px de large suffisent.",
                $width,
                $height,
                self::MAX_DIMENSION_PX,
            );
        }

        // ── 5. Dossier de destination ─────────────────────────────────────────
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            return "Impossible d'enregistrer l'image (dossier de stockage inaccessible).";
        }

        // ── 6. Nom de fichier aléatoire et non devinable ──────────────────────
        // random_bytes() est cryptographiquement sûr (contrairement à uniqid(),
        // qui dépend de l'heure). L'extension vient du VRAI type, pas du nom d'origine.
        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME_TYPES[$mimeType];

        try {
            $file->move($this->storageDir, $filename);
        } catch (FileException) {
            return "Impossible d'enregistrer l'image. Merci de réessayer.";
        }

        return new StoredImage($filename, $width, $height);
    }

    /**
     * Chemin ABSOLU d'une image enregistrée, ou null si elle n'existe pas.
     *
     * Protection contre le « path traversal » (même méthode que
     * ForumImageService::getAbsolutePathIfExists) : realpath() résout les « .. »,
     * puis on exige que le fichier soit STRICTEMENT dans le dossier privé.
     * Même si la valeur vient de la BDD, on ne fait confiance à rien.
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

    /** Supprime une image du disque (rien ne se passe si elle n'existe plus). */
    public function delete(?string $path): void
    {
        $absolute = $this->absolutePath($path);
        if ($absolute !== null) {
            unlink($absolute);
        }
    }
}

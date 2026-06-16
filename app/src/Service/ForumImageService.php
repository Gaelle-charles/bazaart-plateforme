<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ForumImageService — gestion des images attachées aux threads et réponses du forum.
 *
 * Pourquoi un service dédié plutôt qu'une méthode dans ForumService ?
 *   Principe de responsabilité unique (S de SOLID) :
 *   - ForumService = logique métier du forum (threads, réponses, modération)
 *   - ForumImageService = gestion des fichiers image sur le disque
 *   On peut ainsi tester, remplacer ou étendre la gestion des images
 *   sans modifier la logique métier du forum.
 *
 * Pattern d'upload identique à ArtistProfileService::saveProfile() :
 *   - UploadedFile->move() vers public/uploads/
 *   - Nom unique via uniqid() pour éviter les collisions
 *   - Chemin RELATIF stocké en BDD (ex: 'uploads/forum/xyz.jpg')
 *   - Chemin ABSOLU construit à la volée : $projectDir . '/public/' . $path
 *
 * Sécurité (aligné §4.4 CDC V3) :
 *   - Validation MIME TYPE côté serveur (jamais l'extension seule)
 *   - getMimeType() utilise finfo pour lire les magic bytes réels du fichier
 *   - Taille max 5 MB (même limite que l'avatar profil)
 */
class ForumImageService
{
    /**
     * Dossier de stockage des images du forum, relatif à public/.
     * Les images seront accessibles à l'URL /uploads/forum/xxx.jpg.
     */
    private const FORUM_UPLOAD_DIR = 'uploads/forum';

    /**
     * Taille maximale d'une image en octets (5 MB = 5 * 1024 * 1024).
     * Aligné sur la limite de l'avatar profil (§4.4 CDC V3).
     */
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    /**
     * Types MIME autorisés pour les images du forum.
     *
     * IMPORTANT : on valide le MIME TYPE réel (magic bytes lus par finfo),
     * PAS l'extension du fichier. Un attaquant peut nommer un fichier PHP "image.jpg"
     * — l'extension ne prouve rien. getMimeType() lit les vrais premiers octets.
     *
     * GIF est autorisé pour les GIF animés (expression culturelle diaspora).
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * $projectDir est le chemin absolu vers la racine du projet Symfony.
     * Injecté via services.yaml (même pattern que ArtistProfileService).
     * Dans le container Docker : /var/www/html
     */
    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * Valide et déplace un fichier image uploadé vers public/uploads/forum/.
     *
     * Contrat de retour (toujours un string, jamais null) :
     *   - string commençant par 'uploads/' → chemin relatif valide, image sauvegardée
     *   - autre string → message d'erreur à afficher à l'utilisateur
     *
     * L'appelant distingue les deux cas avec str_starts_with($retour, 'uploads/').
     * On ne retourne pas null pour éviter l'ambiguïté avec le cas "pas de fichier"
     * (géré en amont par l'appelant : il n'appelle handleUpload() que si un fichier existe).
     */
    public function handleUpload(UploadedFile $file): string
    {
        // ── Validation de la taille ───────────────────────────────────────────
        // getSize() retourne la taille réelle du fichier côté serveur (fiable).
        // On ne fait pas confiance à $_FILES['size'] qui vient du client.
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            $maxMb = (int) (self::MAX_SIZE_BYTES / 1024 / 1024);
            return sprintf("L'image ne doit pas dépasser %d MB.", $maxMb);
        }

        // ── Validation du type MIME côté serveur ──────────────────────────────
        // getMimeType() utilise la fonction PHP finfo_file() qui lit les magic bytes
        // (les premiers octets du fichier réel), pas l'extension ou le Content-Type
        // envoyé par le navigateur (que l'attaquant peut falsifier).
        //
        // Exemple : un fichier PHP renommé "evil.jpg" aura getMimeType() = 'text/x-php'
        // → refusé, car 'text/x-php' n'est pas dans ALLOWED_MIME_TYPES.
        $mimeType = $file->getMimeType();

        if ($mimeType === null || !in_array($mimeType, self::ALLOWED_MIME_TYPES, strict: true)) {
            return 'Format d\'image non autorisé. Formats acceptés : JPEG, PNG, WebP, GIF.';
        }

        // ── Préparation du dossier de destination ─────────────────────────────
        // On s'assure que le dossier uploads/forum/ existe.
        // recursive: true → crée aussi uploads/ si besoin.
        // 0775 → rwxrwxr-x (le serveur web peut lire et écrire).
        $uploadDir = $this->projectDir . '/public/' . self::FORUM_UPLOAD_DIR;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        // ── Génération d'un nom de fichier unique ─────────────────────────────
        // uniqid('forum_', more_entropy: true) génère un nom comme 'forum_6481abc12345_0.1234'
        // more_entropy: true ajoute de l'entropie pour réduire encore plus
        // le risque de collision si deux uploads arrivent simultanément.
        //
        // guessExtension() déduit l'extension depuis le MIME TYPE réel
        // (pas depuis le nom du fichier original) → cohérent avec la validation MIME.
        $filename = uniqid('forum_', more_entropy: true) . '.' . $file->guessExtension();

        // ── Déplacement du fichier ────────────────────────────────────────────
        // move() déplace le fichier depuis le répertoire temporaire vers la destination.
        // Il peut lever une FileException si le déplacement échoue (dossier non
        // accessible en écriture, disque plein...). On l'intercepte pour renvoyer
        // un message d'erreur exploitable plutôt que de laisser remonter une 500.
        try {
            $file->move($uploadDir, $filename);
        } catch (FileException) {
            return "Impossible d'enregistrer l'image. Veuillez réessayer.";
        }

        // Retourne le chemin RELATIF à public/ (ce qui est stocké en BDD)
        // Exemple : 'uploads/forum/forum_6481abc12345_0.1234.jpg'
        return self::FORUM_UPLOAD_DIR . '/' . $filename;
    }

    /**
     * Supprime un fichier image du disque s'il existe.
     *
     * Appelé lors de la suppression d'un thread/reply.
     *
     * @param string|null $imagePath Chemin RELATIF stocké en BDD (ex: 'uploads/forum/xyz.jpg')
     *                               ou null si aucune image n'est associée.
     */
    public function deleteImage(?string $imagePath): void
    {
        // Si aucune image associée, on ne fait rien
        if ($imagePath === null || $imagePath === '') {
            return;
        }

        // On reconstruit le chemin absolu à partir du chemin relatif stocké en BDD
        $absolutePath = $this->projectDir . '/public/' . $imagePath;

        // is_file() vérifie que le chemin existe ET que c'est bien un fichier (pas un dossier)
        // unlink() supprime le fichier — on ignore silencieusement les erreurs
        // (le fichier peut ne plus exister si déjà supprimé manuellement ou en double).
        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }
}

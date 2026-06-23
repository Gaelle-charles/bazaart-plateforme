<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * CreatorDocumentStorage — gestion sécurisée des pièces d'identité des créateurs.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * POURQUOI STOCKER HORS DU DOSSIER PUBLIC ?
 * ────────────────────────────────────────────────────────────────────────────────
 * Dans Symfony, tout ce qui est dans `public/` est accessible directement via une URL.
 * Exemple : `public/uploads/ma_cni.pdf` → accessible sur `https://bazaart.fr/uploads/ma_cni.pdf`
 * sans aucune vérification d'authentification ou d'autorisation.
 *
 * Or une pièce d'identité (CNI, passeport) est une donnée à caractère personnel très sensible
 * (RGPD : données biométriques). La stocker sous `public/` exposerait ces documents à :
 *   - N'importe qui connaissant ou devinant l'URL
 *   - Les robots d'indexation Google, Bing, etc.
 *   - Un attaquant qui parcourait les fichiers en devinant les noms
 *
 * SOLUTION : stocker dans `%kernel.project_dir%/var/secure_uploads/creator-docs/`
 *   - Ce dossier est SOUS la racine du projet mais EN DEHORS de `public/`.
 *   - Nginx ne sert jamais ce dossier directement (sa racine est `public/`).
 *   - L'accès se fait UNIQUEMENT via PHP (BinaryFileResponse dans le contrôleur admin).
 *   - Ce dossier est exclu du versioning git via `.gitignore` (`app/var/secure_uploads/`).
 *
 * NOM DE FICHIER NON DEVINABLE :
 *   On génère un nom aléatoire avec uniqid() + bin2hex(random_bytes()) au lieu de
 *   conserver le nom original. Ainsi même si quelqu'un devinait le chemin, il ne
 *   pourrait pas accéder au fichier sans connaître le nom aléatoire.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * TYPES DE FICHIERS AUTORISÉS :
 *   - image/jpeg (.jpg, .jpeg)
 *   - image/png  (.png)
 *   - application/pdf (.pdf)
 *
 * TAILLE MAXIMALE : 5 Mo (5 242 880 octets). Au-delà, une exception est levée.
 */
class CreatorDocumentStorage
{
    /**
     * Dossier relatif (dans le dossier sécurisé) pour les pièces d'identité créateurs.
     * Valeur stockée en BDD comme préfixe du chemin relatif.
     */
    private const SUBDIR = 'creator-docs';

    /**
     * Types MIME autorisés pour les pièces d'identité.
     * On n'accepte pas les formats Office (Word, Excel) ou les images vectorielles (SVG)
     * car ils peuvent contenir des macros ou du code JavaScript embarqué.
     *
     * @var array<string, string> MIME type → extension de fichier
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'application/pdf' => 'pdf',
    ];

    /** Taille maximale du fichier en octets (5 Mo). */
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 242 880 octets

    /**
     * Chemin absolu du dossier sécurisé racine (ex : /var/www/bazaart/var/secure_uploads/).
     *
     * Injecté via le paramètre Symfony 'kernel.project_dir' + '/var/secure_uploads'
     * dans config/services.yaml (voir la note de wiring dans ce fichier).
     *
     * On injecte le chemin absolu complet via le constructeur pour deux raisons :
     *   1. Testabilité : en tests, on peut injecter un dossier temporaire (sys_get_temp_dir())
     *   2. Portabilité : pas de dépendance sur un chemin codé en dur
     */
    public function __construct(
        private readonly string $secureUploadsDir,
    ) {}

    /**
     * Stocke une pièce d'identité uploadée dans le dossier sécurisé.
     *
     * Processus :
     *   1. Valide le type MIME (via finfo, pas seulement l'extension déclarée par le client)
     *   2. Valide la taille (max 5 Mo)
     *   3. Génère un nom de fichier aléatoire non devinable
     *   4. Déplace le fichier temporaire PHP vers le dossier sécurisé
     *   5. Retourne le chemin relatif à stocker en BDD
     *
     * @throws \InvalidArgumentException Si le type MIME ou la taille n'est pas autorisé.
     * @throws \RuntimeException Si le dossier ne peut pas être créé ou le fichier déplacé.
     *
     * @return string Chemin RELATIF (ex : "creator-docs/a3f8b2c1d4e5f6g7.pdf")
     *                → à stocker dans CreatorPayoutProfile::$identityDocumentPath
     */
    public function store(UploadedFile $file, User $user): string
    {
        // ── 1. Validation du type MIME ────────────────────────────────────────
        // IMPORTANT : on utilise getMimeType() qui lit les octets du fichier (via finfo),
        // pas getClientMimeType() qui retourne ce que le navigateur déclare.
        // Un attaquant pourrait renommer un fichier .exe en .pdf et déclarer image/jpeg.
        // finfo_file() lit la signature magique (magic bytes) du fichier pour le vrai type.
        $mimeType = $file->getMimeType();
        if ($mimeType === null || !isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            $allowed = implode(', ', array_keys(self::ALLOWED_MIME_TYPES));
            throw new \InvalidArgumentException(
                sprintf(
                    'Type de fichier non autorisé (%s). Types acceptés : %s.',
                    $mimeType ?? 'inconnu',
                    $allowed
                )
            );
        }

        // ── 2. Validation de la taille ────────────────────────────────────────
        // getSize() retourne la taille en octets du fichier temporaire.
        $size = $file->getSize();
        if ($size === false || $size > self::MAX_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Fichier trop volumineux (%s Mo). La taille maximum est 5 Mo.',
                    $size !== false ? round($size / 1024 / 1024, 1) : '?'
                )
            );
        }

        // ── 3. Génération d'un nom de fichier aléatoire ───────────────────────
        // uniqid() : identifiant basé sur le timestamp (microsecondes)
        // bin2hex(random_bytes(8)) : 8 octets aléatoires cryptographiquement sûrs → 16 hex chars
        // Combinés, ils donnent un nom de ~30 chars impossible à deviner.
        // On ajoute l'ID utilisateur pour faciliter le débogage en cas de problème admin.
        $extension = self::ALLOWED_MIME_TYPES[$mimeType];
        $fileName  = sprintf(
            'user%d_%s_%s.%s',
            $user->getId() ?? 0,
            uniqid('', true),
            bin2hex(random_bytes(8)),
            $extension
        );

        // ── 4. Création du dossier si nécessaire + déplacement du fichier ─────
        $targetDir = $this->secureUploadsDir . DIRECTORY_SEPARATOR . self::SUBDIR;

        // Crée récursivement le dossier avec permissions 0750 (lecture/écriture propriétaire,
        // lecture groupe, rien pour les autres). PHP FPM tourne généralement sous www-data.
        if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(
                'Impossible de créer le dossier de stockage sécurisé : ' . $targetDir
            );
        }

        try {
            // move() déplace le fichier temporaire PHP (/tmp/phpXXXXXX) vers la cible.
            // Après ce déplacement, le fichier temporaire n'existe plus.
            $file->move($targetDir, $fileName);
        } catch (FileException $e) {
            throw new \RuntimeException(
                'Erreur lors du déplacement du fichier d\'identité : ' . $e->getMessage(),
                0,
                $e
            );
        }

        // ── 5. Retourne le chemin RELATIF à stocker en BDD ────────────────────
        // On stocke le chemin RELATIF (pas absolu) pour que la BDD reste portable
        // si le projet est déplacé ou le chemin du serveur change.
        // getAbsolutePath() reconstruit le chemin absolu depuis ce relatif.
        return self::SUBDIR . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * Retourne le chemin ABSOLU d'un fichier à partir de son chemin relatif en BDD.
     *
     * Utilisation : dans AdminCreatorPayoutController#document() pour créer
     * une BinaryFileResponse afin de streamer le fichier à l'admin.
     *
     * Exemple :
     *   $relativePath = "creator-docs/user42_xxx.pdf"
     *   → retourne "/var/www/bazaart/var/secure_uploads/creator-docs/user42_xxx.pdf"
     */
    public function getAbsolutePath(string $relativePath): string
    {
        // Nettoyage anti path-traversal : on rejette tout chemin contenant ".."
        // Un attaquant pourrait sinon saisir "../../etc/passwd" pour sortir du dossier sécurisé.
        // On rejette AUSSI les octets nuls (\0) : sur certains systèmes, la libc tronque
        // le chemin au premier \0, ce qui peut contourner les contrôles (défense en profondeur).
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException(
                'Chemin de fichier invalide (traversée de répertoire interdite).'
            );
        }

        return $this->secureUploadsDir . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    }

    /**
     * Supprime le fichier de pièce d'identité du disque.
     *
     * Appelé lors de :
     *   - Re-soumission avec un nouveau fichier (on supprime l'ancien)
     *   - Suppression RGPD du compte (via un event listener à créer en Lot suivant)
     *   - Demande explicite de l'admin ou de l'utilisateur
     *
     * Ne lève PAS d'exception si le fichier n'existe pas (idempotent).
     */
    public function delete(string $relativePath): void
    {
        // Protection contre la traversée de répertoire + octet nul (même que getAbsolutePath)
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException(
                'Chemin de fichier invalide (traversée de répertoire interdite).'
            );
        }

        $absolutePath = $this->getAbsolutePath($relativePath);

        // is_file() vérifie que c'est bien un fichier (pas un dossier, pas un lien symbolique cassé)
        if (is_file($absolutePath)) {
            // unlink() supprime le fichier. On ignore silencieusement l'échec (déjà supprimé ?).
            @unlink($absolutePath);
        }
    }

    /**
     * Vérifie que le fichier existe physiquement sur le disque.
     *
     * Utile pour l'admin pour détecter les fichiers "orphelins" (chemin en BDD mais
     * fichier absent, ex : suite à une erreur de déplacement ou suppression manuelle).
     */
    public function exists(string $relativePath): bool
    {
        if (str_contains($relativePath, '..')) {
            return false;
        }
        return is_file($this->getAbsolutePath($relativePath));
    }
}

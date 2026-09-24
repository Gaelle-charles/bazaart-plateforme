<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ProjectFileKind — famille d'un fichier joint, déduite de son type MIME (ADR-0037).
 *
 * Sert uniquement à l'AFFICHAGE : choisir l'icône et le libellé (« Dossier »,
 * « Google Docs », « PDF »…) dans le sélecteur Drive et les listes de pièces jointes.
 * Les icônes sont des SVG maison (pas d'appel à des images Google) : cohérence
 * visuelle avec le design Street et aucun domaine externe à autoriser en CSP.
 */
enum ProjectFileKind: string
{
    case Folder = 'folder';
    case Doc    = 'doc';
    case Sheet  = 'sheet';
    case Slides = 'slides';
    case Pdf    = 'pdf';
    case Image  = 'image';
    case Video  = 'video';
    case Audio  = 'audio';
    case Link   = 'link';
    case File   = 'file';

    /** Type MIME des dossiers dans l'API Google Drive v3. */
    public const string DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';

    /**
     * Déduit la famille depuis un type MIME (Drive ou standard).
     * `null` (type inconnu, simple lien) → File.
     */
    public static function fromMimeType(?string $mimeType): self
    {
        if ($mimeType === null || $mimeType === '') {
            return self::File;
        }

        return match (true) {
            $mimeType === self::DRIVE_FOLDER_MIME                     => self::Folder,
            $mimeType === 'application/vnd.google-apps.document',
            str_contains($mimeType, 'wordprocessingml'),
            $mimeType === 'application/msword',
            $mimeType === 'text/plain'                                => self::Doc,
            $mimeType === 'application/vnd.google-apps.spreadsheet',
            str_contains($mimeType, 'spreadsheetml'),
            $mimeType === 'application/vnd.ms-excel',
            $mimeType === 'text/csv'                                  => self::Sheet,
            $mimeType === 'application/vnd.google-apps.presentation',
            str_contains($mimeType, 'presentationml'),
            $mimeType === 'application/vnd.ms-powerpoint'             => self::Slides,
            $mimeType === 'application/pdf'                           => self::Pdf,
            str_starts_with($mimeType, 'image/'),
            $mimeType === 'application/vnd.google-apps.drawing'       => self::Image,
            str_starts_with($mimeType, 'video/')                      => self::Video,
            str_starts_with($mimeType, 'audio/')                      => self::Audio,
            default                                                   => self::File,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Folder => 'Dossier',
            self::Doc    => 'Document',
            self::Sheet  => 'Tableur',
            self::Slides => 'Présentation',
            self::Pdf    => 'PDF',
            self::Image  => 'Image',
            self::Video  => 'Vidéo',
            self::Audio  => 'Audio',
            self::Link   => 'Lien',
            self::File   => 'Fichier',
        };
    }
}

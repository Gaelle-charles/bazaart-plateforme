<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ProjectAttachmentSource — origine d'une pièce jointe (ADR-0037).
 *
 *   - Drive : fichier ou dossier du Google Drive de l'équipe (on garde son ID Drive)
 *   - Link  : simple lien web (Canva, Notion, site partenaire…)
 *
 * Aucun fichier n'est stocké sur le serveur Bazaart : on ne conserve que des
 * références (ID + lien). Le Drive reste la source de vérité des documents.
 */
enum ProjectAttachmentSource: string
{
    case Drive = 'drive';
    case Link  = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Drive => 'Google Drive',
            self::Link  => 'Lien',
        };
    }
}

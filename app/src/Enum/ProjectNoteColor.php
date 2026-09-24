<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ProjectNoteColor — couleur de fond d'une note du mur d'équipe (ADR-0037).
 *
 * On stocke un NOM de couleur (et pas un code hexadécimal) : la palette réelle
 * est définie en CSS (public/css/projects.css, classes .pm-note--{valeur}).
 * Avantage : changer une teinte ne demande aucune migration de données, et un
 * utilisateur ne peut pas injecter une couleur arbitraire.
 */
enum ProjectNoteColor: string
{
    case Yellow = 'yellow';
    case Orange = 'orange';
    case Green  = 'green';
    case Blue   = 'blue';
    case Pink   = 'pink';
    case Cream  = 'cream';

    public function label(): string
    {
        return match ($this) {
            self::Yellow => 'Jaune',
            self::Orange => 'Orange',
            self::Green  => 'Vert',
            self::Blue   => 'Bleu',
            self::Pink   => 'Rose',
            self::Cream  => 'Crème',
        };
    }
}

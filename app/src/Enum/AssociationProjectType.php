<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * AssociationProjectType : catégorie d'un projet de l'association, affichée sur
 * la page « Projets en cours » envoyée aux partenaires (ADR-0039).
 *
 * Backed enum 'string' : la valeur (ex. 'residency') est stockée telle quelle en BDD
 * (colonne VARCHAR), lisible en SQL brut. Doctrine fait la conversion grâce à
 * `enumType: AssociationProjectType::class` dans le mapping de l'entité.
 *
 * ⚠️ Les valeurs sont aussi écrites en dur dans la migration des 9 fiches de départ :
 * ne jamais renommer une valeur sans migration de données.
 *
 * Ordre des cases = ordre d'affichage dans la liste déroulante du back-office.
 */
enum AssociationProjectType: string
{
    case Residency = 'residency';
    case Cooperation = 'cooperation';
    case Training = 'training';
    case PlatformMedia = 'platform_media';
    case Structuring = 'structuring';

    /** Libellé français affiché dans l'interface (cartes, fiche, back-office). */
    public function label(): string
    {
        return match ($this) {
            self::Residency     => 'Résidence',
            self::Cooperation   => 'Coopération',
            self::Training      => 'Formation',
            self::PlatformMedia => 'Plateforme et média',
            self::Structuring   => 'Structuration',
        };
    }
}

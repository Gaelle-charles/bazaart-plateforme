<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * AssociationProjectState : avancement d'un projet de l'association (ADR-0039).
 *
 * Même principe que AssociationProjectType : enum « backed » en chaîne, stocké
 * en VARCHAR. Les valeurs sont reprises en dur dans la migration des fiches de
 * départ : ne pas les renommer sans migration de données.
 *
 * Ordre des cases = cycle de vie naturel d'un projet = ordre de la liste déroulante.
 */
enum AssociationProjectState: string
{
    /** Projet encore au stade d'idée. */
    case Idea = 'idea';

    /** Projet en cours de montage (partenaires, contenu, calendrier). */
    case InProgress = 'in_progress';

    /** Dossiers de financement déposés ou en cours de dépôt. */
    case Funding = 'funding';

    /** Projet confirmé (financé et calé). */
    case Confirmed = 'confirmed';

    /** Projet réalisé. */
    case Completed = 'completed';

    /** Libellé français affiché dans l'interface. */
    public function label(): string
    {
        return match ($this) {
            self::Idea       => 'Idée',
            self::InProgress => 'En construction',
            self::Funding    => 'Financement en cours',
            self::Confirmed  => 'Confirmé',
            self::Completed  => 'Terminé',
        };
    }
}

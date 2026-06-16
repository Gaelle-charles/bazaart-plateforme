<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * CourseProposalStatus — états d'une proposition de formation soumise par un membre.
 *
 * Cycle de vie : Pending (à la création) → Accepted OU Rejected (décision admin).
 *
 * Enum backed string : la valeur ('pending', etc.) est stockée en BDD via
 * enumType: CourseProposalStatus::class sur la colonne Doctrine.
 */
enum CourseProposalStatus: string
{
    /** En attente de revue par un administrateur (état initial) */
    case Pending = 'pending';

    /** Proposition acceptée par l'équipe Bazaart */
    case Accepted = 'accepted';

    /** Proposition refusée par l'équipe Bazaart */
    case Rejected = 'rejected';

    /** Libellé français lisible (affichage interface) */
    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'En attente',
            self::Accepted => 'Acceptée',
            self::Rejected => 'Refusée',
        };
    }

    /**
     * Clé de couleur logique pour le rendu d'un badge (mappée en CSS côté template).
     * On évite de mettre des couleurs en dur ici : on renvoie une intention.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending  => 'warn',   // orange : en attente
            self::Accepted => 'ok',     // vert : acceptée
            self::Rejected => 'danger', // rouge : refusée
        };
    }
}

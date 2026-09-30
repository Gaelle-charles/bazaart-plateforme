<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * OpportunityReviewStatus — où en est l'équipe avec une opportunité (ADR-0038).
 *
 * Une opportunité sans décision est « à étudier » : elle n'a alors AUCUNE ligne
 * en base (on n'enregistre que les décisions, pas les milliers d'opportunités vues).
 */
enum OpportunityReviewStatus: string
{
    case Shortlisted = 'retenue';
    case Applying    = 'candidature';
    case Dismissed   = 'ecartee';

    public function label(): string
    {
        return match ($this) {
            self::Shortlisted => 'Retenue',
            self::Applying    => 'Candidature en cours',
            self::Dismissed   => 'Écartée',
        };
    }
}

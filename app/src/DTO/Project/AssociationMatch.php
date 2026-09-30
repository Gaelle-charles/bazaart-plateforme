<?php

declare(strict_types=1);

namespace App\DTO\Project;

use App\Enum\BazaartAssociation;

/**
 * AssociationMatch — résultat de l'évaluation d'une opportunité pour UNE association (ADR-0038).
 *
 *   score    0 à 100 (plus c'est haut, plus l'opportunité correspond)
 *   matches  true si l'opportunité est affichée pour cette association
 *   reasons  phrases courtes affichées sous l'opportunité (« Ouvert aux associations »…)
 *   excludedBecause  raison de l'exclusion (null si non exclue), utile au débogage
 */
final readonly class AssociationMatch
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public BazaartAssociation $association,
        public int $score,
        public bool $matches,
        public array $reasons,
        public ?string $excludedBecause = null,
    ) {}
}

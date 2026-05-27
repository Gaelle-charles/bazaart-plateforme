<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * SuggestedSourceStatus — Statut d'une source suggérée automatiquement.
 *
 * Cycle de vie d'une suggestion :
 *   1. app:discover-sources crée la suggestion → statut AValider
 *   2. L'admin consulte /admin/suggested-sources
 *   3. L'admin clique "Valider" → statut Validee + création d'une ScrapingSource
 *      OU "Rejeter" → statut Rejetee (pas de ScrapingSource créée)
 *
 * Les suggestions validées et rejetées sont conservées en BDD pour avoir
 * un historique et éviter de re-suggérer les mêmes sources.
 */
enum SuggestedSourceStatus: string
{
    // Suggestion en attente de décision admin (état initial)
    case AValider = 'a_valider';

    // Validée par l'admin et transformée en ScrapingSource active
    case Validee = 'validee';

    // Rejetée explicitement par l'admin (organisme non pertinent ou doublon)
    case Rejetee = 'rejetee';

    /**
     * Libellé lisible en français pour l'interface admin.
     */
    public function label(): string
    {
        return match($this) {
            self::AValider => 'À valider',
            self::Validee  => 'Validée',
            self::Rejetee  => 'Rejetée',
        };
    }
}

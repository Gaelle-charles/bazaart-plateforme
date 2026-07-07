<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * SuggestedSourceStatus — Statut d'une source suggérée automatiquement.
 *
 * Cycle de vie d'une suggestion :
 *   1. app:discover-sources crée la suggestion → statut AValider
 *   2. Deux chemins possibles à partir de là (ADR-0034) :
 *      a) AUTOMATIQUE : si l'URL est un flux RSS/Atom confirmé fonctionnel par
 *         FeedDetectorService, la suggestion est immédiatement promue en
 *         ScrapingSource active → statut AutoValidee (aucune action admin).
 *      b) MANUEL (comportement historique, inchangé) : l'admin consulte
 *         /admin/suggested-sources et clique "Valider" (→ Validee + création
 *         d'une ScrapingSource) ou "Rejeter" (→ Rejetee, rien de créé).
 *
 * Les suggestions validées, auto-validées et rejetées sont conservées en BDD
 * pour avoir un historique et éviter de re-suggérer les mêmes sources.
 */
enum SuggestedSourceStatus: string
{
    // Suggestion en attente de décision admin (état initial)
    case AValider = 'a_valider';

    // Validée MANUELLEMENT par l'admin et transformée en ScrapingSource active
    case Validee = 'validee';

    // Rejetée explicitement par l'admin (organisme non pertinent ou doublon)
    case Rejetee = 'rejetee';

    // Auto-validée AUTOMATIQUEMENT (ADR-0034) : flux RSS/Atom confirmé fonctionnel
    // par FeedDetectorService, aucune intervention admin nécessaire.
    case AutoValidee = 'auto_validee';

    /**
     * Libellé lisible en français pour l'interface admin.
     */
    public function label(): string
    {
        return match($this) {
            self::AValider    => 'À valider',
            self::Validee     => 'Validée',
            self::Rejetee     => 'Rejetée',
            self::AutoValidee => 'Auto-validée (RSS)',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ScrapingRunStatus — Statut du dernier run d'une source de scraping.
 *
 * Stocké dans ScrapingSource.statutDernierRun pour indiquer l'état
 * de la dernière exécution du scraper sur cette source.
 *
 * Utilisé dans l'interface admin (/admin/scraping-sources) pour :
 *   - Afficher un badge coloré par source
 *   - Identifier rapidement les sources en erreur
 *   - Afficher le message d'erreur en tooltip si statut = Error
 */
enum ScrapingRunStatus: string
{
    case NeverRun = 'never_run';
    case Success  = 'success';
    case Error    = 'error';

    /**
     * Libellé lisible en français pour l'interface admin.
     */
    public function label(): string
    {
        return match($this) {
            self::NeverRun => 'Jamais lancé',
            self::Success  => 'Succès',
            self::Error    => 'Erreur',
        };
    }
}

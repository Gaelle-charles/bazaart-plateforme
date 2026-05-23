<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'une ressource collectée automatiquement par l'agent IA de scraping.
 *
 * Workflow :
 *   - Pending  : scrapée par l'agent IA, en attente de validation admin.
 *                Tant qu'elle est dans ce statut, elle n'apparaît pas publiquement.
 *   - Verified : un admin l'a validée (et généralement promue en Resource publiée).
 *
 * Cet enum est distinct de ResourceStatus parce que les ScrapedResource
 * suivent un cycle de vie différent (source machine, pas humain) et n'ont
 * que deux états utiles.
 */
enum ScrapedResourceStatus: string
{
    case Pending  = 'pending';
    case Verified = 'verified';
}

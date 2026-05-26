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
 *   - Rejected : un admin l'a rejetée (hors sujet ou doublon) — ne sera pas publiée.
 *
 * Cet enum est distinct de ResourceStatus parce que les ScrapedResource
 * suivent un cycle de vie différent (source machine, pas humain).
 *
 * Note technique : la colonne BDD est un VARCHAR(20). PostgreSQL accepte nativement
 * la nouvelle valeur 'rejected' sans migration (pas de type ENUM natif PostgreSQL ici).
 */
enum ScrapedResourceStatus: string
{
    case Pending  = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /**
     * Retourne le libellé français pour l'affichage dans les templates Twig.
     * Utilise le match exhaustif PHP 8.1 : si un case est oublié, on a une erreur à la compile.
     */
    public function label(): string
    {
        return match($this) {
            self::Pending  => 'À vérifier',
            self::Verified => 'Vérifié',
            self::Rejected => 'Rejeté',
        };
    }
}

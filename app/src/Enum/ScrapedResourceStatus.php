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
 *   - Archived : opportunité expirée (deadline passée, détectée automatiquement)
 *                ou archivée manuellement par un admin.
 *                Distinct de Rejected : l'opportunité était valide, mais son délai est révolu.
 *
 * Cet enum est distinct de ResourceStatus parce que les ScrapedResource
 * suivent un cycle de vie différent (source machine, pas humain).
 *
 * Note technique : la colonne BDD est un VARCHAR(20). PostgreSQL accepte nativement
 * les nouvelles valeurs ('rejected', 'archived') sans migration car ce n'est pas
 * un type ENUM natif PostgreSQL — c'est un simple VARCHAR avec contrainte applicative.
 */
enum ScrapedResourceStatus: string
{
    case Pending  = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /**
     * Opportunité expirée (deadline passée) ou archivée manuellement par un admin.
     *
     * Ce statut est attribué automatiquement par ScrapedResourceRepository::archiveExpired()
     * à chaque run du scraper. Il permet de conserver l'historique des opportunités passées
     * sans encombrer l'onglet "À vérifier" avec des offres périmées.
     *
     * Différence avec Rejected :
     *   - Rejected = l'admin a jugé l'opportunité hors sujet ou invalide
     *   - Archived = l'opportunité était valide mais sa deadline est passée
     */
    case Archived = 'archived';

    /**
     * Retourne le libellé français pour l'affichage dans les templates Twig.
     *
     * Utilise le match exhaustif PHP 8.1 : si un case manque dans le match,
     * PHP lève une UnhandledMatchError à l'exécution — filet de sécurité utile.
     */
    public function label(): string
    {
        return match($this) {
            self::Pending  => 'À vérifier',
            self::Verified => 'Vérifié',
            self::Rejected => 'Rejeté',
            self::Archived => 'Archivé',
        };
    }
}

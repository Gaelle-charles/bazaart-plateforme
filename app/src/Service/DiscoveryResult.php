<?php

declare(strict_types=1);

namespace App\Service;

/**
 * DiscoveryResult — Résultat de la découverte d'une URL-liste pour un site donné.
 *
 * Ce DTO léger porte le résultat de ListingUrlDiscoverer::discoverForSite() :
 *   - siteUrl    : URL du site analysé (entrée)
 *   - listingUrl : URL de la page-liste trouvée, ou null si aucune détectée
 *   - method     : méthode utilisée ('heuristic', 'llm', 'none')
 *   - sourceId   : null = créée (ID après flush), -1 = doublon, null = dry-run
 *   - nom        : nom lisible de la source (depuis CSV ou domaine)
 *   - reason     : explication humaine du résultat (pour le rapport de la commande)
 *
 * IMMUABILITÉ :
 *   Propriétés readonly — les résultats d'une découverte ne doivent pas être modifiés
 *   après construction. Même philosophie que ImportResult.
 *
 * Utilisé par : DiscoverListingUrlsCommand pour le rapport final.
 */
final readonly class DiscoveryResult
{
    /**
     * @param string      $siteUrl    URL du site analysé
     * @param string|null $listingUrl URL de la page-liste trouvée (null = aucune)
     * @param string      $method     Méthode utilisée : 'heuristic', 'llm', 'none'
     * @param int|null    $sourceId   null = créée ou dry-run, -1 = doublon ignoré
     * @param string      $nom        Nom lisible de la source
     * @param string      $reason     Explication lisible pour le rapport
     */
    public function __construct(
        public string $siteUrl,
        public ?string $listingUrl,
        public string $method,
        public ?int $sourceId,
        public string $nom,
        public string $reason,
    ) {
    }

    /**
     * Indique si une URL-liste a été trouvée pour ce site.
     *
     * Un résultat est "trouvé" même si l'URL était déjà en BDD (doublon).
     * Ce qui compte c'est qu'on a identifié la page-liste, même si on n'a pas
     * créé de nouvelle ScrapingSource.
     */
    public function found(): bool
    {
        return $this->listingUrl !== null;
    }

    /**
     * Indique si une nouvelle ScrapingSource a été créée en BDD.
     *
     * Différent de found() :
     *   - found() = on a trouvé l'URL-liste (heuristique ou LLM)
     *   - created() = on a créé une nouvelle source en BDD (pas de doublon, pas dry-run)
     *
     * sourceId === null ET listingUrl !== null = créée (ID pas encore connu)
     * sourceId === -1 = doublon ignoré
     */
    public function created(): bool
    {
        // Créée = URL trouvée + pas un doublon (-1) + pas null (dry-run ou erreur)
        // En pratique : listingUrl non null + sourceId !== -1 + non dry-run
        // La commande connaît le contexte dry-run, ce getter est un indicateur brut.
        return $this->listingUrl !== null && $this->sourceId !== -1;
    }

    /**
     * Indique si l'URL trouvée était déjà présente en BDD (doublon).
     */
    public function isDuplicate(): bool
    {
        return $this->sourceId === -1;
    }
}

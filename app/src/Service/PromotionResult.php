<?php

declare(strict_types=1);

namespace App\Service;

/**
 * PromotionResult — Résultat de la promotion d'une opportunité en source de scraping.
 *
 * Objet valeur (Value Object) retourné par OpportunityToSourcePromoter::promote().
 * Le controller l'utilise pour construire le flash message approprié.
 *
 * Readonly : PHP 8.1 — les propriétés ne peuvent pas être modifiées après construction.
 * Constructor promotion : toutes les propriétés sont déclarées dans le constructeur.
 */
final readonly class PromotionResult
{
    /**
     * @param string|null $sourceUrl URL de la ScrapingSource créée ou déjà existante.
     *                               Null uniquement si la promotion a totalement échoué
     *                               (ex: URL manquante sur la ScrapedResource).
     * @param bool        $isNew     True si la source vient d'être créée, false si elle
     *                               existait déjà en BDD (doublon évité).
     * @param bool        $success   True si la promotion s'est déroulée sans erreur fatale.
     *                               Une promotion "partiellement réussie" (fallback créé)
     *                               retourne quand même success=true.
     *                               False uniquement si l'URL source était manquante.
     * @param string      $message   Message lisible pour le flash admin.
     *                               Décrit ce qui s'est passé : source créée, doublon,
     *                               fallback, ou erreur.
     */
    public function __construct(
        public readonly ?string $sourceUrl,
        public readonly bool    $isNew,
        public readonly bool    $success,
        public readonly string  $message,
    ) {
    }
}

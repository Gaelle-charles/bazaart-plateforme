<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ScrapingSourceType — Type d'une source de scraping.
 *
 * Chaque type détermine la stratégie de collecte utilisée par le scraper :
 *
 *   RSS       : Parse un flux XML standardisé (le plus fiable, ne casse presque jamais).
 *               Pas besoin de clé API, pas de LLM — simple parsing XML.
 *
 *   HtmlLlm   : Fetch le HTML brut + envoi au LLM (Mistral ou Anthropic) pour extraction.
 *               Utilisé quand le site n'a pas de flux RSS. Nécessite une clé API LLM.
 *               Ce type peut fonctionner SANS classe PHP dédiée (via GenericScraper).
 *
 *   HtmlCss   : Fetch le HTML + parsing par sélecteurs CSS (DomCrawler).
 *               Très rapide et sans coût API, mais fragile si le site change sa structure.
 *               TOUJOURS associé à un scraperSlug (classe PHP dédiée OBLIGATOIRE).
 *               Ce type ne peut pas fonctionner de manière générique.
 *
 * Convention : le formulaire admin ne propose QUE RSS et HtmlLlm pour les nouvelles sources.
 * HtmlCss est réservé aux sources initialisées via app:seed-scraping-sources.
 */
enum ScrapingSourceType: string
{
    case RSS     = 'rss';
    case HtmlLlm = 'html_llm';
    case HtmlCss = 'html_css';

    /**
     * Libellé lisible en français pour l'interface admin.
     */
    public function label(): string
    {
        return match($this) {
            self::RSS     => 'Flux RSS',
            self::HtmlLlm => 'HTML → LLM',
            self::HtmlCss => 'HTML → CSS (classe dédiée)',
        };
    }

    /**
     * Indique si ce type nécessite un scraperSlug (classe PHP personnalisée OBLIGATOIRE).
     *
     * HTML_CSS = true  : DomCrawler a besoin de sélecteurs CSS spécifiques à chaque site.
     * RSS      = false : parsing XML générique, pas de classe dédiée requise.
     * HTML_LLM = false : le LLM fait l'extraction, pas de sélecteurs à coder.
     */
    public function requiresSlug(): bool
    {
        return $this === self::HtmlCss;
    }
}

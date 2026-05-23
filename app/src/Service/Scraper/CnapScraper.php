<?php

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * CnapScraper — Scrape les actualités du CNAP.
 *
 * CNAP = Centre National des Arts Plastiques
 * Page : https://www.cnap.fr/actualites
 *
 * Structure HTML réelle confirmée par debug :
 *   h3 a = 10 liens ✓ — sélecteur qui fonctionne
 *   article = 20   ✓
 *
 * On récupère TOUTES les actualités (pas de filtre par mots-clés)
 * car le CNAP ne publie que des contenus liés aux arts plastiques.
 * La validation humaine dans Google Sheets fera le tri.
 */
class CnapScraper extends AbstractScraper
{
    private const BASE_URL  = 'https://www.cnap.fr';
    private const SCRAPE_URL = 'https://www.cnap.fr/actualites';

    // URLs à ignorer — liens de navigation qui apparaissent dans les h3
    private const EXCLUDED_PATHS = ['/contact', '/mentions-legales', '/accessibilite', '/plan-du-site'];

    public function getName(): string
    {
        return 'CNAP - Centre National des Arts Plastiques';
    }

    public function getTestUrl(): string
    {
        return self::SCRAPE_URL;
    }

    /**
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        $opportunities = [];
        $seenUrls = [];

        $crawler = $this->fetch(self::SCRAPE_URL);
        if ($crawler === null) {
            return [];
        }

        // Sélecteur confirmé : h3 a = 10 éléments trouvés lors du debug
        $crawler->filter('h3 a')->each(function ($linkNode) use (&$opportunities, &$seenUrls) {
            $title = $this->cleanText($linkNode->text());
            $href  = $linkNode->attr('href') ?? '';

            // Ignore les liens vides ou ancres
            if (empty($href) || $href === '#' || str_starts_with($href, 'mailto:')) {
                return;
            }

            // Ignore les liens de navigation connus
            foreach (self::EXCLUDED_PATHS as $excluded) {
                if (str_contains($href, $excluded)) {
                    return;
                }
            }

            // Ignore les titres trop courts (probablement des liens de nav)
            if (mb_strlen($title) < 10) {
                return;
            }

            $absoluteUrl = $this->absoluteUrl($href, self::BASE_URL);

            // Évite les doublons
            if (isset($seenUrls[$absoluteUrl])) {
                return;
            }
            $seenUrls[$absoluteUrl] = true;

            $opportunities[] = new ScrapedOpportunity(
                title: $title,
                type: 'Actualité CNAP',
                url: $absoluteUrl,
                source: 'cnap.fr',
                description: '',
                deadline: '',
                disciplines: 'Arts plastiques',
            );
        });

        return $opportunities;
    }
}

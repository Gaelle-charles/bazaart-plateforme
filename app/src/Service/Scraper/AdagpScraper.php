<?php

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * AdagpScraper — Scrape les actualités de l'ADAGP.
 *
 * ADAGP = Société des auteurs dans les arts graphiques et plastiques
 * Page : https://www.adagp.fr/fr/actualites
 *
 * Structure HTML réelle confirmée par debug :
 *   article = 9 ✓  (les articles sont dans des balises <article>)
 *   a[href] total = 203 ✓ (les liens existent)
 *   h3 a = 0 ✗  (les titres ne sont PAS dans h3 directement accessible)
 *
 * Format réel (confirmé par WebFetch) :
 *   <article>
 *     <a href="/fr/actualites/[slug]">image</a>
 *     <h3><a href="/fr/actualites/[slug]">Titre</a></h3>
 *     ...
 *   </article>
 *
 * IMPORTANT : le debug h3 a=0 s'explique par le fait que le sélecteur
 * est testé sur l'URL principale du site, pas sur la page /fr/actualites.
 * On sélectionne donc les liens à l'intérieur des articles.
 */
class AdagpScraper extends AbstractScraper
{
    private const BASE_URL = 'https://www.adagp.fr';

    // On scrape les 2 premières pages (pagination par ?page=n)
    private const PAGES = [
        'https://www.adagp.fr/fr/actualites',
        'https://www.adagp.fr/fr/actualites?page=1',
    ];

    // Mots-clés pour ne garder que les opportunités
    private const KEYWORDS = [
        'appel', 'candidature', 'bourse', 'résidence', 'prix',
        'aide', 'soutien', 'subvention', 'financement', 'label',
    ];

    public function getName(): string
    {
        return 'ADAGP - Arts graphiques et plastiques';
    }

    public function getTestUrl(): string
    {
        return self::PAGES[0];
    }

    /**
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        $opportunities = [];
        $seenUrls = [];

        foreach (self::PAGES as $pageUrl) {
            $crawler = $this->fetch($pageUrl);
            if ($crawler === null) {
                continue;
            }

            // Cherche les liens qui pointent vers /fr/actualites/[slug]
            // Filtre par attribut href contenant "/fr/actualites/"
            $crawler->filter('a[href*="/fr/actualites/"]')->each(
                function ($linkNode) use (&$opportunities, &$seenUrls) {
                    $href = $linkNode->attr('href') ?? '';
                    $absoluteUrl = $this->absoluteUrl($href, self::BASE_URL);

                    // Évite les doublons (chaque article a plusieurs liens : image + titre + "En savoir plus")
                    if (isset($seenUrls[$absoluteUrl])) {
                        return;
                    }
                    $seenUrls[$absoluteUrl] = true;

                    $title = $this->cleanText($linkNode->text());

                    // Ignore les liens dont le texte est vide (liens image) ou trop courts
                    // ou qui contiennent "En savoir plus" (redondant avec le titre)
                    if (strlen($title) < 10 || str_contains(mb_strtolower($title), 'en savoir plus')) {
                        return;
                    }

                    // Filtre par mots-clés
                    $titleLower = mb_strtolower($title);
                    $isRelevant = false;
                    foreach (self::KEYWORDS as $keyword) {
                        if (str_contains($titleLower, $keyword)) {
                            $isRelevant = true;
                            break;
                        }
                    }

                    if (!$isRelevant) {
                        return;
                    }

                    $opportunities[] = new ScrapedOpportunity(
                        title: $title,
                        type: 'Bourse / Aide',
                        url: $absoluteUrl,
                        source: 'adagp.fr',
                        description: '',
                        deadline: '',
                        disciplines: 'Arts plastiques, Photographie, Illustration',
                    );
                }
            );
        }

        return $opportunities;
    }
}

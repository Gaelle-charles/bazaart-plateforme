<?php

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * SaifScraper — Scrape les opportunités de la SAIF via leurs pages statiques.
 *
 * SAIF = Société des Auteurs des arts visuels et de l'Image Fixe
 * Site : https://www.saif.fr
 *
 * La page /fr/actualites est JS-rendue (296Ko de bundle JS, 0 liens HTML).
 * On scrape directement les pages des bourses et prix qui sont statiques.
 *
 * Bourses connues de la SAIF (2026) :
 *   - Bourse du Talent SAIF (4 000€) → photographes émergents
 *   - Prix Camille Lepage (8 000€) → photojournalisme
 *   - Prix SAIF Les Femmes s'exposent (3 000€) → femmes photographes
 *   - Bourse Benoît Schaeffer (10 000€) → livre photo
 */
class SaifScraper extends AbstractScraper
{
    private const BASE_URL = 'https://www.saif.fr';

    // Pages statiques des bourses et prix de la SAIF
    private const PAGES = [
        'https://www.saif.fr/fr/bourses-et-prix',
        'https://www.saif.fr/fr/aides-a-la-creation',
    ];

    public function getName(): string
    {
        return 'SAIF - Auteurs arts visuels et image fixe';
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

            // Cherche tous les liens internes qui peuvent être des bourses/prix
            $crawler->filter('a[href]')->each(function ($linkNode) use (&$opportunities, &$seenUrls, $pageUrl) {
                $href  = $linkNode->attr('href') ?? '';
                $title = $this->cleanText($linkNode->text());

                // Ignore les liens vides, de navigation, ou trop courts
                if (empty($title) || strlen($title) < 10) {
                    return;
                }
                if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                    return;
                }

                $absoluteUrl = $this->absoluteUrl($href, self::BASE_URL);

                // Évite les doublons
                if (isset($seenUrls[$absoluteUrl])) {
                    return;
                }
                $seenUrls[$absoluteUrl] = true;

                // Filtre par mots-clés
                $titleLower = mb_strtolower($title);
                $keywords = ['bourse', 'prix', 'appel', 'candidature', 'aide', 'résidence'];
                $isRelevant = false;
                foreach ($keywords as $kw) {
                    if (str_contains($titleLower, $kw)) {
                        $isRelevant = true;
                        break;
                    }
                }

                if (!$isRelevant) {
                    return;
                }

                $opportunities[] = new ScrapedOpportunity(
                    title: $title,
                    type: str_contains($titleLower, 'prix') ? 'Prix' : 'Bourse / Aide',
                    url: $absoluteUrl,
                    source: 'saif.fr',
                    description: '',
                    deadline: '',
                    disciplines: 'Photographie, Arts visuels, Illustration',
                );
            });
        }

        return $opportunities;
    }
}

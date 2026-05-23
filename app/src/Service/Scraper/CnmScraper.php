<?php

namespace App\Service\Scraper;

/**
 * CnmScraper — Scrape le flux RSS du CNM.
 *
 * CNM = Centre National de la Musique
 * Flux RSS : https://cnm.fr/feed/
 *
 * Le RSS du CNM contient toutes les actualités, on filtre pour ne garder
 * que les opportunités (appels, bourses, résidences).
 *
 * Items RSS confirmés (mars 2026) :
 *   - "Corée du Sud | Appel à candidatures : showcases au Zandari Festa"
 *   - "Diaphonique – Appel à projets 2026 (13 mars – 17 mai)"
 */
class CnmScraper extends AbstractRssScraper
{
    protected function getFeedUrl(): string
    {
        return 'https://cnm.fr/feed/';
    }

    public function getName(): string
    {
        return 'CNM - Centre National de la Musique';
    }

    protected function getSourceName(): string
    {
        return 'cnm.fr';
    }

    protected function getDisciplines(): string
    {
        return 'Musique';
    }
}

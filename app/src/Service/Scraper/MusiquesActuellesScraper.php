<?php

namespace App\Service\Scraper;

/**
 * MusiquesActuellesScraper — Scrape le flux RSS de Musiques Actuelles.
 *
 * Site : https://www.musiquesactuelles.fr
 * RSS  : https://www.musiquesactuelles.fr/feed/
 *
 * "Musiques Actuelles en France" est un portail qui agrège les actualités
 * de la scène musicale française (rock, électro, hip-hop, folk, jazz...).
 * Le flux contient des sorties de disques, événements ET des appels à
 * candidatures (ex: "Prix des Musiques d'ICI").
 *
 * On filtre avec des mots-clés stricts pour ne garder que les opportunités.
 */
class MusiquesActuellesScraper extends AbstractRssScraper
{
    protected function getFeedUrl(): string
    {
        return 'https://www.musiquesactuelles.fr/feed/';
    }

    public function getName(): string
    {
        return 'Musiques Actuelles en France';
    }

    protected function getSourceName(): string
    {
        return 'musiquesactuelles.fr';
    }

    protected function getDisciplines(): string
    {
        return 'Musique';
    }

    /**
     * Filtre strict pour ne garder que les opportunités (pas les sorties d'albums).
     *
     * @return string[]
     */
    protected function getKeywords(): array
    {
        return [
            'appel', 'candidature', 'bourse', 'résidence', 'prix',
            'aide', 'subvention', 'financement', 'soutien',
        ];
    }
}

<?php

namespace App\Service\Scraper;

/**
 * ProHelvetiaScraper — Scrape le flux RSS de Pro Helvetia.
 *
 * Pro Helvetia = Fondation suisse pour la culture
 * RSS : https://prohelvetia.ch/fr/feed/
 *
 * Pro Helvetia soutient les artistes suisses et les projets francophones
 * internationaux. Leur flux RSS contient des appels à candidatures,
 * résidences, et soutiens pour : arts visuels, design, musique, arts
 * numériques, jeux vidéo, littérature...
 *
 * Items confirmés dans le flux :
 *   - "She Got Game 2026" — mentorat développeuses de jeux vidéo
 *   - "Circuit Mixers 2026" — réseau DJ-productrices
 *   - "Synergies 2026" — art et technologies numériques
 */
class ProHelvetiaScraper extends AbstractRssScraper
{
    protected function getFeedUrl(): string
    {
        return 'https://prohelvetia.ch/fr/feed/';
    }

    public function getName(): string
    {
        return 'Pro Helvetia - Fondation suisse pour la culture';
    }

    protected function getSourceName(): string
    {
        return 'prohelvetia.ch';
    }

    protected function getDisciplines(): string
    {
        return 'Arts visuels, Design, Musique, Arts numériques';
    }

    /**
     * Mots-clés élargis car Pro Helvetia publie des sélections ET des appels.
     *
     * @return string[]
     */
    protected function getKeywords(): array
    {
        return [
            'appel', 'candidature', 'sélection', 'bourse', 'résidence',
            'aide', 'soutien', 'prix', 'programme', 'call',
        ];
    }
}

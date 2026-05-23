<?php

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * CultureGouvScraper — Scrape les opportunités du Ministère de la Culture.
 *
 * Site : https://www.culture.gouv.fr
 *
 * PROBLÈME CONNU : la page /Presse/Communiques-de-presse retourne 16Ko de HTML
 * mais 0 liens car le contenu est chargé via Algolia (moteur de recherche JS).
 *
 * SOLUTION : on scrape directement la page d'accueil des actualités culturelles
 * qui utilise des listes HTML statiques, et on filtre par mots-clés.
 */
class CultureGouvScraper extends AbstractScraper
{
    private const BASE_URL = 'https://www.culture.gouv.fr';

    // Pages du ministère avec du contenu HTML statique (non JS-rendu)
    private const PAGES = [
        'https://www.culture.gouv.fr/Actualites',
        'https://www.culture.gouv.fr/Thematiques/Theatre-spectacles/Appels-a-projets',
        'https://www.culture.gouv.fr/Thematiques/Arts-visuels/Appels-a-projets',
    ];

    private const KEYWORDS = [
        'appel', 'candidature', 'bourse', 'résidence', 'prix',
        'aide', 'soutien', 'subvention', 'label', 'dispositif',
    ];

    public function getName(): string
    {
        return 'Ministère de la Culture';
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

            // Cherche les articles et liens internes
            $crawler->filter('article h3 a, article h2 a, .liste-article a, h3 a, h2 a')->each(
                function ($linkNode) use (&$opportunities, &$seenUrls) {
                    $title = $this->cleanText($linkNode->text());
                    $href  = $linkNode->attr('href') ?? '';

                    if (empty($title) || strlen($title) < 10 || empty($href)) {
                        return;
                    }

                    $absoluteUrl = $this->absoluteUrl($href, self::BASE_URL);

                    // Ignore les liens externes (hors culture.gouv.fr)
                    if (!str_contains($absoluteUrl, 'culture.gouv.fr')) {
                        return;
                    }

                    if (isset($seenUrls[$absoluteUrl])) {
                        return;
                    }
                    $seenUrls[$absoluteUrl] = true;

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

                    $disciplines = $this->detectDisciplines($title);

                    $opportunities[] = new ScrapedOpportunity(
                        title: $title,
                        type: 'Appel à projets',
                        url: $absoluteUrl,
                        source: 'culture.gouv.fr',
                        description: '',
                        deadline: '',
                        disciplines: $disciplines,
                    );
                }
            );
        }

        return $opportunities;
    }

    /**
     * Détecte les disciplines artistiques dans le titre.
     */
    private function detectDisciplines(string $text): string
    {
        $text = mb_strtolower($text);
        $found = [];

        $map = [
            'musique'      => 'Musique',
            'théâtre'      => 'Théâtre',
            'danse'        => 'Danse',
            'cinéma'       => 'Cinéma',
            'arts visuels' => 'Arts visuels',
            'plastiques'   => 'Arts plastiques',
            'patrimoine'   => 'Patrimoine',
            'livre'        => 'Littérature',
            'photo'        => 'Photographie',
            'cirque'       => 'Cirque',
            'spectacle'    => 'Spectacle vivant',
        ];

        foreach ($map as $keyword => $discipline) {
            if (str_contains($text, $keyword)) {
                $found[] = $discipline;
            }
        }

        return empty($found) ? 'Toutes disciplines' : implode(', ', $found);
    }
}

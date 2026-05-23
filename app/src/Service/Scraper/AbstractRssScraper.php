<?php

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * AbstractRssScraper — Classe de base pour les scrapers basés sur des flux RSS.
 *
 * Un flux RSS est un fichier XML standardisé que beaucoup de sites publient
 * pour diffuser leurs actualités. C'est beaucoup plus fiable que de scraper
 * le HTML car le format ne change presque jamais.
 *
 * Structure d'un item RSS :
 *   <item>
 *     <title>Titre</title>
 *     <link>https://...</link>
 *     <description>Résumé</description>
 *     <pubDate>Mon, 23 Mar 2026 13:57:09 +0000</pubDate>
 *   </item>
 */
abstract class AbstractRssScraper extends AbstractScraper
{
    /**
     * URL du flux RSS à parser.
     */
    abstract protected function getFeedUrl(): string;

    /**
     * Retourne l'URL principale à tester (= le flux RSS).
     */
    public function getTestUrl(): string
    {
        return $this->getFeedUrl();
    }

    /**
     * Mots-clés pour filtrer les items pertinents (opportunités pour artistes).
     * Peut être surchargé dans les classes enfants.
     *
     * @return string[]
     */
    protected function getKeywords(): array
    {
        return [
            'appel', 'candidature', 'bourse', 'résidence', 'aide',
            'soutien', 'prix', 'subvention', 'financement', 'grant',
        ];
    }

    /**
     * Parse le flux RSS et retourne les opportunités filtrées par mots-clés.
     *
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        $opportunities = [];

        try {
            // Télécharge le XML du flux RSS
            $response = $this->httpClient->request('GET', $this->getFeedUrl(), [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; BazaArtBot/1.0)',
                    'Accept'     => 'application/rss+xml, application/xml, text/xml',
                ],
                'timeout' => 20,
            ]);

            $this->lastStatusCode = $response->getStatusCode();

            if ($this->lastStatusCode !== 200) {
                return [];
            }

            $xml = $response->getContent();
            $this->lastFetchedLength = strlen($xml);

            // SimpleXML parse le XML en objet PHP navigable facilement
            // @ supprime les warnings PHP si le XML est mal formé
            $feed = @simplexml_load_string($xml);
            if ($feed === false) {
                return [];
            }

            // Parcourt les items du flux RSS (chaque item = une actualité)
            foreach ($feed->channel->item as $item) {
                $title       = $this->cleanText((string) $item->title);
                $link        = $this->cleanText((string) $item->link);
                $description = $this->cleanText(strip_tags((string) $item->description));
                $pubDate     = $this->cleanText((string) $item->pubDate);

                // Ignore les items sans titre ou sans lien
                if (empty($title) || empty($link)) {
                    continue;
                }

                // Filtre par mots-clés sur le titre + description
                $textToSearch = mb_strtolower($title . ' ' . $description);
                $isRelevant = false;
                foreach ($this->getKeywords() as $keyword) {
                    if (str_contains($textToSearch, $keyword)) {
                        $isRelevant = true;
                        break;
                    }
                }

                if (!$isRelevant) {
                    continue;
                }

                // Formate la date de publication (ex: "Mon, 23 Mar 2026 13:57:09 +0000")
                $formattedDate = '';
                if (!empty($pubDate)) {
                    try {
                        $date = new \DateTime($pubDate);
                        $formattedDate = $date->format('d/m/Y');
                    } catch (\Exception) {
                        $formattedDate = $pubDate;
                    }
                }

                $opportunities[] = new ScrapedOpportunity(
                    title: $title,
                    type: $this->detectType($title . ' ' . $description),
                    url: $link,
                    source: $this->getSourceName(),
                    description: mb_substr($description, 0, 200),
                    deadline: $formattedDate,
                    disciplines: $this->getDisciplines(),
                );
            }

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
        }

        return $opportunities;
    }

    /**
     * Nom court du site source (ex: "cnm.fr").
     */
    abstract protected function getSourceName(): string;

    /**
     * Disciplines couvertes par ce site (valeur par défaut).
     */
    protected function getDisciplines(): string
    {
        return 'Toutes disciplines';
    }

    /**
     * Détecte le type d'opportunité à partir du texte.
     */
    protected function detectType(string $text): string
    {
        $text = mb_strtolower($text);

        if (str_contains($text, 'résidence')) return 'Résidence';
        if (str_contains($text, 'bourse'))    return 'Bourse / Aide';
        if (str_contains($text, 'prix'))      return 'Prix';
        if (str_contains($text, 'financement') || str_contains($text, 'soutien')) return 'Financement';

        return 'Appel à projets';
    }
}

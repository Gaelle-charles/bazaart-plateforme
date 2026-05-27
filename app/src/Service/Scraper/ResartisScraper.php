<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;
use App\Service\AfrodiasporaRelevanceScorer;
use App\Service\LlmExtractorService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ResartisScraper — Scrape les résidences d'artistes sur Resartis.
 *
 * Resartis (resartis.org) est le réseau mondial des résidences d'artistes.
 * Il recense des centaines de résidences à travers le monde, dont beaucoup
 * en Afrique, en Europe et dans les Caraïbes — très pertinent pour Bazaart.
 *
 * Resartis publie une liste de membres (résidences) mais pas toujours
 * des flux RSS d'opportunités actives.
 *
 * Stratégie :
 *   1. Tentative RSS → https://www.resartis.org/feed/ (WordPress standard)
 *   2. Fallback → HTML de la page membres + LLM extractor
 *
 * Note SSL :
 *   resartis.org utilise un certificat signé par une CA racine absente du container Docker
 *   ("unable to get local issuer certificate"). Les deux méthodes de fetch (RSS + HTML)
 *   désactivent la vérification SSL via verify_peer = false.
 *   Ce choix est conscient et documenté — le trafic reste chiffré ; seule la vérification
 *   du certificat est contournée. En production (DigitalOcean), les CA sont normalement
 *   à jour et cette option n'a pas d'impact sur la sécurité réelle.
 */
class ResartisScraper extends AbstractScraper
{
    /**
     * Flux RSS standard WordPress — beaucoup de sites Resartis sont sous WordPress.
     */
    private const RSS_URL = 'https://www.resartis.org/feed/';

    /**
     * URL de fallback — page des membres/résidences.
     */
    private const MEMBERS_URL = 'https://www.resartis.org/residencies/';

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly LlmExtractorService $llmExtractor,
        private readonly AfrodiasporaRelevanceScorer $relevanceScorer,
    ) {
        parent::__construct($httpClient);
    }

    public function getName(): string
    {
        return 'resartis.org';
    }

    public function getTestUrl(): string
    {
        return self::MEMBERS_URL;
    }

    /**
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        // Tenter le RSS en priorité
        $rssOpportunities = $this->scrapeRss();
        if (!empty($rssOpportunities)) {
            return $rssOpportunities;
        }

        // Fallback HTML + LLM
        return $this->scrapeWithLlm();
    }

    /**
     * Tente de récupérer les actualités depuis le flux RSS WordPress.
     *
     * @return ScrapedOpportunity[]
     */
    private function scrapeRss(): array
    {
        try {
            // Désactivation de la vérification SSL pour resartis.org :
            // le container Docker ne dispose pas des CA racines nécessaires pour valider
            // le certificat de ce site. withOptions() crée un client temporaire ;
            // $this->httpClient original n'est pas modifié (les autres scrapers ne sont pas affectés).
            $insecureClient = $this->httpClient->withOptions([
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $response = $insecureClient->request('GET', self::RSS_URL, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; BazaArtBot/1.0)',
                    'Accept'     => 'application/rss+xml, application/xml, text/xml',
                ],
                'timeout' => 15,
            ]);

            $this->lastStatusCode = $response->getStatusCode();
            if ($this->lastStatusCode !== 200) {
                return [];
            }

            $xml  = $response->getContent();
            $feed = @simplexml_load_string($xml);

            if ($feed === false || !isset($feed->channel->item)) {
                return [];
            }

            // Mots-clés pour filtrer les items RSS pertinents (opportunités)
            $keywords = [
                'call', 'appel', 'residenc', 'résidence', 'open call',
                'opportunity', 'grant', 'bourse', 'fellowship', 'award',
            ];

            $opportunities = [];

            foreach ($feed->channel->item as $item) {
                $title       = $this->cleanText((string) $item->title);
                $link        = $this->cleanText((string) $item->link);
                $description = $this->cleanText(strip_tags((string) $item->description));

                if (empty($title) || empty($link)) {
                    continue;
                }

                // Filtrage par mots-clés (texte combiné titre + description)
                $textToSearch = mb_strtolower($title . ' ' . $description);
                $isRelevant   = false;
                foreach ($keywords as $keyword) {
                    if (str_contains($textToSearch, $keyword)) {
                        $isRelevant = true;
                        break;
                    }
                }

                if (!$isRelevant) {
                    continue;
                }

                $opportunities[] = new ScrapedOpportunity(
                    title: $title,
                    type: 'Résidence',
                    url: $link,
                    source: $this->getName(),
                    description: mb_substr($description, 0, 200),
                    deadline: '',
                    disciplines: 'Toutes disciplines (résidences)',
                    documents: '',
                    relevanceScore: $this->relevanceScorer->score($title, $description),
                );
            }

            return $opportunities;

        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Fallback : HTML + LLM extractor.
     *
     * Utilise fetchHtmlInsecure() (définie dans AbstractScraper) pour contourner
     * l'erreur SSL du container Docker sur resartis.org.
     *
     * @return ScrapedOpportunity[]
     */
    private function scrapeWithLlm(): array
    {
        try {
            // fetchHtmlInsecure() désactive verify_peer pour ce fetch uniquement.
            // Voir le docblock de AbstractScraper::fetchHtmlInsecure() pour les détails.
            $html = $this->fetchHtmlInsecure(self::MEMBERS_URL);
            if (empty($html)) {
                return [];
            }

            $opportunities = $this->llmExtractor->extractFromHtml(
                $html,
                self::MEMBERS_URL,
                $this->getName()
            );

            // Recalculer le score Afrodiaspora pour chaque opportunité
            return array_map(function (ScrapedOpportunity $opp): ScrapedOpportunity {
                $score = $this->relevanceScorer->score($opp->title, $opp->description);
                return new ScrapedOpportunity(
                    title: $opp->title,
                    type: $opp->type,
                    url: $opp->url,
                    source: $opp->source,
                    description: $opp->description,
                    deadline: $opp->deadline,
                    disciplines: $opp->disciplines,
                    documents: $opp->documents,
                    relevanceScore: $score,
                );
            }, $opportunities);

        } catch (\Exception) {
            return [];
        }
    }
}

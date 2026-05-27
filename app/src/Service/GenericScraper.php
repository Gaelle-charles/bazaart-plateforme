<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;
use App\Entity\ScrapingSource;
use App\Enum\ScrapingSourceType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GenericScraper — Scraper générique pour les sources sans classe PHP dédiée.
 *
 * Ce service est utilisé quand ScrapingSource.scraperSlug est null.
 * Il dispatche vers la méthode appropriée selon le type de la source :
 *
 *   RSS      → scrapeRss()     : parse le flux XML directement
 *   HTML_LLM → scrapeHtmlLlm() : fetch HTML + extraction via LlmExtractorService
 *   HTML_CSS → impossible       : nécessite des sélecteurs CSS spécifiques à chaque site
 *
 * Pourquoi ce service existe-t-il ?
 *   Les scrapers custom (CnapScraper, CnmScraper...) ont été écrits pour des sites précis
 *   avec leurs sélecteurs CSS ou leur logique de parsing. Pour les nouvelles sources ajoutées
 *   par l'admin depuis /admin/scraping-sources, on ne peut pas coder une classe dédiée à la volée.
 *   Ce scraper générique couvre les cas les plus courants sans code PHP custom.
 *
 * Limites :
 *   - Le filtre RSS est moins précis que les scrapers dédiés (pas de filtre disciplinaire)
 *   - L'extraction LLM coûte des tokens API Mistral / Anthropic
 *   - HTML_CSS ne peut jamais être générique (sélecteurs trop site-spécifiques)
 */
class GenericScraper
{
    /**
     * Mots-clés pour filtrer les items RSS jugés pertinents.
     *
     * Ces mots-clés sont plus larges que ceux des scrapers spécifiques
     * car on ne connaît pas la discipline de la source à l'avance.
     * Tous les items RSS contenant un de ces mots dans le titre ou la description
     * seront conservés.
     *
     * @var string[]
     */
    private const KEYWORDS = [
        'appel', 'candidature', 'bourse', 'résidence', 'résidences',
        'aide', 'soutien', 'prix', 'subvention', 'financement', 'grant',
        'fellowship', 'commission', 'call', 'award', 'mobility',
    ];

    public function __construct(
        // Client HTTP Symfony — injecté automatiquement par autowiring
        private readonly HttpClientInterface $httpClient,
        // LlmExtractorService — délègue l'extraction HTML au LLM (Mistral ou Anthropic)
        private readonly LlmExtractorService $llmExtractor,
        // Logger PSR-3 — logs des erreurs sans lever d'exception
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Scrape une source depuis la BDD selon son type.
     *
     * Dispatche vers la méthode interne appropriée.
     * HTML_CSS retourne [] car ce type ne peut pas être générique.
     *
     * @return ScrapedOpportunity[]
     */
    public function scrapeSource(ScrapingSource $source): array
    {
        return match($source->getType()) {
            ScrapingSourceType::RSS     => $this->scrapeRss($source),
            ScrapingSourceType::HtmlLlm => $this->scrapeHtmlLlm($source),
            // HTML_CSS exige toujours une classe dédiée — ce type ne peut pas être générique.
            // Si une source HTML_CSS se retrouve ici, elle a un bug de configuration.
            ScrapingSourceType::HtmlCss => [],
        };
    }

    /**
     * Parse un flux RSS 2.0 ou Atom depuis l'URL stockée en BDD.
     *
     * Logique similaire à AbstractRssScraper mais sans classe dédiée :
     *   1. Fetch le XML via HTTPClient
     *   2. Parse avec SimpleXML
     *   3. Filtre les items par mots-clés génériques
     *   4. Détecte le type d'opportunité via heuristique simple
     *
     * ── Différence entre RSS 2.0 et Atom ──────────────────────────────────
     * RSS 2.0 : les entrées sont dans $feed->channel->item
     *   <rss><channel><item>...</item></channel></rss>
     *
     * Atom (RFC 4287) : les entrées sont directement dans $feed->entry (sans channel)
     *   <feed><entry>...</entry></feed>
     *   De plus, le lien Atom n'est PAS une simple valeur texte : c'est un tag
     *   <link href="..." rel="alternate"/> — il faut lire l'attribut href.
     *
     * Ce code supporte les deux formats. Il ne supporte PAS :
     *   - les extensions RSS Media (<media:content>, <media:group>)
     *   - les flux JSON Feed (format application/feed+json)
     *   - le rendu JavaScript côté client (Algolia, React SPA, etc.)
     *
     * @return ScrapedOpportunity[]
     */
    private function scrapeRss(ScrapingSource $source): array
    {
        $opportunities = [];

        try {
            $response = $this->httpClient->request('GET', $source->getUrl(), [
                'headers' => [
                    // User-Agent bot identifié — transparent sur qui fait la requête
                    'User-Agent' => 'Mozilla/5.0 (compatible; BazaArtBot/1.0)',
                    'Accept'     => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
                ],
                'timeout' => 20,
            ]);

            if ($response->getStatusCode() !== 200) {
                // On retourne [] silencieusement — la commande gère l'erreur via markRunError
                return [];
            }

            $xml  = $response->getContent();
            $feed = @simplexml_load_string($xml);

            if ($feed === false) {
                $this->logger->warning('[GenericScraper] XML invalide', ['url' => $source->getUrl()]);
                return [];
            }

            // ── Détection du format : RSS 2.0 vs Atom ─────────────────────────
            // RSS 2.0 expose $feed->channel->item.
            // Atom expose $feed->entry directement à la racine (pas de channel).
            // Si aucun des deux n'est présent, le flux est vide ou dans un format inconnu.
            $items = $feed->channel->item ?? $feed->entry ?? [];

            foreach ($items as $item) {
                $title       = trim((string) $item->title);

                // ── Extraction du lien ─────────────────────────────────────────
                // RSS 2.0 : <link>https://...</link> → simple valeur texte
                // Atom    : <link href="https://..." rel="alternate"/> → attribut href
                $link = trim((string) $item->link);
                if ($link === '') {
                    // Tentative Atom : lecture de l'attribut href du tag <link>
                    $atomLink = $item->link;
                    if ($atomLink !== null) {
                        $attrs = $atomLink->attributes();
                        $link  = trim((string) ($attrs['href'] ?? ''));
                    }
                }

                // ── Extraction de la description ──────────────────────────────
                // RSS 2.0 : <description>
                // Atom    : <summary> ou <content> (fallback dans cet ordre)
                $description = trim(strip_tags(
                    (string) ($item->description ?? $item->summary ?? $item->content ?? '')
                ));

                // ── Extraction de la date de publication ──────────────────────
                // RSS 2.0 : <pubDate>
                // Atom    : <updated> (dernière modif) ou <published>
                $pubDate = trim((string) ($item->pubDate ?? $item->updated ?? $item->published ?? ''));

                // On ignore les items sans titre ni lien — incomplets
                if (empty($title) || empty($link)) {
                    continue;
                }

                // Filtre par mots-clés génériques (titre + description)
                // Un item est pertinent s'il contient au moins un mot-clé
                // $textLower est calculé une seule fois et réutilisé pour le filtre ET la détection de type
                $textLower = mb_strtolower($title . ' ' . $description);
                $relevant  = false;
                foreach (self::KEYWORDS as $kw) {
                    if (str_contains($textLower, $kw)) {
                        $relevant = true;
                        break;
                    }
                }

                if (!$relevant) {
                    continue;
                }

                // Formatage de la date de publication RSS (format libre → JJ/MM/AAAA)
                $formattedDate = '';
                if (!empty($pubDate)) {
                    try {
                        $formattedDate = (new \DateTime($pubDate))->format('d/m/Y');
                    } catch (\Exception) {
                        // Si le format de date est inconnu, on garde la valeur brute
                        $formattedDate = $pubDate;
                    }
                }

                // Détection heuristique du type d'opportunité
                // (moins précise que les scrapers dédiés mais suffisante pour le générique)
                // Réutilise $textLower déjà calculé lors du filtre par mots-clés — pas de recalcul.
                $type = 'Appel à projets'; // valeur par défaut
                if (str_contains($textLower, 'résidence')) {
                    $type = 'Résidence';
                } elseif (str_contains($textLower, 'bourse')) {
                    $type = 'Bourse';
                } elseif (str_contains($textLower, 'prix')) {
                    $type = 'Prix';
                } elseif (str_contains($textLower, 'financement') || str_contains($textLower, 'soutien')) {
                    $type = 'Financement';
                }

                // Extraction du domaine pour le champ source (ex: "cnm.fr")
                $sourceName = parse_url($source->getUrl(), PHP_URL_HOST) ?: $source->getNom();

                $opportunities[] = new ScrapedOpportunity(
                    title: $title,
                    type: $type,
                    url: $link,
                    source: $sourceName,
                    description: mb_substr($description, 0, 200),
                    deadline: $formattedDate,
                    // disciplines vient de la ScrapingSource — défini lors du seed ou de l'ajout admin
                    disciplines: $source->getDisciplinePrincipale() ?? 'Toutes disciplines',
                );
            }

        } catch (\Exception $e) {
            $this->logger->warning('[GenericScraper] Erreur RSS', [
                'url'   => $source->getUrl(),
                'error' => $e->getMessage(),
            ]);
        }

        return $opportunities;
    }

    /**
     * Fetch le HTML d'une page puis délègue l'extraction au LlmExtractorService.
     *
     * Le LLM (Mistral ou Anthropic selon la config) reçoit le texte nettoyé
     * et retourne une liste structurée d'opportunités.
     *
     * Prérequis : clé API LLM configurée dans /admin/settings.
     * Si la clé est absente → LlmExtractorService retourne [] avec un warning dans les logs.
     *
     * @return ScrapedOpportunity[]
     */
    private function scrapeHtmlLlm(ScrapingSource $source): array
    {
        try {
            $response = $this->httpClient->request('GET', $source->getUrl(), [
                'headers' => [
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Accept'          => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                ],
                'timeout' => 20,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $html       = $response->getContent();
            // Le nom du site source = domaine de l'URL, fallback sur le nom BDD
            $sourceSite = parse_url($source->getUrl(), PHP_URL_HOST) ?: $source->getNom();

            // Délégation complète au LlmExtractorService
            // Il gère : nettoyage HTML, appel API, parsing JSON, mapping vers ScrapedOpportunity[]
            return $this->llmExtractor->extractFromHtml($html, $source->getUrl(), $sourceSite);

        } catch (\Exception $e) {
            $this->logger->warning('[GenericScraper] Erreur HTML/LLM', [
                'url'   => $source->getUrl(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}

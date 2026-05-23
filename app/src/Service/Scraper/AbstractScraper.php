<?php

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * AbstractScraper — Classe de base pour tous les scrapers.
 *
 * Chaque site culturel aura son propre scraper qui étend cette classe.
 * Ici on regroupe les comportements communs : faire une requête HTTP,
 * parser le HTML avec DomCrawler, et gérer les erreurs.
 *
 * Concept "abstrait" : cette classe ne peut pas être utilisée directement,
 * il faut obligatoirement créer un scraper spécifique qui l'étend.
 */
abstract class AbstractScraper
{
    // Propriétés pour le debug — remplies à chaque appel à fetch()
    protected int $lastStatusCode = 0;
    protected string $lastError = '';
    protected int $lastFetchedLength = 0;

    public function __construct(
        // HttpClient de Symfony pour faire des requêtes HTTP (GET sur les sites)
        protected readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Scrape le site et retourne la liste des opportunités trouvées.
     * Chaque scraper enfant doit implémenter cette méthode.
     *
     * @return ScrapedOpportunity[] Liste des opportunités trouvées
     */
    abstract public function scrape(): array;

    /**
     * Retourne le nom lisible du site (ex: "CNAP - Arts plastiques").
     * Utilisé dans les logs et dans Google Sheets pour identifier la source.
     */
    abstract public function getName(): string;

    /**
     * Retourne l'URL principale à tester en mode debug.
     */
    abstract public function getTestUrl(): string;

    /**
     * Télécharge le HTML d'une URL et retourne un objet Crawler.
     *
     * DomCrawler est un outil Symfony qui permet de naviguer dans le HTML
     * comme on le ferait avec jQuery en JavaScript (sélecteurs CSS, XPath...).
     *
     * @param string $url L'URL à télécharger
     * @return Crawler|null null si la requête échoue
     */
    protected function fetch(string $url): ?Crawler
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                // On se fait passer pour un navigateur pour éviter les blocages
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Cache-Control' => 'no-cache',
                ],
                // Timeout de 20 secondes
                'timeout' => 20,
                // Suit les redirections automatiquement (302, 301...)
                'max_redirects' => 5,
            ]);

            $statusCode = $response->getStatusCode();

            // Stocke le dernier code HTTP pour le debug
            $this->lastStatusCode = $statusCode;

            if ($statusCode !== 200) {
                return null;
            }

            $html = $response->getContent();
            $this->lastFetchedLength = strlen($html);

            return new Crawler($html);

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Retourne des informations de debug sur le dernier fetch.
     * Utile pour comprendre pourquoi un scraper ne trouve rien.
     */
    public function getDebugInfo(string $url): array
    {
        $this->lastStatusCode = 0;
        $this->lastError = '';
        $this->lastFetchedLength = 0;

        $crawler = $this->fetch($url);

        return [
            'url'            => $url,
            'status_code'    => $this->lastStatusCode,
            'error'          => $this->lastError,
            'html_length'    => $this->lastFetchedLength,
            'crawler_ok'     => $crawler !== null,
            // Compte quelques sélecteurs courants pour voir ce qui existe dans la page
            'selectors'      => $crawler ? [
                'h2 a'                          => $crawler->filter('h2 a')->count(),
                'h3 a'                          => $crawler->filter('h3 a')->count(),
                'article'                       => $crawler->filter('article')->count(),
                '.wp-block-cnm-cnm-card'        => $crawler->filter('.wp-block-cnm-cnm-card')->count(),
                '.news-item'                    => $crawler->filter('.news-item')->count(),
                'a[href]'                       => $crawler->filter('a[href]')->count(),
            ] : [],
        ];
    }

    /**
     * Nettoie un texte : supprime les espaces multiples, retours à la ligne
     * et caractères invisibles récupérés lors du scraping.
     *
     * @param string $text Texte brut du HTML
     * @return string Texte nettoyé
     */
    protected function cleanText(string $text): string
    {
        // Remplace tous les espaces blancs multiples (espaces, tabs, retours) par un espace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Construit une URL absolue à partir d'une URL relative.
     *
     * Certains liens dans le HTML sont relatifs : "/actualites/mon-appel"
     * On doit les transformer en absolus : "https://monsite.fr/actualites/mon-appel"
     *
     * @param string $href    L'attribut href du lien (peut être relatif ou absolu)
     * @param string $baseUrl L'URL de base du site (ex: "https://cnap.fr")
     * @return string URL absolue
     */
    protected function absoluteUrl(string $href, string $baseUrl): string
    {
        // Si le lien commence déjà par http, il est déjà absolu
        if (str_starts_with($href, 'http')) {
            return $href;
        }

        // Si le lien commence par //, c'est un lien sans protocole
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        // Sinon on construit l'URL absolue
        return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
    }
}

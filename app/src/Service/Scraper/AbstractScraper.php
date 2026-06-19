<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;
use App\Service\HttpBrowserFetchTrait;
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
    // Fournit buildBrowserHeaders() et requestWithRetry() — centralise les en-têtes
    // Chrome 124 complets (Sec-Fetch-*) et la logique de retry (3 tentatives, backoff 1s/2s).
    // AbstractScraper n'a pas de LoggerInterface injecté, donc requestWithRetry() sera
    // appelé avec null comme logger — les retries auront lieu sans log de trace.
    use HttpBrowserFetchTrait;

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
     * EN-TÊTES : buildBrowserHeaders() (trait HttpBrowserFetchTrait) envoie un bloc
     * Chrome 124 complet avec Sec-Fetch-* — plus efficace que le seul User-Agent Chrome.
     *
     * RETRY : requestWithRetry() relance 3 fois sur timeout / 429 / 5xx.
     * AbstractScraper n'a pas de logger → les retries sont silencieux (logger = null).
     *
     * IMPORTANT — Accept-Encoding intentionnellement ABSENT de buildBrowserHeaders() :
     *   Quand Accept-Encoding est posé manuellement, Symfony HTTP Client bypasse sa
     *   décompression automatique → getContent() retourne des octets gzip bruts (~25%
     *   de la taille réelle), ce qui casse DomCrawler. Symfony décompresse seul si
     *   Accept-Encoding n'est pas forcé — on ne l'interfère donc pas.
     *
     * @param string $url L'URL à télécharger
     * @return Crawler|null null si la requête échoue
     */
    protected function fetch(string $url): ?Crawler
    {
        // Options : en-têtes Chrome 124 + timeout 22s (un peu plus généreux qu'avant)
        $options = [
            // buildBrowserHeaders() n'inclut PAS Accept-Encoding — voir docblock ci-dessus.
            'headers'       => $this->buildBrowserHeaders(),
            // Timeout 22s — légèrement plus généreux que l'ancien 20s pour les sites lents.
            'timeout'       => 22,
            // Suit les redirections automatiquement (302, 301...).
            'max_redirects' => 5,
        ];

        // requestWithRetry() : 3 tentatives, backoff 1s/2s (logger null = silencieux).
        $response = $this->requestWithRetry($this->httpClient, null, 'GET', $url, $options);

        if ($response === null) {
            // Toutes les tentatives ont échoué (timeout, DNS, SSL, etc.)
            $this->lastError = 'Echec réseau après 3 tentatives';
            return null;
        }

        try {
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
     *
     * @return array<string, mixed> Tableau contenant url, status_code, error, html_length, crawler_ok, selectors
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
     * Télécharge le HTML d'une URL et le retourne sous forme de string brute.
     *
     * Contrairement à fetch() qui retourne un Crawler (pour le parsing CSS),
     * cette méthode retourne le HTML brut — utile pour l'envoyer au LLM
     * qui fera lui-même l'extraction.
     *
     * Memes améliorations que fetch() : en-têtes Chrome 124 complets + retry 3x.
     * Accept-Encoding absent intentionnellement (même raison que fetch()).
     *
     * @param string $url L'URL à télécharger
     * @return string HTML brut, ou chaîne vide si la requête échoue
     */
    protected function fetchHtml(string $url): string
    {
        $options = [
            // buildBrowserHeaders() n'inclut PAS Accept-Encoding — voir docblock de fetch().
            'headers'       => $this->buildBrowserHeaders(),
            'timeout'       => 22,
            'max_redirects' => 5,
        ];

        $response = $this->requestWithRetry($this->httpClient, null, 'GET', $url, $options);

        if ($response === null) {
            $this->lastError = 'Echec réseau après 3 tentatives';
            return '';
        }

        try {
            $this->lastStatusCode = $response->getStatusCode();

            if ($this->lastStatusCode !== 200) {
                return '';
            }

            $html = $response->getContent();
            $this->lastFetchedLength = strlen($html);

            return $html;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return '';
        }
    }

    /**
     * Télécharge le HTML d'une URL en désactivant la vérification SSL.
     *
     * Pourquoi cette méthode séparée (et non un flag dans fetchHtml) ?
     *   Désactiver SSL globalement est dangereux (man-in-the-middle possible).
     *   On isole ce comportement dans une méthode dédiée afin que chaque scraper
     *   choisisse explicitement de l'utiliser, et que le risque soit visible dans le code.
     *
     * Cas d'usage identifié :
     *   resartis.org — le container Docker n'a pas les CA racines nécessaires pour
     *   valider le certificat de ce site (erreur "unable to get local issuer certificate").
     *   En production sur le droplet DigitalOcean, les CA sont normalement à jour ;
     *   cette option ne devrait pas poser de problème de sécurité réel en prod.
     *   En développement Docker, c'est le seul moyen de contourner l'erreur SSL.
     *
     * Note : verify_peer = false ne chiffre PAS moins la connexion — il évite seulement
     *   la vérification du certificat serveur. Le trafic reste chiffré (HTTPS).
     *
     * @param string $url L'URL HTTPS à télécharger sans vérification SSL
     * @return string HTML brut, ou chaîne vide si la requête échoue
     */
    protected function fetchHtmlInsecure(string $url): string
    {
        try {
            // withOptions() crée un nouveau client HTTP configuré sans vérif SSL.
            // On n'écrase pas $this->httpClient — le client original reste inchangé
            // pour toutes les autres requêtes de ce scraper (comportement sûr).
            $insecureClient = $this->httpClient->withOptions([
                'verify_peer' => false,   // Désactive la vérification du certificat serveur
                'verify_host' => false,   // Désactive aussi la vérification du nom d'hôte
            ]);

            $options = [
                // En-têtes Chrome 124 complets via trait — meme logique que fetch().
                // Accept-Encoding absent intentionnellement (voir docblock de fetch()).
                'headers'       => $this->buildBrowserHeaders(),
                'timeout'       => 22,
                'max_redirects' => 5,
            ];

            // requestWithRetry() : 3 tentatives, backoff 1s/2s (logger null = silencieux).
            // On passe $insecureClient (SSL désactivé) au lieu de $this->httpClient.
            $response = $this->requestWithRetry($insecureClient, null, 'GET', $url, $options);

            if ($response === null) {
                $this->lastError = 'Echec réseau après 3 tentatives (SSL désactivé)';
                return '';
            }

            $this->lastStatusCode = $response->getStatusCode();

            if ($this->lastStatusCode !== 200) {
                return '';
            }

            $html = $response->getContent();
            $this->lastFetchedLength = strlen($html);

            return $html;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return '';
        }
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

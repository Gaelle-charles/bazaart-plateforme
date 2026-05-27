<?php

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * CnmScraper — Scrape les opportunités du CNM (Centre National de la Musique).
 *
 * Deux sources combinées :
 *   1. Flux RSS général (https://cnm.fr/feed/) — 10 dernières actualités filtrées
 *      par mots-clés (appel, résidence, bourse…). Hérité de AbstractRssScraper.
 *
 *   2. API REST WordPress (https://cnm.fr/wp-json/wp/v2/posts?categories=42)
 *      La catégorie 42 = "appels-a-projets" contient ~376 articles.
 *      On récupère les 40 plus récents (2 pages de 20) via l'API JSON, sans scraping HTML.
 *
 * Pourquoi l'API REST WP plutôt que le scraping CSS ?
 *   La page /appels-a-projets/ est un site WordPress qui utilise WordPress Interactivity API
 *   (WP 6.5+) : les cartes sont rendues côté client par JavaScript. Le HTML statique
 *   servi par le serveur ne contient donc pas les cartes — DomCrawler ne peut pas les lire.
 *   L'API REST WP, elle, retourne directement du JSON propre, stable et sans JS.
 *
 * Convention Symfony utilisée : surcharge (override) de la méthode parente scrape()
 * pour enrichir le comportement sans le remplacer entièrement.
 */
class CnmScraper extends AbstractRssScraper
{
    /**
     * ID de la catégorie WordPress "appels-a-projets" sur cnm.fr.
     * Vérifié via https://cnm.fr/wp-json/wp/v2/categories le 26 mai 2026.
     */
    private const CATEGORY_ID_APPELS = 42;

    /**
     * Nombre d'articles par page pour l'API REST WP.
     * On récupère 20 articles par appel, soit 40 au total sur 2 pages.
     */
    private const API_PER_PAGE = 20;

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

    /**
     * URL à tester en mode debug — on pointe vers la page principale des appels.
     */
    public function getTestUrl(): string
    {
        return 'https://cnm.fr/appels-a-projets/';
    }

    /**
     * Surcharge de scrape() pour combiner deux sources :
     *   - Le flux RSS CNM (actualités filtrées par mots-clés, via parent)
     *   - L'API REST WP catégorie "appels-a-projets" (pages 1 et 2, JSON pur)
     *
     * On scrape uniquement les deux premières pages API (40 appels les plus récents)
     * pour rester raisonnable en temps d'exécution. Augmenter $pages si besoin.
     *
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        // 1. Récupère les actualités du flux RSS (filtrage par mots-clés intégré dans parent)
        $opportunities = parent::scrape();

        // 2. Ajoute les appels à projets via l'API REST WP (sans filtre : tout = opportunité)
        $opportunities = array_merge($opportunities, $this->scrapeApiPage(1));
        $opportunities = array_merge($opportunities, $this->scrapeApiPage(2));

        return $opportunities;
    }

    /**
     * Récupère une page de la catégorie "appels-a-projets" via l'API REST WordPress.
     *
     * Endpoint : GET https://cnm.fr/wp-json/wp/v2/posts
     * Paramètres :
     *   categories=42       → filtre sur la catégorie "appels-a-projets"
     *   per_page=20         → 20 articles par page
     *   page={n}            → numéro de page
     *   orderby=date        → les plus récents en premier
     *   order=desc
     *   _fields=id,title,link,date,excerpt
     *              → minimise la taille de la réponse JSON (on n'a pas besoin du contenu complet)
     *
     * Structure d'un item JSON retourné :
     * {
     *   "id": 12345,
     *   "title": { "rendered": "Taïwan | Appel à candidatures…" },
     *   "link": "https://cnm.fr/appels-a-projets/taiwan-appel-...",
     *   "date": "2026-05-26T11:25:26",
     *   "excerpt": { "rendered": "<p>Date limite de candidature…</p>" }
     * }
     *
     * @param int $page Numéro de page (1-based)
     * @return ScrapedOpportunity[]
     */
    private function scrapeApiPage(int $page): array
    {
        // Construction de l'URL de l'API REST WP avec tous les paramètres
        $apiUrl = sprintf(
            'https://cnm.fr/wp-json/wp/v2/posts?categories=%d&per_page=%d&page=%d&orderby=date&order=desc&_fields=id,title,link,date,excerpt',
            self::CATEGORY_ID_APPELS,
            self::API_PER_PAGE,
            $page
        );

        try {
            // On fait une requête HTTP GET vers l'API JSON
            // Note : on utilise httpClient directement (pas fetch() qui crée un DomCrawler HTML)
            $response = $this->httpClient->request('GET', $apiUrl, [
                'headers' => [
                    // L'API REST WP accepte les requêtes sans authentification pour les contenus publics
                    'User-Agent' => 'Mozilla/5.0 (compatible; BazaArtBot/1.0)',
                    'Accept'     => 'application/json',
                ],
                'timeout' => 20,
            ]);

            $this->lastStatusCode = $response->getStatusCode();

            if ($this->lastStatusCode !== 200) {
                // Page inexistante ou erreur serveur — on arrête silencieusement
                return [];
            }

            // Décode le JSON retourné par l'API WP
            // json_decode() retourne un tableau associatif PHP (true = array, pas objet)
            /** @var array<int, array<string, mixed>> $posts */
            $posts = json_decode($response->getContent(), true);

            if (!is_array($posts) || empty($posts)) {
                return [];
            }

            $this->lastFetchedLength = strlen($response->getContent());

        } catch (\Exception $e) {
            // Timeout, connexion refusée, etc.
            $this->lastError = $e->getMessage();
            return [];
        }

        $opportunities = [];

        foreach ($posts as $post) {
            // -- Titre (l'API retourne le HTML décodé dans "rendered") --
            // htmlspecialchars_decode() convertit &rsquo; → ', &amp; → &, etc.
            $title = $this->cleanText(
                htmlspecialchars_decode(strip_tags($post['title']['rendered'] ?? ''), ENT_QUOTES)
            );

            // -- URL directe vers l'article --
            $url = $this->cleanText($post['link'] ?? '');

            if (empty($title) || empty($url)) {
                // Article sans titre ou sans URL → on ignore
                continue;
            }

            // -- Extrait (description courte) --
            // L'extrait contient souvent la date limite de candidature en texte.
            // Exemples observés sur cnm.fr :
            //   "Date limite de candidature : 4 juin, minuit"
            //   "Vous avez jusqu'au 31 mai 2026 pour proposer une candidature"
            // On décode les entités HTML (&rsquo;, &hellip;…) AVANT d'extraire la deadline.
            $excerptRaw = $post['excerpt']['rendered'] ?? '';
            $excerptClean = $this->cleanText(
                html_entity_decode(strip_tags($excerptRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
            // On tronque à 200 caractères pour rester cohérent avec le RSS
            $description = mb_substr($excerptClean, 0, 200);

            // -- Deadline : extraite de l'extrait, PAS la date de publication --
            // La date de publication (post['date']) n'est PAS la deadline de candidature.
            // Stocker la date de publication comme deadline causerait l'archivage immédiat
            // des items "anciens" (publiés il y a plusieurs semaines) par archiveExpired().
            // On extrait la vraie deadline depuis l'extrait, ou on laisse vide.
            $deadline = $this->extractDeadlineFromExcerpt($excerptClean);

            $opportunities[] = new ScrapedOpportunity(
                title: $title,
                // On détecte le type à partir du titre (pas de tag catégorie dans _fields)
                type: $this->detectType($title . ' ' . $excerptClean),
                url: $url,
                source: 'cnm.fr',
                description: $description,
                deadline: $deadline,
                disciplines: 'Musique',
            );
        }

        return $opportunities;
    }

    /**
     * Tente d'extraire la date limite de candidature depuis le texte d'un extrait CNM.
     *
     * Le CNM formule les deadlines de plusieurs façons différentes dans ses excerpts :
     *   "Date limite de candidature : 4 juin, minuit"
     *   "Date limite de candidature : 15 juin 2026"
     *   "Vous avez jusqu'au 31 mai 2026 pour..."
     *   "jusqu'au 24 mai au plus tard"
     *   "avant le 15 juin 2026"
     *
     * Si aucun pattern ne correspond → retourne '' (chaîne vide).
     * archiveExpired() ignore les deadlines vides → pas d'archivage prématuré.
     *
     * @param string $excerpt Texte brut de l'extrait (HTML et entités déjà décodés)
     * @return string Deadline trouvée (ex: "4 juin", "31 mai 2026") ou '' si non trouvée
     */
    private function extractDeadlineFromExcerpt(string $excerpt): string
    {
        if (empty($excerpt)) {
            return '';
        }

        // Pattern 1 : "Date limite de candidature : 4 juin, minuit" ou "Date limite : 15 juin 2026"
        // Le groupe capture : "4 juin" ou "15 juin 2026" (avec ou sans heure et année)
        if (preg_match(
            '/date limite[^:]*:\s*(\d{1,2}\s+\w+(?:\s+\d{4})?)/iu',
            $excerpt,
            $matches
        )) {
            // Supprime ce qui suit la deadline (", minuit", "au plus tard"...)
            $raw = preg_replace('/[,;]\s*.+$/u', '', $matches[1]);
            return $this->cleanText($raw ?? $matches[1]);
        }

        // Pattern 2 : "jusqu'au 31 mai 2026" ou "jusqu'au 24 mai au plus tard"
        if (preg_match(
            '/jusqu\'au\s+(\d{1,2}\s+\w+(?:\s+\d{4})?)/iu',
            $excerpt,
            $matches
        )) {
            // Supprime " au plus tard", " pour candidater"...
            $raw = preg_replace('/\s+(au\s+plus|pour\s+).+$/iu', '', $matches[1]);
            return $this->cleanText($raw ?? $matches[1]);
        }

        // Pattern 3 : "avant le 15 juin 2026"
        if (preg_match(
            '/avant le\s+(\d{1,2}\s+\w+\s+\d{4})/iu',
            $excerpt,
            $matches
        )) {
            return $this->cleanText($matches[1]);
        }

        // Aucun pattern trouvé → deadline inconnue, vaut mieux laisser vide
        // que de mettre la date de publication (qui déclencherait un archivage prématuré)
        return '';
    }
}

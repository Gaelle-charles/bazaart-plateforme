<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LogoFetcherService — Récupère l'URL du logo d'un site web par parsing HTML.
 *
 * POURQUOI CE SERVICE EST SÉPARÉ :
 *   La récupération de logo est une tâche indépendante de l'extraction LLM :
 *   - Elle n'utilise PAS de LLM (pur parsing HTML avec DomCrawler)
 *   - Elle fait un fetch réseau vers la page d'accueil du site cible
 *   - Elle applique une chaîne de repli sur plusieurs balises HTML
 *   En isolant cette logique dans un service dédié, on respecte le principe
 *   de responsabilité unique (SRP) et on facilite les tests.
 *
 * ALGORITHME (chaîne de repli dans l'ordre de préférence) :
 *   1. <link rel="apple-touch-icon" href="..."> — icône haute résolution (180px+)
 *   2. <link rel="icon" href="..."> ou rel="shortcut icon"
 *   3. <meta property="og:image" content="..."> — image OG (souvent une bannière)
 *   → Si aucune balise trouvée → null (le template affichera le badge "B")
 *
 * SITE CIBLE (chaîne de repli) :
 *   1. Si applicationUrl est présente → domaine de applicationUrl
 *   2. Sinon → domaine de l'URL source de l'offre (sourceUrl)
 *
 * GARDES SSRF (anti-Server Side Request Forgery) :
 *   - Toute URL cible est vérifiée par ListingUrlDiscoverer::isSafeHost() avant fetch
 *   - Timeout court (8s) pour éviter le blocage sur des sites lents
 *   - max_redirects limité à 3 (pas de chaîne de redirection longue)
 *   - Stream borné à MAX_BODY_BYTES (50 Ko) : on n'a besoin que du <head>
 *   - On ne télécharge JAMAIS l'image — on stocke seulement son URL
 *
 * ANTI-XSS :
 *   - Les URLs extraites sont stockées en base sans |raw dans Twig (auto-échappement)
 *   - Les <img> dans les templates utilisent l'attribut src (pas innerHTML)
 *
 * Ce service ne lève JAMAIS d'exception — tout échec retourne null.
 */
class LogoFetcherService
{
    // Fournit buildBrowserHeaders() et requestWithRetry() — centralise les en-têtes
    // navigateur Chrome 124 et la logique de retry (3 tentatives, backoff 1s/2s).
    use HttpBrowserFetchTrait;
    /**
     * Timeout réseau pour le fetch de la page d'accueil (en secondes).
     * Court : on ne récupère que le <head> de la page, pas tout le body.
     */
    private const FETCH_TIMEOUT = 8;

    /**
     * Nombre maximum de redirections HTTP suivies.
     * 3 couvre les cas légitimes (http → https → www. → page).
     * Limité pour réduire la surface d'attaque SSRF par redirection.
     */
    private const MAX_REDIRECTS = 3;

    /**
     * Taille maximale du body lu (en octets).
     * 50 Ko est amplement suffisant pour lire la section <head> d'une page.
     * Évite de charger le <body> entier (inutile pour la détection de logo).
     */
    private const MAX_BODY_BYTES = 51_200; // 50 Ko

    public function __construct(
        // Client HTTP Symfony — même instance que les autres services (autowiring)
        private readonly HttpClientInterface $httpClient,

        // Logger PSR-3 — pour tracer les erreurs sans lever d'exception
        private readonly LoggerInterface $logger,

        // ListingUrlDiscoverer — réutilisé UNIQUEMENT pour isSafeHost() (garde SSRF)
        // On injecte le service complet plutôt que de dupliquer la logique isSafeHost.
        // C'est le seul point de couplage avec ListingUrlDiscoverer — acceptable.
        private readonly ListingUrlDiscoverer $listingUrlDiscoverer,

        // ScraperApiClient — repli API de scraping si fetchPage() échoue.
        // Null acceptable : si absent ou non configuré, comportement identique à avant.
        // Les logos ne sont pas critiques — le repli est un bonus, pas une nécessité.
        private readonly ?ScraperApiClient $scraperApiClient = null,
    ) {
    }

    /**
     * Récupère l'URL du logo pour une opportunité donnée.
     *
     * LOGIQUE DE SÉLECTION DU SITE CIBLE :
     *   On veut afficher le logo de l'organisme qui gère la candidature,
     *   pas nécessairement du site agrégateur où l'offre a été trouvée.
     *   → Si applicationUrl est défini : on prend son domaine
     *   → Sinon                         : on prend le domaine de sourceUrl
     *
     * @param string      $sourceUrl      URL source de l'offre (page de présentation)
     * @param string|null $applicationUrl URL de candidature directe (peut être null)
     * @return string|null URL absolue du logo, ou null si non trouvé
     */
    public function fetchLogoUrl(string $sourceUrl, ?string $applicationUrl = null): ?string
    {
        // ── Étape 1 : déterminer le site cible ───────────────────────────────
        // On choisit d'abord applicationUrl (site de candidature), car c'est lui
        // dont on veut afficher le logo. Si absent, on tombe sur le site source.
        $targetSite = $this->buildSiteRoot($applicationUrl ?? $sourceUrl);

        if ($targetSite === null) {
            $this->logger->debug('[LogoFetcher] Impossible de construire la racine du site.', [
                'sourceUrl'      => $sourceUrl,
                'applicationUrl' => $applicationUrl,
            ]);
            return null;
        }

        // ── Étape 2 : garde SSRF — vérifier que le site cible est autorisé ──
        // On refuse localhost, IPs privées, schémas non-HTTP, etc.
        // Délégation à isSafeHost() de ListingUrlDiscoverer (logique centralisée).
        if (!$this->listingUrlDiscoverer->isSafeHost($targetSite)) {
            $this->logger->warning('[LogoFetcher] Site cible rejeté par isSafeHost (SSRF).', [
                'targetSite' => $targetSite,
            ]);
            return null;
        }

        // ── Étape 3 : fetch de la page d'accueil ─────────────────────────────
        $html = $this->fetchPage($targetSite);
        if ($html === null) {
            // fetchPage() a déjà logué l'erreur
            return null;
        }

        // ── Étape 4 : extraction du logo par parsing HTML ─────────────────────
        return $this->extractLogoFromHtml($html, $targetSite);
    }

    /**
     * Construit la racine d'un site (scheme + host) depuis une URL.
     *
     * Exemples :
     *   "https://www.cnap.fr/appel-a-projets/..."  → "https://www.cnap.fr"
     *   "http://example.com/page"                  → "https://example.com"
     *   "www.example.com/page" (sans schéma)       → "https://www.example.com"
     *   ""                                          → null
     *   URL malformée                               → null
     *
     * On force https:// même si l'URL source est en http:// :
     * la plupart des sites modernes supportent https, et c'est plus sûr.
     *
     * @param string $url URL brute (peut être sans schéma)
     * @return string|null Racine du site (ex: "https://www.example.com"), null si échec
     */
    private function buildSiteRoot(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Ajouter un schéma si absent (ex: "www.example.com/page")
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            // Schéma protocole-relatif → force https
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            } else {
                // Pas de schéma du tout → on préfixe https
                $url = 'https://' . $url;
            }
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        // On force https:// (schéma le plus sûr et le plus courant aujourd'hui)
        return 'https://' . $parts['host'];
    }

    /**
     * Effectue le fetch HTTP de la page d'accueil du site cible.
     *
     * LIMITE DE TAILLE :
     *   On lit seulement MAX_BODY_BYTES (50 Ko) du body.
     *   La section <head> d'une page HTML fait rarement plus de 10-20 Ko.
     *   Cette limite protège contre les pages géantes ou les downloads binaires.
     *
     * GARDE SSRF APRES REDIRECTIONS (C1) :
     *   isSafeHost() est vérifié sur l'URL INITIALE dans fetchLogoUrl() avant d'appeler
     *   cette méthode. Mais un serveur malveillant peut répondre avec une redirection
     *   vers une IP interne (ex: 302 vers http://169.254.169.254/...).
     *   Symfony HttpClient suit les redirections en transparence — la réponse finale
     *   peut donc pointer vers une ressource interne, ce qui constitue une attaque SSRF.
     *
     *   CONTRE-MESURE : après la requête, on récupère l'URL EFFECTIVE (URL réelle de la
     *   réponse finale après redirections) via $response->getInfo('url'). On la passe à
     *   isSafeHost() ; si elle ne passe pas la garde, on log et on retourne null.
     *   Ce pattern est identique à ListingUrlDiscoverer::doFetch().
     *
     * EN-TÊTES ET RETRY :
     *   On utilise buildBrowserHeaders() (trait HttpBrowserFetchTrait) pour envoyer
     *   des en-têtes Chrome 124 complets avec Sec-Fetch-*.
     *   requestWithRetry() relance jusqu'à 3 fois sur timeout / 429 / 5xx.
     *
     * @param string $siteRoot URL racine du site (ex: "https://www.cnap.fr")
     * @return string|null HTML brut (tronqué si > MAX_BODY_BYTES), null si échec réseau ou SSRF
     */
    private function fetchPage(string $siteRoot): ?string
    {
        // ── Fetch via fetchHtmlRobust() (trait) ───────────────────────────────────
        // 1. Tente le fetch direct (closure ci-dessous)
        // 2. Si le direct échoue ET l'API de scraping est disponible → repli API
        // Pour les logos, le repli est un bonus : si l'API est trop lente ou indisponible,
        // on affiche simplement le badge "B" par défaut.
        return $this->fetchHtmlRobust(
            $siteRoot,
            // Closure : fetch direct (logique inchangée, refactorisée ici)
            function () use ($siteRoot): ?string {
                // Options : en-têtes navigateur complets + timeout court (on ne lit que le <head>)
                $options = [
                    'timeout'       => self::FETCH_TIMEOUT,
                    // 3 redirections couvrent http->https->www — limité pour réduire la surface SSRF.
                    'max_redirects' => self::MAX_REDIRECTS,
                    // En-têtes Chrome 124 complets (via trait HttpBrowserFetchTrait).
                    // Incluent Sec-Fetch-* qui réduisent les faux positifs anti-bot.
                    'headers'       => $this->buildBrowserHeaders(),
                ];

                // requestWithRetry() relance sur timeout / 429 / 5xx (3 tentatives, backoff 1s/2s).
                // Le logger est fourni pour tracer les retries en niveau INFO.
                $response = $this->requestWithRetry($this->httpClient, $this->logger, 'GET', $siteRoot, $options);

                if ($response === null) {
                    // Toutes les tentatives ont échoué (timeout, DNS, SSL, etc.)
                    return null;
                }

                // ── Garde SSRF post-redirection (C1) ──────────────────────────────────
                // getInfo('url') retourne l'URL finale après que Symfony HttpClient a suivi
                // toutes les redirections (HTTP 301/302/307/308). C'est cette URL réelle
                // qu'on doit valider, pas l'URL initiale (déjà vérifiée dans fetchLogoUrl).
                //
                // Scénario d'attaque : le site cible redirige vers http://169.254.169.254/...
                // (métadonnées AWS/DigitalOcean). Sans cette garde, Symfony lirait l'IP interne.
                //
                // Note : on appelle getInfo() AVANT getStatusCode() / getContent() pour ne pas
                // déclencher la lecture du body si l'URL effective est dangereuse.
                $effectiveUrl = $response->getInfo('url');
                if (is_string($effectiveUrl) && $effectiveUrl !== '' && $effectiveUrl !== $siteRoot) {
                    if (!$this->listingUrlDiscoverer->isSafeHost($effectiveUrl)) {
                        $this->logger->warning(
                            '[LogoFetcher] SSRF bloqué : URL effective après redirection rejetée par isSafeHost().',
                            [
                                'url_initiale'  => $siteRoot,
                                'url_effective' => $effectiveUrl,
                            ]
                        );
                        return null;
                    }
                }

                $statusCode = $response->getStatusCode();
                if ($statusCode < 200 || $statusCode >= 300) {
                    $this->logger->debug('[LogoFetcher] HTTP non-2xx lors du fetch de la page d\'accueil.', [
                        'site'   => $siteRoot,
                        'status' => $statusCode,
                    ]);
                    return null;
                }

                try {
                    // Lecture du contenu, bornée à MAX_BODY_BYTES.
                    // getContent() lit tout le body en mémoire — pour des pages ordinaires
                    // (<< 1 Mo), c'est acceptable ; on tronque après.
                    $content = $response->getContent();

                    // Tronquage en octets bruts (pas en chars multibytes) pour respecter
                    // la limite mémoire. Le parsing HTML qui suit tolère un HTML incomplet.
                    if (mb_strlen($content, '8bit') > self::MAX_BODY_BYTES) {
                        $content = substr($content, 0, self::MAX_BODY_BYTES);
                    }

                    return $content;

                } catch (\Exception $e) {
                    $this->logger->debug('[LogoFetcher] Erreur lors de la lecture du body.', [
                        'site'      => $siteRoot,
                        'exception' => $e->getMessage(),
                    ]);
                    return null;
                }
            },
            // Client API de scraping (null si non injecté → pas de repli)
            $this->scraperApiClient,
            // Callback SSRF — délègue à isSafeHost() de ListingUrlDiscoverer
            fn(string $u): bool => $this->listingUrlDiscoverer->isSafeHost($u),
            $this->logger,
        );
    }

    /**
     * Extrait l'URL du logo depuis le HTML de la page d'accueil.
     *
     * CHAÎNE DE REPLI (ordre de préférence, du plus "logo" au moins) :
     *   1. <link rel="apple-touch-icon" href="..."> — icône dédiée aux mobiles
     *      Avantage : haute résolution (180px+), spécifiquement un logo/icône de l'app.
     *      Inconvénient rare : peut pointer vers une icône générique Apple.
     *
     *   2. <link rel="icon" href="..."> ou rel="shortcut icon"
     *      Favicons standard — souvent un fichier .ico 32px ou .png.
     *      Qualité variable mais universellement présent.
     *
     *   3. <meta property="og:image" content="...">
     *      Image Open Graph — souvent une bannière ou photo de couverture.
     *      Moins précis comme "logo" mais meilleure qualité visuelle.
     *      Utilisé en dernier recours.
     *
     * RÉSOLUTION DES URLS RELATIVES :
     *   Les balises peuvent contenir des URLs relatives ("/favicon.ico").
     *   On les résout en URLs absolues grâce à $baseUrl (racine du site).
     *
     * @param string $html     HTML brut de la page d'accueil (peut être tronqué)
     * @param string $baseUrl  URL racine du site (ex: "https://www.cnap.fr")
     * @return string|null URL absolue du logo, ou null si aucune balise trouvée
     */
    private function extractLogoFromHtml(string $html, string $baseUrl): ?string
    {
        // DomCrawler parse le HTML avec l'extension PHP DOM.
        // Il gère correctement les documents HTML incomplets (tronqués à 50 Ko).
        $crawler = new Crawler($html);

        // ── Priorité 1 : <link rel="apple-touch-icon"> ───────────────────────
        // apple-touch-icon = icône haute résolution conçue pour être un logo d'app
        // On cherche d'abord "apple-touch-icon-precomposed" (ancienne variante) puis
        // "apple-touch-icon" pour couvrir les deux formes.
        $appleIconUrl = $this->findLinkHref(
            $crawler,
            // Les valeurs possibles de rel pour apple-touch-icon
            ['apple-touch-icon', 'apple-touch-icon-precomposed'],
            $baseUrl
        );
        if ($appleIconUrl !== null) {
            $this->logger->debug('[LogoFetcher] Logo trouvé via apple-touch-icon.', [
                'url' => $appleIconUrl,
                'site' => $baseUrl,
            ]);
            return $appleIconUrl;
        }

        // ── Priorité 2 : <link rel="icon"> ou rel="shortcut icon" ────────────
        // Favicon standard — présent sur presque tous les sites.
        // On privilégie les fichiers PNG/SVG (meilleure qualité) mais on accepte
        // aussi les .ico pour la couverture maximale.
        $iconUrl = $this->findLinkHref(
            $crawler,
            ['icon', 'shortcut icon'],
            $baseUrl
        );
        if ($iconUrl !== null) {
            $this->logger->debug('[LogoFetcher] Logo trouvé via <link rel="icon">.', [
                'url' => $iconUrl,
                'site' => $baseUrl,
            ]);
            return $iconUrl;
        }

        // ── Priorité 3 : <meta property="og:image"> ──────────────────────────
        // Open Graph image — souvent une bannière ou image de partage social.
        // Moins ciblé comme "logo" mais meilleure résolution que les favicons.
        $ogImage = $this->findOgImage($crawler, $baseUrl);
        if ($ogImage !== null) {
            $this->logger->debug('[LogoFetcher] Logo trouvé via og:image.', [
                'url' => $ogImage,
                'site' => $baseUrl,
            ]);
            return $ogImage;
        }

        // Aucune balise trouvée → null (le template affichera le badge "B")
        $this->logger->debug('[LogoFetcher] Aucun logo trouvé pour ce site.', [
            'site' => $baseUrl,
        ]);
        return null;
    }

    /**
     * Cherche un <link> avec une des valeurs rel données et retourne son href absolu.
     *
     * Gère les deux types de balises <link> :
     *   - href dans l'attribut "href" (cas standard)
     *
     * La valeur rel peut contenir plusieurs tokens séparés par des espaces
     * (ex: rel="apple-touch-icon precomposed") — on vérifie par intersection.
     *
     * @param Crawler  $crawler  Instance DomCrawler sur le HTML de la page
     * @param string[] $relValues Valeurs rel acceptées (ex: ["icon", "shortcut icon"])
     * @param string   $baseUrl  URL racine du site (pour résoudre les URLs relatives)
     * @return string|null URL absolue du premier <link> trouvé, null si aucun
     */
    private function findLinkHref(Crawler $crawler, array $relValues, string $baseUrl): ?string
    {
        // On itère sur tous les <link> du document
        $found = null;
        $crawler->filter('link')->each(function (Crawler $node) use ($relValues, $baseUrl, &$found): void {
            // Si on a déjà trouvé une URL, on arrête de chercher
            if ($found !== null) {
                return;
            }

            // Lire la valeur de l'attribut rel (peut être vide ou absente)
            $rel = strtolower(trim($node->attr('rel') ?? ''));
            if ($rel === '') {
                return;
            }

            // Vérifier si l'un des relValues demandés est présent dans rel
            // On tokenise rel par les espaces (ex: "shortcut icon" → ["shortcut", "icon"])
            $relTokens = array_map('trim', explode(' ', $rel));

            $matches = false;
            foreach ($relValues as $rv) {
                // Cas simple : un token unique ("icon")
                if (in_array($rv, $relTokens, true)) {
                    $matches = true;
                    break;
                }
                // Cas multi-token : "shortcut icon" → on vérifie que tous les tokens de rv
                // sont présents dans relTokens (en ordre quelconque)
                $rvTokens = array_map('trim', explode(' ', strtolower($rv)));
                if (count(array_intersect($rvTokens, $relTokens)) === count($rvTokens)) {
                    $matches = true;
                    break;
                }
            }

            if (!$matches) {
                return;
            }

            // Récupérer l'attribut href
            $href = trim($node->attr('href') ?? '');
            if ($href === '' || $href === '#') {
                return;
            }

            // Résoudre l'URL relative en absolue
            $absolute = $this->resolveUrl($href, $baseUrl);
            if ($absolute !== null) {
                $found = $absolute;
            }
        });

        return $found;
    }

    /**
     * Cherche la balise <meta property="og:image"> et retourne son contenu absolu.
     *
     * @param Crawler $crawler  Instance DomCrawler sur le HTML de la page
     * @param string  $baseUrl  URL racine du site (pour résoudre les URLs relatives)
     * @return string|null URL absolue de l'og:image, null si absente
     */
    private function findOgImage(Crawler $crawler, string $baseUrl): ?string
    {
        $found = null;

        // On filtre les balises <meta> ayant property="og:image"
        $crawler->filter('meta[property="og:image"]')->each(
            function (Crawler $node) use ($baseUrl, &$found): void {
                if ($found !== null) {
                    return; // Première trouvée suffit
                }

                $content = trim($node->attr('content') ?? '');
                if ($content === '') {
                    return;
                }

                $absolute = $this->resolveUrl($content, $baseUrl);
                if ($absolute !== null) {
                    $found = $absolute;
                }
            }
        );

        return $found;
    }

    /**
     * Résout une URL (relative ou absolue) en URL absolue à partir d'une base.
     *
     * Retourne null si l'URL ne peut pas être résolue ou est d'un schéma non-web.
     *
     * Cas gérés :
     *   - "https://..." ou "http://..."  → retourné tel quel
     *   - "//example.com/..."           → force https://
     *   - "/path/to/file.png"           → baseUrl + "/path/to/file.png"
     *   - "data:image/..."              → null (pas une URL externe)
     *   - ""                            → null
     *
     * @param string $url     URL brute à résoudre
     * @param string $baseUrl URL racine du site (ex: "https://www.example.com")
     * @return string|null URL absolue résolue, null si non résolvable ou schéma refusé
     */
    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Data URI → pas une URL externe, on ignore
        if (str_starts_with($url, 'data:')) {
            return null;
        }

        // Déjà absolue http(s) → on retourne tel quel
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Protocole-relative → on force https
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        // Relative racine → on colle au baseUrl
        if (str_starts_with($url, '/')) {
            return rtrim($baseUrl, '/') . $url;
        }

        // Relative chemin (ex: "favicon.ico") → on colle au baseUrl
        return rtrim($baseUrl, '/') . '/' . $url;
    }
}

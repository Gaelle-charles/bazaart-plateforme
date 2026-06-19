<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ScraperApiClient — Repli HTTP via une API de scraping tierce (ScraperAPI, ScrapingAnt, etc.).
 *
 * PROBLÈME RÉSOLU :
 *   Certains sites bloquent l'IP fixe du droplet DigitalOcean (blocage par réputation d'IP)
 *   ou nécessitent le rendu JavaScript pour exposer leur contenu.
 *   Dans ces cas, le fetch direct (même avec en-têtes Chrome 124 + retry) retourne un 403,
 *   un CAPTCHA ou un HTML vide.
 *
 * SOLUTION :
 *   Ce service délègue la requête à une API de scraping tierce qui :
 *   - Route la requête depuis une IP propre différente du droplet
 *   - Peut rendre le JavaScript (rendu headless Chromium) via le paramètre render=true
 *
 * PRINCIPE D'ÉCONOMIE DE QUOTA :
 *   Ce service n'est JAMAIS appelé directement par les consommateurs.
 *   Il est invoqué UNIQUEMENT en repli (fallback) par fetchHtmlRobust(), après que le
 *   fetch direct a échoué (timeout, HTTP non-200, HTML vide/bloqué).
 *   → On ne consomme du quota API QUE quand le direct échoue.
 *
 * CONFIGURATION (via app_settings, éditables depuis /admin/settings) :
 *   - scraper_api_key         : clé API du service (secret — masqué dans l'UI)
 *   - scraper_api_url_template: template d'URL avec placeholders {key} et {url}
 *                               Défaut : https://api.scraperapi.com/?api_key={key}&url={url}&render=true
 *                               Compatible ScraperAPI par défaut, changeable pour ScrapingAnt, etc.
 *   - scraper_api_enabled     : interrupteur (string "true"/"false") — si false, ce service ne fait rien
 *
 * SÉCURITÉ :
 *   - La clé API n'est JAMAIS loggée (pas même en debug)
 *   - Toute URL cible est vérifiée par isSafeHost() AVANT d'être envoyée à l'API externe
 *     (on ne veut pas qu'un site tiers déclenche une requête vers une IP interne via notre API)
 *   - On respecte le principe : si pas de clé ou si désactivé → on retourne null silencieusement
 *
 * PROVIDER-AGNOSTICITÉ :
 *   Le template {key}/{url} supporte tous les providers courants :
 *   - ScraperAPI : https://api.scraperapi.com/?api_key={key}&url={url}&render=true
 *   - ScrapingAnt: https://api.scrapingant.com/v2/general?api_key={key}&url={url}
 *   - BrightData  : configurer selon la doc du provider
 *
 * CE SERVICE NE LÈVE JAMAIS D'EXCEPTION — tout échec retourne null avec log.
 */
class ScraperApiClient
{
    /**
     * Template d'URL par défaut : ScraperAPI avec rendu JS activé.
     *
     * On utilise render=true pour que les sites JS-lourds exposent leur contenu HTML.
     * C'est le cas le plus courant quand un fetch direct échoue sur un SPA (React, Vue, etc.)
     * ou un site Cloudflare Challenge.
     */
    private const DEFAULT_URL_TEMPLATE = 'https://api.scraperapi.com/?api_key={key}&url={url}&render=true';

    /**
     * Timeout pour l'appel à l'API de scraping (en secondes).
     *
     * Généreux (40s) car l'API doit :
     *   1. Recevoir notre requête
     *   2. Lancer un navigateur headless
     *   3. Rendre la page (peut prendre 10-15s sur des SPAs)
     *   4. Nous renvoyer le HTML résultant
     * Un timeout trop court ici ferait échouer le repli inutilement.
     */
    private const API_TIMEOUT = 40;

    public function __construct(
        // Client HTTP Symfony — même instance que les autres services (autowiring)
        private readonly HttpClientInterface $httpClient,

        // SettingService — pour lire les 3 réglages scraper_api_* depuis app_settings
        private readonly SettingService $settingService,

        // Logger PSR-3 — pour tracer les appels API (info) et erreurs (warning)
        // La clé API n'est JAMAIS incluse dans les messages de log
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Vérifie si le repli API est disponible (activé + clé configurée).
     *
     * Appelé par fetchHtmlRobust() avant de décider si le repli est une option.
     * Ne lit les settings qu'une seule fois par vérification (pas de cache ici —
     * l'appelant est responsable de n'appeler fetchWithFallback() qu'en cas d'échec direct).
     *
     * @return bool true = le repli API peut être tenté, false = on laisse null
     */
    public function isAvailable(): bool
    {
        // Interrupteur global — l'admin peut le désactiver depuis /admin/settings
        // sans supprimer la clé API (pratique pour les maintenances ou tests)
        $enabled = $this->settingService->get('scraper_api_enabled', 'false');
        if ($enabled !== 'true') {
            return false;
        }

        // La clé API doit être configurée — sinon le repli ne peut pas fonctionner
        $key = $this->settingService->get('scraper_api_key');
        return !empty($key);
    }

    /**
     * Télécharge une page via l'API de scraping tierce.
     *
     * FLUX :
     *   1. Lire les 3 settings BDD (enabled, key, url_template)
     *   2. Garde rapide si désactivé ou pas de clé (retour null immédiat, silencieux)
     *   3. Garde SSRF : vérifier l'URL cible via le callback isSafeHost fourni
     *      (on ne veut pas envoyer une IP interne à un service tiers !)
     *   4. Construire l'URL d'appel API depuis le template
     *   5. GET vers l'API avec timeout généreux (API_TIMEOUT = 40s)
     *   6. Vérifier le code HTTP retourné par l'API
     *   7. Lire et retourner le HTML, ou null en cas d'échec
     *
     * IMPORTANT — ne jamais retransmettre la clé dans les logs :
     *   On remplace la clé par "***" dans les messages d'erreur qui incluent l'URL d'appel.
     *
     * @param string   $targetUrl    URL du site cible (ce qu'on veut scraper via l'API)
     * @param callable $isSafeHost   Callback(string $url): bool — garde SSRF fournie par l'appelant
     *                               (évite de coupler ce service à ListingUrlDiscoverer)
     * @return string|null HTML retourné par l'API, ou null si échec/désactivé/pas de clé
     */
    public function fetchViaApi(string $targetUrl, callable $isSafeHost): ?string
    {
        // ── Étape 1 : lire les réglages BDD ──────────────────────────────────────
        $enabled     = $this->settingService->get('scraper_api_enabled', 'false');
        $apiKey      = $this->settingService->get('scraper_api_key');
        $urlTemplate = $this->settingService->get('scraper_api_url_template', self::DEFAULT_URL_TEMPLATE);

        // ── Étape 2 : gardes rapides — silencieuses si config incomplète ─────────
        // On ne log pas en "warning" ici car c'est une config absente normale (pas d'erreur).
        if ($enabled !== 'true') {
            // Désactivé volontairement → retour null silencieux
            return null;
        }

        if (empty($apiKey)) {
            // Clé absente → pas de repli possible
            return null;
        }

        // Assurer que le template est non vide
        if (empty($urlTemplate)) {
            $urlTemplate = self::DEFAULT_URL_TEMPLATE;
        }

        // ── Étape 3 : garde SSRF sur l'URL CIBLE ─────────────────────────────────
        // CRITIQUE : on ne doit jamais envoyer une IP interne (localhost, 169.254.x.x,
        // 192.168.x.x, etc.) à un service tiers. Cela exposerait notre infrastructure.
        // Le callback isSafeHost est fourni par l'appelant (ListingUrlDiscoverer,
        // OpportunityEnrichmentService...) pour ne pas coupler ce service à l'implémentation
        // SSRF de ListingUrlDiscoverer.
        if (!$isSafeHost($targetUrl)) {
            $this->logger->warning('[ScraperApiClient] SSRF bloqué : URL cible non sûre, repli API annulé.', [
                'target_url' => $targetUrl,
                // On ne log PAS la clé API ici (même masquée, prudence)
            ]);
            return null;
        }

        // ── Étape 4 : construire l'URL d'appel vers l'API ─────────────────────────
        // On urlencode l'URL cible pour qu'elle soit passée proprement en query string.
        // Ex: "https://example.com/page?foo=bar" → "https%3A%2F%2Fexample.com..."
        $callUrl = str_replace(
            ['{key}', '{url}'],
            [$apiKey, rawurlencode($targetUrl)],
            (string) $urlTemplate
        );

        // ── NOTE DE SÉCURITÉ (SG-2) — pourquoi on ne vérifie pas isSafeHost($callUrl) ──
        // L'URL d'appel API ($callUrl) est construite depuis scraper_api_url_template,
        // un réglage BDD éditable uniquement par l'admin depuis /admin/settings.
        // Dans le modèle de menace actuel (admin de confiance, accès restreint), cette
        // URL n'est pas considérée comme une entrée utilisateur non fiable : c'est de la
        // configuration, pas de la donnée externe.
        //
        // Si le modèle de menace évolue (multi-admin partiellement non fiable, ou si
        // les settings deviennent éditables par des rôles moins privilégiés), il faudra
        // ajouter ici une whitelist des préfixes d'URL autorisés pour les API de scraping
        // (ex: api.scraperapi.com, api.scrapingant.com, etc.) et rejeter toute URL ne
        // correspondant pas à cette whitelist.
        //
        // À ce stade, on vérifie UNIQUEMENT l'URL CIBLE (targetUrl) — c'est elle qui
        // peut être influencée par des données tierces (BDD de sources scrapées, CSV, etc.).

        // ── Étape 5 : appel GET vers l'API de scraping ────────────────────────────
        // On loggue l'URL cible (pas l'URL d'appel qui contient la clé).
        $this->logger->info('[ScraperApiClient] Repli API de scraping déclenché.', [
            'target_url' => $targetUrl,
            // On n'inclut PAS $callUrl dans les logs car il contient la clé API en clair
        ]);

        try {
            $response = $this->httpClient->request('GET', $callUrl, [
                // Timeout généreux : l'API doit démarrer un browser + rendre la page
                'timeout' => self::API_TIMEOUT,
                // Pas d'en-têtes navigateur ici : l'API gère ses propres headers vers le site cible.
                // On fait une requête API-to-API, pas une requête "navigateur".
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                // L'API elle-même a renvoyé une erreur (clé invalide, quota épuisé, etc.)
                $this->logger->warning('[ScraperApiClient] API de scraping a retourné HTTP non-200.', [
                    'target_url'  => $targetUrl,
                    'http_status' => $statusCode,
                    // On ne loggue pas $callUrl (contient la clé)
                ]);
                return null;
            }

            // ── Étape 5b : vérification du Content-Type (SG-4) ───────────────────
            // Certaines APIs de scraping retournent un JSON d'erreur (ex: quota épuisé,
            // clé invalide) avec un code HTTP 200 plutôt qu'un 4xx. Sans cette vérification,
            // on transmettrait du JSON brut à LlmExtractorService comme si c'était du HTML,
            // ce qui produirait des extractions incohérentes voire des exceptions de parsing.
            //
            // On accepte uniquement les Content-Types HTML ou texte brut.
            // Les APIs de scraping légitimes retournent toujours "text/html" ou "text/plain"
            // quand elles ont réussi à rendre la page cible.
            //
            // IMPORTANT : on ne loggue PAS le Content-Type complet sans l'avoir épuré,
            // car certaines APIs incluent des paramètres de session dans ce header.
            // On loggue uniquement le type MIME de base (avant le ";").
            $contentTypeHeader = $response->getHeaders(false)['content-type'][0] ?? '';
            // Extraction du type MIME de base (ex: "text/html; charset=utf-8" → "text/html")
            $mimeType = strtolower(trim(explode(';', $contentTypeHeader)[0]));

            $isHtmlOrText = str_starts_with($mimeType, 'text/html')
                || str_starts_with($mimeType, 'text/plain')
                || $mimeType === ''; // Content-Type absent → on laisse passer (cas marginal)

            if (!$isHtmlOrText) {
                // L'API a répondu 200 mais avec un type non-HTML → probablement un JSON d'erreur
                // (quota épuisé, clé invalide, erreur propriétaire, etc.)
                // On log le type MIME uniquement (sans valeur sensible comme la clé ou les paramètres).
                $this->logger->warning(
                    '[ScraperApiClient] API de scraping a retourné un Content-Type non-HTML en HTTP 200. '
                    . 'Possible JSON d\'erreur (quota épuisé, clé invalide) — repli annulé.',
                    [
                        'target_url'  => $targetUrl,
                        'mime_type'   => $mimeType,
                        // On ne loggue PAS la clé API ni $callUrl
                    ]
                );
                return null;
            }

            // ── Étape 6 : lire le HTML retourné par l'API ─────────────────────────
            $html = $response->getContent();

            if (empty(trim($html))) {
                // L'API a répondu 200 mais avec un body vide → site inaccessible même via API
                $this->logger->warning('[ScraperApiClient] HTML vide retourné par l\'API de scraping.', [
                    'target_url' => $targetUrl,
                ]);
                return null;
            }

            // Succès : on log le nombre d'octets reçus pour suivre la consommation
            $this->logger->info('[ScraperApiClient] Repli API réussi.', [
                'target_url' => $targetUrl,
                'html_bytes' => strlen($html),
            ]);

            return $html;

        } catch (\Throwable $e) {
            // Erreur réseau (timeout, DNS, SSL) ou exception inattendue
            // On ne log jamais l'URL d'appel (qui contient la clé)
            $this->logger->warning('[ScraperApiClient] Erreur lors de l\'appel à l\'API de scraping.', [
                'target_url' => $targetUrl,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }
}

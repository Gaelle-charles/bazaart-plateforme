<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * FeedDetectorService — Détecte automatiquement si une URL est un flux RSS/Atom ou une page HTML.
 *
 * Ce service est utilisé par l'interface admin de gestion des sources de scraping
 * (/admin/scraping-sources) pour aider l'utilisatrice à choisir le bon type de source
 * sans avoir à inspecter manuellement le contenu de l'URL.
 *
 * ── ALGORITHME DE DÉTECTION ───────────────────────────────────────────────────
 *
 *  Étape 1 : Tester l'URL telle quelle
 *    - Faire un GET HTTP (timeout 8s, User-Agent Chrome pour éviter les blocages)
 *    - Si le Content-Type contient "rss", "atom" ou "xml" → flux RSS trouvé directement
 *    - Sinon inspecter les 2 000 premiers caractères du body :
 *        si <rss ou <feed est présent → flux RSS trouvé directement
 *    - Si HTTP 200 sans marqueur RSS → noter que l'URL principale répond (HTML probable)
 *
 *  Étape 2 : Si pas de RSS sur l'URL principale, tester les suffixes courants
 *    - Construire une URL base (schéma + hôte) et y adjoindre les suffixes RSS classiques
 *    - Tester dans l'ordre : /feed/, /rss.xml, /feed.xml, /rss/, /atom.xml, /feed.atom
 *    - Dès qu'un flux RSS est détecté sur l'une de ces URL → retourner RSS + l'URL du flux
 *    - Si aucun suffixe ne répond en RSS → retourner html_llm (comportement sûr par défaut)
 *
 * ── RÈGLES IMPORTANTES ────────────────────────────────────────────────────────
 *  - Aucune exception ne doit remonter : tout try/catch retourne html_llm par défaut
 *  - Timeout strict de 8 secondes par tentative (évite de bloquer l'interface admin)
 *  - Maximum 6 variantes d'URL RSS testées (prévient les timeouts en cascade)
 *  - PHPStan niveau 6 : pas de mixed non typé
 *
 * ── FORMAT DE RETOUR ──────────────────────────────────────────────────────────
 * La méthode detect() retourne toujours un tableau associatif :
 *   [
 *     'type'     => 'rss' | 'html_llm',        // type détecté pour ScrapingSourceType
 *     'feed_url' => 'https://...' | null,       // URL du flux si trouvé sur une variante
 *     'message'  => string,                     // Message lisible pour l'interface admin
 *   ]
 */
class FeedDetectorService
{
    /**
     * Timeout par requête HTTP, en secondes.
     *
     * 8s est volontairement plus court que FeedReaderService (15s) car ici on enchaîne
     * potentiellement plusieurs requêtes (URL principale + jusqu'à 6 variantes RSS).
     * Un timeout trop long bloquerait l'interface admin de façon désagréable.
     */
    private const FETCH_TIMEOUT = 8;

    /**
     * Nombre de caractères du body analysés pour détecter les marqueurs RSS.
     *
     * 2 000 caractères suffisent largement pour trouver <rss ou <feed
     * qui apparaissent toujours dans les premières lignes d'un flux valide.
     * Évite de charger le body entier (certains flux font plusieurs Mo).
     */
    private const BODY_INSPECT_LENGTH = 2000;

    /**
     * Suffixes d'URL RSS/Atom à tester si l'URL principale ne répond pas en flux.
     *
     * Ces chemins sont les plus courants sur les CMS WordPress, Drupal, Ghost,
     * Dotclear et autres plateformes utilisées par les organismes culturels français.
     * On les teste dans l'ordre du plus répandu au moins répandu.
     *
     * @var string[]
     */
    private const RSS_SUFFIXES = [
        '/feed/',
        '/rss.xml',
        '/feed.xml',
        '/rss/',
        '/atom.xml',
        '/feed.atom',
    ];

    /**
     * User-Agent Chrome utilisé pour les requêtes de détection.
     *
     * Certains sites culturels bloquent les bots génériques mais acceptent Chrome.
     * On utilise Chrome pour maximiser les chances de recevoir une vraie réponse.
     * Note : pour le vrai pipeline de scraping, BazaartBot est utilisé à la place.
     */
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(
        // Client HTTP Symfony — injecté par autowiring (symfony/http-client requis)
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Détecte si une URL est un flux RSS/Atom ou une page HTML.
     *
     * @param string $url L'URL à analyser (doit commencer par http:// ou https://)
     *
     * @return array{type: string, feed_url: string|null, message: string}
     *   - type     : 'rss' ou 'html_llm'
     *   - feed_url : URL du flux RSS si trouvé sur une variante (null si détecté sur l'URL principale ou si html_llm)
     *   - message  : message lisible pour l'interface admin
     */
    public function detect(string $url): array
    {
        // ── Défense en profondeur : whitelist schémas (cohérent avec le controller) ─
        // Le controller valide déjà le schéma avant d'appeler ce service.
        // Cette vérification ici protège les appels directs au service (commandes,
        // tests, usage futur) qui passeraient en dehors du controller.
        // On bloque file://, ftp://, data:// etc. qui pourraient provoquer un SSRF.
        $parsed = parse_url($url);
        if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return [
                'type'     => 'html_llm',
                'feed_url' => null,
                'message'  => 'URL non supportée (seuls http:// et https:// sont autorisés).',
            ];
        }

        // ── Étape 1 : tester l'URL principale ─────────────────────────────────
        // On commence par tester l'URL telle que soumise par l'admin.
        // Si c'est déjà un flux RSS, c'est le chemin le plus rapide.
        $mainResult = $this->fetchAndInspect($url);

        if ($mainResult['is_rss'] === true) {
            // Flux RSS détecté directement sur l'URL soumise.
            // feed_url est null ici : l'URL principale IS le flux, pas besoin d'URL alternative.
            return [
                'type'     => 'rss',
                'feed_url' => null,
                'message'  => 'Flux RSS/Atom détecté directement sur l\'URL fournie.',
            ];
        }

        // ── Étape 2 : tester les variantes RSS ────────────────────────────────
        // L'URL principale n'est pas un flux RSS. On va tester les suffixes classiques
        // construits à partir de la base (schéma + hôte) de l'URL.
        // Exemple : "https://exemple.fr/actualites/" → base = "https://exemple.fr"
        $baseUrl = $this->extractBaseUrl($url);

        if ($baseUrl !== null) {
            // On teste jusqu'à 6 variantes dans l'ordre défini dans RSS_SUFFIXES.
            foreach (self::RSS_SUFFIXES as $suffix) {
                $variantUrl    = $baseUrl . $suffix;
                $variantResult = $this->fetchAndInspect($variantUrl);

                if ($variantResult['is_rss'] === true) {
                    // Flux RSS trouvé sur cette variante.
                    // On retourne l'URL du flux pour que l'admin puisse la copier
                    // dans le champ "URL du flux RSS" du formulaire.
                    return [
                        'type'     => 'rss',
                        'feed_url' => $variantUrl,
                        'message'  => sprintf(
                            'Flux RSS/Atom détecté sur %s — vous pouvez utiliser cette URL comme "URL du flux".',
                            $variantUrl
                        ),
                    ];
                }
            }
        }

        // ── Étape 3 : aucun flux RSS trouvé → HTML_LLM par défaut ─────────────
        // On n'a trouvé ni flux RSS sur l'URL principale, ni sur les variantes.
        // Le type "html_llm" est le comportement sûr par défaut : le pipeline de
        // scraping utilisera le LLM pour extraire les informations de la page HTML.
        //
        // Note : $mainResult['responded'] indique si l'URL principale a au moins
        // répondu en HTTP 200. On l'utilise pour personnaliser le message.
        if ($mainResult['responded'] === true) {
            $message = 'Aucun flux RSS détecté — HTML → LLM recommandé (la page répond correctement en HTTP).';
        } else {
            // L'URL ne répond pas du tout — on reste sur html_llm par défaut
            // mais on informe l'admin que l'URL semble inaccessible.
            $message = 'L\'URL ne répond pas ou retourne une erreur. HTML → LLM sélectionné par défaut — vérifiez l\'URL.';
        }

        return [
            'type'     => 'html_llm',
            'feed_url' => null,
            'message'  => $message,
        ];
    }

    /**
     * Extrait la base URL (schéma + hôte) d'une URL donnée.
     *
     * Exemples :
     *   "https://cnm.fr/actualites/open-calls/" → "https://cnm.fr"
     *   "http://site.org/blog/?p=1"             → "http://site.org"
     *
     * @param string $url URL complète
     *
     * @return string|null Base URL, ou null si l'URL est malformée
     */
    private function extractBaseUrl(string $url): ?string
    {
        // parse_url() décompose l'URL en ses composantes.
        // On ne garde que le schéma (http/https) et l'hôte (domaine).
        $parsed = parse_url($url);

        // parse_url() peut retourner false (URL complètement malformée) ou
        // un tableau partiel sans les clés 'scheme' et 'host' (ex: URL relative).
        // On vérifie les deux cas de façon compatible PHPStan niveau 6.
        if (!is_array($parsed)) {
            // URL complètement malformée (ex: chaîne vide, séquences invalides)
            return null;
        }

        $scheme = isset($parsed['scheme']) ? (string) $parsed['scheme'] : '';
        $host   = isset($parsed['host']) ? (string) $parsed['host'] : '';

        if ($scheme === '' || $host === '') {
            // URL relative ou incomplète — impossible d'extraire la base absolue
            return null;
        }

        // Le port doit être inclus s'il est non-standard (ex: :8080).
        // Sans lui, les variantes /feed/ seraient testées sur le port par défaut (80/443)
        // et manqueraient la cible sur les serveurs non-standard.
        // parse_url() retourne null si le port est absent → on produit alors une chaîne vide.
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        // Reconstruction : "https" + "://" + "exemple.fr" + ":8080" (si non-standard)
        return $scheme . '://' . $host . $port;
    }

    /**
     * Télécharge une URL et inspecte la réponse pour détecter un flux RSS/Atom.
     *
     * Cette méthode est privée et appelée plusieurs fois par detect() :
     * une fois pour l'URL principale, puis pour chaque variante RSS.
     *
     * ── CE QU'ELLE RETOURNE ────────────────────────────────────────────────────
     *   is_rss    : true si un marqueur RSS/Atom a été trouvé (Content-Type ou body)
     *   responded : true si l'URL a répondu HTTP 200 (même sans être un flux RSS)
     *
     * ── STRATÉGIE DE DÉTECTION ────────────────────────────────────────────────
     *  1. Vérifier le Content-Type de la réponse :
     *     - "application/rss+xml", "application/atom+xml", "text/xml", "application/xml"
     *       → toute valeur contenant "rss", "atom" ou "xml" est considérée comme RSS
     *  2. Si le Content-Type ne suffit pas (beaucoup de sites servent les flux
     *     en "text/html" ou "text/plain"), inspecter les premiers caractères du body :
     *     - <rss  → balise ouvrante RSS 2.0
     *     - <feed → balise ouvrante Atom 1.0 (RFC 4287)
     *
     * ── GESTION DES ERREURS ──────────────────────────────────────────────────
     * Toute exception réseau → retourne is_rss = false, responded = false.
     * On ne remonte jamais d'exception : cette méthode doit rester silencieuse.
     *
     * @param string $url URL à tester
     *
     * @return array{is_rss: bool, responded: bool}
     */
    private function fetchAndInspect(string $url): array
    {
        // Résultat par défaut : pas de flux RSS, pas de réponse
        $defaultResult = ['is_rss' => false, 'responded' => false];

        try {
            // ── Requête HTTP ─────────────────────────────────────────────────
            // On utilise verify_peer = false pour ne pas bloquer sur les sites avec
            // des certificats SSL auto-signés ou mal configurés (fréquent sur les
            // petites structures culturelles). La sécurité n'est pas un enjeu ici
            // car on lit uniquement des métadonnées publiques.
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    // Accept très large pour ne pas orienter la réponse du serveur
                    'Accept'     => 'application/rss+xml, application/atom+xml, application/xml, text/xml, text/html, */*',
                ],
                'timeout'     => self::FETCH_TIMEOUT,
                'verify_peer' => false,
                'verify_host' => false,
                // buffer : false → on ne charge pas tout le body en mémoire avant d'analyser
                // Symfony HttpClient streame la réponse, ce qui est plus économique.
                // Mais on a besoin d'une partie du body → on lit getContent() en dessous.
                // Ce n'est pas un vrai streaming ici, mais l'option évite le téléchargement complet
                // dans certains contextes. On lit ensuite seulement les N premiers chars.
            ]);

            // ── Vérification du code HTTP ─────────────────────────────────────
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                // Toute réponse non-200 (301, 404, 500...) n'est pas un flux valide
                return $defaultResult;
            }

            // ── Inspection du Content-Type ─────────────────────────────────────
            // getHeaders() retourne tous les headers en tableau — on cherche content-type.
            // Les headers HTTP sont normalisés en minuscules par Symfony HttpClient.
            // getHeaders() retourne array<string, string[]> selon le contrat Symfony HttpClient.
            // Les headers sont normalisés en minuscules. 'content-type' peut ne pas être présent.
            $headers     = $response->getHeaders(false); // false = ne pas lever d'exception sur 4xx/5xx
            $contentType = '';

            if (isset($headers['content-type'])) {
                // Plusieurs valeurs possibles pour un même header (rare mais légal en HTTP).
                // getHeaders() garantit que chaque valeur est string[] — on concatène.
                $contentType = implode(' ', $headers['content-type']);
            }

            // Un Content-Type contenant "rss", "atom" ou "xml" indique un flux
            // (application/rss+xml, application/atom+xml, text/xml, application/xml...)
            if (
                str_contains($contentType, 'rss')
                || str_contains($contentType, 'atom')
                || str_contains($contentType, 'xml')
            ) {
                return ['is_rss' => true, 'responded' => true];
            }

            // ── Inspection du body (fallback Content-Type insuffisant) ─────────
            // Beaucoup de serveurs servent les flux RSS avec Content-Type: text/html.
            // On télécharge une portion du body pour chercher les balises XML RSS/Atom.
            //
            // getContent() déclenche le téléchargement et retourne le body complet.
            // On ne prend que les BODY_INSPECT_LENGTH premiers caractères pour éviter
            // de charger un article HTML volumineux entier en mémoire.
            $bodyChunk = mb_substr($response->getContent(), 0, self::BODY_INSPECT_LENGTH);

            // Marqueurs RSS/Atom en début de document (insensible à la casse)
            // On utilise mb_strtolower() pour une comparaison robuste.
            // '<rss'  → balise ouvrante RSS 2.0
            // '<feed' → balise ouvrante Atom 1.0 (RFC 4287)
            // Note : '<channel>' retiré — il n'apparaît jamais sans '<rss>' dans un flux
            // RSS 2.0 valide, donc il est redondant. De plus, utilisé seul il générait
            // des faux positifs sur des pages HTML contenant ce terme (PHPStan l'avait détecté
            // comme tautologie logique dans la combinaison avec '&&').
            $bodyLower = mb_strtolower($bodyChunk);
            if (
                str_contains($bodyLower, '<rss')
                || str_contains($bodyLower, '<feed')
            ) {
                return ['is_rss' => true, 'responded' => true];
            }

            // L'URL répond en HTTP 200 mais ce n'est pas un flux RSS → HTML probable
            return ['is_rss' => false, 'responded' => true];

        } catch (\Throwable) {
            // Exception réseau (timeout, DNS, connexion refusée...) → silencieux
            // On retourne le résultat par défaut : pas de flux, pas de réponse.
            // \Throwable capture aussi les erreurs PHP (Error), pas seulement les Exceptions.
            return $defaultResult;
        }
    }
}

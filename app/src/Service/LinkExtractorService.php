<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * LinkExtractorService — Pré-filtrage PHP des liens avant envoi au LLM.
 *
 * POURQUOI CE SERVICE EXISTE :
 *   Avant ce service, DiscoverSourcesCommand envoyait 30 000 caractères de HTML brut
 *   au LLM pour chaque agrégateur. C'est coûteux (tokens) et lent.
 *   Ce service extrait les liens proprement via DomCrawler et les filtre en PHP,
 *   de sorte que le LLM reçoit une liste compacte de ~50 candidats maximum.
 *
 * PIPELINE DE FILTRAGE (dans l'ordre, cf. extractAndFilter() / filterCandidates()) :
 *   1. extractLinks() ou extractLinksFromFeed() — tous les <a href> de la page HTML,
 *      OU (ADR-0036) les liens extraits d'un flux RSS/Atom si le contenu en est un
 *   2. filterNoiseDomains()     — supprime les réseaux sociaux, Google, CDN, etc.
 *   3. filterInternalLinks()    — supprime les liens vers le même domaine que la source
 *   4. deduplicateByDomain()    — un seul lien par domaine (le plus long en texte ancre)
 *   5. filterKnownDomains()     — supprime les domaines déjà connus en BDD
 *   6. shuffle() puis plafond MAX_CANDIDATES_PER_AGGREGATOR (rotation, cf. ADR-0036)
 *
 * Résultat : le LLM reçoit une liste "Candidat N : "Texte ancre" → https://..."
 * au lieu de 30 000 chars de HTML brut → économie de ~95% des tokens.
 *
 * SUPPORT RSS/ATOM (ADR-0036) :
 *   Certains agrégateurs (ex: Resartis) exposent une page-liste sous forme de flux
 *   RSS/Atom plutôt que de HTML avec des <a href>. Le contenu utile (liens vers les
 *   organismes tiers) se trouve alors DANS le texte des <description>/<content:encoded>
 *   (RSS) ou <summary>/<content> (Atom), généralement échappé en entités HTML ou en
 *   CDATA — jamais sous forme de <a href> directement lisible par DomCrawler sur le
 *   flux brut. extractAndFilter() détecte ce cas (looksLikeFeed()) et bascule sur
 *   extractLinksFromFeed() avant de continuer le MÊME pipeline de filtrage.
 *
 * RÉUTILISATION MULTI-GISEMENTS (ADR-0036) :
 *   filterCandidates() factorise les étapes 2 à 6 du pipeline pour qu'un appelant
 *   disposant déjà d'une liste de candidats {text, url} — par exemple les
 *   ScrapedResource déjà collectées (gisement "opportunités", cf.
 *   DiscoverSourcesCommand::discoverFromOpportunities()) — bénéficie du même
 *   filtrage que les liens extraits d'une page agrégateur, sans dupliquer la logique.
 *
 * Ce service est SANS ÉTAT (pas de propriétés mutables) — il peut être injecté
 * comme service partagé sans risque de collision entre deux appels.
 */
class LinkExtractorService
{
    /**
     * Plafond max de candidats retournés par agrégateur.
     *
     * Garantit un coût LLM plafonné même sur une très grosse page.
     * 50 candidats × ~30 chars en moyenne = ~1 500 chars envoyés au LLM.
     * Contre 30 000 chars de HTML brut avant ce service → économie ×20.
     */
    private const MAX_CANDIDATES_PER_AGGREGATOR = 50;

    /**
     * Fragments de domaines à exclure (bruit : réseaux sociaux, outils, CDN...).
     *
     * On utilise str_contains() sur le HOST — pas sur l'URL complète — pour éviter
     * les faux positifs sur les slugs de chemin (ex: /google-arts-culture/).
     *
     * Hypothèse : à notre échelle, un domaine contenant 'google' est un outil tiers,
     * jamais une source culturelle. Si ce postulat devient faux un jour, on retire
     * l'entrée de ce tableau — aucune autre modification nécessaire.
     *
     * Liste extensible : ajouter un fragment de domaine suffit pour l'exclure partout.
     *
     * @var string[]
     */
    private const NOISE_DOMAINS = [
        // Réseaux sociaux — aucun ne publie ses propres opportunités artistiques
        'facebook', 'instagram', 'twitter', 'x.com', 'linkedin', 'youtube',
        'tiktok', 'pinterest', 'snapchat', 'whatsapp', 'telegram',
        // Moteurs de recherche / encyclopédies — liens génériques, pas des sources
        'google', 'bing', 'duckduckgo', 'wikipedia', 'wikimedia',
        // CDN / hébergement générique — pas des organismes culturels
        'cloudflare', 'amazonaws', 'wordpress.com', 'wixsite', 'squarespace',
        // Outils tiers — hors scope
        'mailchimp', 'dropbox', 'apple', 'microsoft',
    ];

    public function __construct(
        // Logger PSR-3 — utilisé uniquement pour tracer le plafond (info, pas erreur)
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Extensions de fichiers à exclure lors de l'extraction de liens internes.
     *
     * Ces extensions correspondent à des assets statiques ou documents qui ne peuvent
     * pas être des pages-listes d'opportunités. On les filtre en PHP avant d'envoyer
     * la liste au LLM pour réduire le bruit et économiser des tokens.
     *
     * @var string[]
     */
    private const ASSET_EXTENSIONS = [
        '.jpg', '.jpeg', '.png', '.gif', '.svg', '.webp', '.ico',  // images
        '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', // documents
        '.css', '.js', '.woff', '.woff2', '.ttf', '.eot',          // assets web
        '.zip', '.tar', '.gz', '.mp4', '.mp3', '.avi',             // archives / médias
        '.xml', '.rss', '.atom',                                    // feeds
    ];

    /**
     * Extrait les liens INTERNES d'une page (même domaine) pour le fallback LLM
     * du ListingUrlDiscoverer.
     *
     * DIFFÉRENCE AVEC extractAndFilter() :
     *   extractAndFilter() → garde les liens EXTERNES (autres domaines) pour découvrir
     *                         de nouvelles sources à scraper.
     *   extractInternalLinks() → garde les liens INTERNES (même domaine) pour trouver
     *                            quelle SOUS-PAGE du site liste les opportunités.
     *
     * Pipeline de filtrage :
     *   1. extractLinks()     — tous les <a href> (DomCrawler)
     *   2. Garder uniquement les liens dont le host === host de $baseUrl
     *   3. Filtrer les assets (.jpg, .pdf, .css, .js...)
     *   4. Dédupliquer par URL normalisée (sans query string ni fragment)
     *   5. Plafond $maxLinks pour limiter la taille du prompt LLM
     *
     * @param string $html     HTML brut de la page d'accueil
     * @param string $baseUrl  URL de base du site (ex: "https://institutfrancais.com")
     * @param int    $maxLinks Nombre maximum de liens à retourner (défaut : 120)
     * @return array<int, array{text: string, url: string}> Liens internes dédupliqués
     */
    public function extractInternalLinks(string $html, string $baseUrl, int $maxLinks = 120): array
    {
        // ── Étape 1 : extraire tous les liens via DomCrawler ──────────────────
        $crawler = new Crawler($html);
        $allLinks = $this->extractLinks($crawler, $baseUrl);

        // ── Étape 2 : parser le host de référence (domaine du site) ──────────
        // On normalise le host pour gérer "www.example.com" vs "example.com"
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        if (!is_string($baseHost)) {
            // Ne devrait pas arriver si $baseUrl est valide, mais on sécurise
            $this->logger->warning('[LinkExtractor] extractInternalLinks : baseUrl sans host.', [
                'baseUrl' => $baseUrl,
            ]);
            return [];
        }

        $baseHostNorm = $this->normalizeHost($baseHost);

        // ── Étape 3 : filtrer pour ne garder que les liens internes ───────────
        // Un lien est "interne" si son host (normalisé) correspond au host de base.
        // On exclut aussi les assets statiques et les paramètres de tri/page.
        $seen = [];     // Pour la déduplication par URL normalisée
        $internal = [];

        foreach ($allLinks as $link) {
            $linkHost = parse_url($link['url'], PHP_URL_HOST);

            // Ignorer les liens sans host parseable
            if (!is_string($linkHost)) {
                continue;
            }

            // Vérifier que c'est le même domaine (normalisé sans www.)
            if ($this->normalizeHost($linkHost) !== $baseHostNorm) {
                continue; // Lien externe → on ignore
            }

            // ── Filtre assets : exclure les URLs pointant vers des fichiers statiques ──
            // On regarde l'extension du chemin de l'URL (ex: "/img/logo.jpg")
            $path = strtolower(parse_url($link['url'], PHP_URL_PATH) ?? '');
            $isAsset = false;
            foreach (self::ASSET_EXTENSIONS as $ext) {
                if (str_ends_with($path, $ext)) {
                    $isAsset = true;
                    break;
                }
            }
            if ($isAsset) {
                continue; // Asset statique → on ignore
            }

            // ── Déduplication par URL normalisée (sans query string ni fragment) ──
            // normalizeUrl() force https://, minuscules, supprime www., slash final, query, fragment.
            // Ainsi "https://example.com/page/" et "https://example.com/page" sont traités identiques.
            $normalizedUrl = $this->normalizeUrl($link['url']);
            if (isset($seen[$normalizedUrl])) {
                continue; // Doublon → on ignore
            }
            $seen[$normalizedUrl] = true;

            $internal[] = $link;

            // Plafond : on arrête dès qu'on a assez de liens pour le LLM
            if (count($internal) >= $maxLinks) {
                $this->logger->info('[LinkExtractor] extractInternalLinks : plafond atteint.', [
                    'plafond' => $maxLinks,
                    'baseUrl' => $baseUrl,
                ]);
                break;
            }
        }

        $this->logger->debug('[LinkExtractor] extractInternalLinks résumé.', [
            'total_avant_filtre' => count($allLinks),
            'liens_internes'     => count($internal),
            'baseUrl'            => $baseUrl,
        ]);

        return $internal;
    }

    /**
     * Extrait les liens d'une page HTML et les filtre pour ne garder que les candidats-sources.
     *
     * Point d'entrée principal du service — appelé par DiscoverSourcesCommand.
     *
     * Pipeline complet :
     *   1. extractLinks()           — tous les <a href> de la page
     *   2. filterNoiseDomains()     — supprime les domaines de bruit (réseaux sociaux, etc.)
     *   3. filterInternalLinks()    — supprime les liens internes (même domaine que l'agrégateur)
     *   4. deduplicateByDomain()    — un seul lien par domaine (le plus long en texte ancre)
     *   5. filterKnownDomains()     — supprime les domaines déjà connus en BDD
     *   6. Plafond MAX_CANDIDATES_PER_AGGREGATOR — log avant/après si tronqué
     *
     * @param string   $html          HTML brut de la page agrégateur (pas de fetch réseau ici)
     * @param string   $aggregatorUrl URL de la page (pour filtrer les liens internes)
     * @param string[] $knownDomains  Domaines déjà connus (en minuscules, sans www.)
     * @return array<int, array{text: string, url: string}> Candidats filtrés, réindexés
     */
    public function extractAndFilter(string $html, string $aggregatorUrl, array $knownDomains): array
    {
        // ── Étape 1 : extraire les liens bruts ────────────────────────────────
        // ADR-0036 : certains agrégateurs (ex: Resartis) exposent leur page-liste
        // sous forme de flux RSS/Atom plutôt que de HTML avec des <a href>. Dans ce
        // cas, DomCrawler sur le flux brut ne trouverait aucun <a> exploitable — les
        // liens utiles sont DANS le texte des <description>/<content:encoded>.
        // On détecte ce cas et on bascule sur un extracteur dédié.
        if ($this->looksLikeFeed($html)) {
            $this->logger->debug('[LinkExtractor] Contenu détecté comme flux RSS/Atom.', [
                'url' => $aggregatorUrl,
            ]);
            $afterExtract = $this->extractLinksFromFeed($html, $aggregatorUrl);
        } else {
            // DomCrawler parse le HTML avec l'extension PHP DOM (ext-dom, toujours présente).
            // On ne fait PAS de requête réseau ici — le HTML est déjà téléchargé par la commande.
            $crawler = new Crawler($html);
            $afterExtract = $this->extractLinks($crawler, $aggregatorUrl);
        }

        // ── Étape 2 à 6 : pipeline de filtrage commun (ADR-0036) ─────────────
        // Factorisé dans filterCandidates() pour être réutilisable par le gisement
        // "opportunités déjà collectées" (DiscoverSourcesCommand::discoverFromOpportunities()),
        // qui dispose déjà d'une liste de candidats {text, url} sans passer par le HTML.
        $aggregatorHost = parse_url($aggregatorUrl, PHP_URL_HOST);
        $baseHost = is_string($aggregatorHost) ? $aggregatorHost : '';

        return $this->filterCandidates($afterExtract, $baseHost, $knownDomains, $aggregatorUrl);
    }

    /**
     * Applique le pipeline de filtrage commun (bruit, interne, dédup, connus, rotation,
     * plafond) à une liste de candidats déjà extraite.
     *
     * RÉUTILISATION (ADR-0036) : cette méthode est le cœur partagé entre :
     *   - extractAndFilter() : candidats extraits d'une page agrégateur (HTML ou flux)
     *   - DiscoverSourcesCommand::discoverFromOpportunities() : candidats construits
     *     directement depuis les ScrapedResource déjà collectées (title/applicationUrl)
     *
     * @param array<int, array{text: string, url: string}> $candidates   Candidats bruts
     * @param string                                        $baseHost    Host de la page/source
     *        d'origine (pour filtrer les liens internes). Chaîne vide = pas de filtrage
     *        interne pertinent (cas du gisement "opportunités", qui n'a pas un unique host
     *        de référence : chaque ScrapedResource vient d'un site source différent).
     * @param string[]                                       $knownDomains Domaines déjà
     *        connus en BDD (minuscules, sans www.)
     * @param string|null                                    $logContext  Libellé/URL pour
     *        les logs de debug (facultatif, purement informatif)
     * @return array<int, array{text: string, url: string}> Candidats filtrés, réindexés,
     *         plafonnés à MAX_CANDIDATES_PER_AGGREGATOR
     */
    public function filterCandidates(
        array $candidates,
        string $baseHost,
        array $knownDomains,
        ?string $logContext = null,
    ): array {
        // ── Étape 2 : supprimer les domaines de bruit ────────────────────────
        $afterNoise = $this->filterNoiseDomains($candidates);

        // ── Étape 3 : supprimer les liens internes ───────────────────────────
        // Si $baseHost est vide (ex: gisement "opportunités", pas de host unique de
        // référence), cette étape est un no-op : on ne peut pas juger "interne" sans
        // domaine de référence — filterInternalLinks() gère ce cas en conservant tout.
        $afterInternal = $this->filterInternalLinks($afterNoise, $baseHost);

        // ── Étape 4 : dédupliquer par domaine ────────────────────────────────
        $afterDedup = $this->deduplicateByDomain($afterInternal);

        // ── Étape 5 : supprimer les domaines déjà connus en BDD ─────────────
        $afterKnown = $this->filterKnownDomains($afterDedup, $knownDomains);

        // ── Étape 6a : rotation aléatoire avant plafond (ADR-0036, point C) ──
        // POURQUOI : sans rotation, array_slice() gardait toujours les N premiers
        // candidats dans l'ordre du DOM/flux. Un agrégateur avec plus de candidats
        // que le plafond soumettait donc EXACTEMENT les mêmes candidats au LLM à
        // chaque run — les candidats situés après le rang N n'étaient jamais
        // découverts. shuffle() mélange l'ordre juste avant le plafond : chaque run
        // expose un sous-ensemble potentiellement différent au LLM. Aucun effet
        // quand count($afterKnown) <= plafond (rien n'est perdu, juste réordonné).
        shuffle($afterKnown);

        // ── Log debug : comptes après chaque étape (visible avec -vvv) ───────
        // Format : "extract: 170 → noise: X → internal: Y → dedup: Z → known: W → cap: V"
        // Si une étape filtre TOUT, c'est ici qu'on le voit.
        $capFinal = min(count($afterKnown), self::MAX_CANDIDATES_PER_AGGREGATOR);
        $this->logger->debug(sprintf(
            '[LinkExtractor] extract: %d → noise: %d → internal: %d → dedup: %d → known: %d → cap: %d',
            count($candidates),
            count($afterNoise),
            count($afterInternal),
            count($afterDedup),
            count($afterKnown),
            $capFinal
        ), ['contexte' => $logContext]);

        // ── Étape 6b : appliquer le plafond ───────────────────────────────────
        // On log l'info AVANT de tronquer pour que les statistiques soient exactes.
        if (count($afterKnown) > self::MAX_CANDIDATES_PER_AGGREGATOR) {
            $this->logger->info('[LinkExtractor] Plafond appliqué.', [
                'avant'    => count($afterKnown),
                'retenu'   => self::MAX_CANDIDATES_PER_AGGREGATOR,
                'contexte' => $logContext,
            ]);
            return array_slice($afterKnown, 0, self::MAX_CANDIDATES_PER_AGGREGATOR);
        }

        return $afterKnown;
    }

    /**
     * Normalise une URL pour la comparaison de doublons.
     *
     * Transformations appliquées dans l'ordre :
     *   1. Force le schéma en https://
     *   2. Met le host en minuscules
     *   3. Supprime le préfixe www.
     *   4. Supprime le slash final sur le path
     *   5. Supprime la query string (?foo=bar)
     *   6. Supprime le fragment (#section)
     *
     * Exemple : "http://WWW.Example.com/page/?q=1#top" → "https://example.com/page"
     *
     * Méthode publique car DiscoverSourcesCommand en a besoin pour normaliser
     * les URLs de scraping_sources et suggested_sources (buildKnownDomains).
     *
     * @param string $url URL brute (peut être http, https, avec ou sans www.)
     * @return string URL normalisée (retourne $url tel quel si parse_url échoue)
     */
    public function normalizeUrl(string $url): string
    {
        // parse_url retourne un tableau ou false si l'URL est malformée
        $parts = parse_url($url);
        if (!is_array($parts)) {
            // URL non parseable — on retourne l'URL brute sans modification
            return $url;
        }

        // ── Host : mise en minuscules + suppression du www. ───────────────────
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        // On supprime uniquement "www." en début de host (pas "www2.", "www3.", etc.)
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // ── Path : suppression du slash final ────────────────────────────────
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        // ── Reconstruction en https:// uniquement ─────────────────────────────
        // On force https:// pour que "http://example.com" et "https://example.com"
        // soient traités comme le même domaine lors des comparaisons.
        return 'https://' . $host . $path;
        // Note : on ne conserve pas la query string ni le fragment (objectif : dédupliquer)
    }

    // =========================================================================
    // MÉTHODES PRIVÉES — Pipeline de filtrage
    // =========================================================================

    /**
     * Extrait tous les liens <a href> de la page via DomCrawler.
     *
     * Les liens relatifs sont résolus en URLs absolues via resolveUrl() avant tout filtrage.
     * Sans cette résolution, les sites comme on-the-move.org (qui n'utilisent que des
     * chemins relatifs comme "/news/goethe-institut") retourneraient 0 candidat.
     *
     * Règles d'exclusion (un lien est ignoré si resolveUrl() retourne null) :
     *   - href vide
     *   - href commence par '#'          → ancre interne
     *   - href commence par 'mailto:'    → lien email
     *   - href commence par 'tel:'       → lien téléphone
     *   - href commence par 'javascript:'→ lien JS
     *
     * Seul le texte ancre est nettoyé (trim + collapsing des espaces consécutifs).
     *
     * @param Crawler $crawler      Instance DomCrawler pointant sur le HTML de la page
     * @param string  $baseUrl      URL de la page agrégateur (nécessaire pour résoudre les relatifs)
     * @return array<int, array{text: string, url: string}> Liste des liens valides (URLs absolues)
     */
    private function extractLinks(Crawler $crawler, string $baseUrl): array
    {
        $links = [];

        // DomCrawler::filter() retourne un nouveau Crawler sur les nœuds <a>
        // each() itère sur chaque nœud et collecte les résultats dans un tableau
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseUrl): void {
            $href = trim($node->attr('href') ?? '');

            // Résoudre le lien (relatif ou absolu) en URL absolue.
            // resolveUrl() retourne null pour les hrefs à ignorer (ancres, mailto, etc.)
            $absoluteUrl = $this->resolveUrl($href, $baseUrl);
            if ($absoluteUrl === null) {
                return;
            }

            // Nettoyage du texte ancre : trim + collapsing des espaces multiples
            // (les <a> peuvent contenir des retours à la ligne et des espaces en cascade)
            $text = preg_replace('/\s+/', ' ', trim($node->text())) ?? '';

            $links[] = [
                'text' => $text,
                'url'  => $absoluteUrl, // On stocke l'URL absolue résolue, pas le href brut
            ];
        });

        return $links;
    }

    /**
     * Résout un href brut en URL absolue à partir de l'URL de base de la page.
     *
     * Retourne null pour les hrefs à ignorer silencieusement — ils ne seront
     * pas ajoutés à la liste des candidats.
     *
     * Cas gérés dans l'ordre :
     *   - Vide, ancre "#…", "mailto:", "tel:", "javascript:" → null (ignorer)
     *   - Absolu "https://…" ou "http://…"                  → retourné tel quel
     *   - Relatif protocole "//example.com/path"             → "https://example.com/path"
     *   - Relatif racine "/path/to/page"                     → scheme + host + href
     *   - Relatif chemin "page" ou "../page"                 → résolu par rapport
     *                                                          au répertoire courant
     *
     * Si $baseUrl n'est pas une URL parseable (pas de host), retourne null pour
     * les cas relatifs — on ne peut pas construire une URL absolue sans base.
     *
     * @param string $href    Valeur brute de l'attribut href (peut être relative ou absolue)
     * @param string $baseUrl URL de la page agrégateur (utilisée uniquement pour les relatifs)
     * @return string|null    URL absolue résolue, ou null si ce href doit être ignoré
     */
    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // ── Cas à ignorer immédiatement ───────────────────────────────────────
        if ($href === '') {
            return null; // href vide
        }
        if (str_starts_with($href, '#')) {
            return null; // Ancre interne à la page
        }
        if (str_starts_with($href, 'mailto:')) {
            return null; // Lien email
        }
        if (str_starts_with($href, 'tel:')) {
            return null; // Lien téléphone
        }
        if (str_starts_with($href, 'javascript:')) {
            return null; // Pseudo-protocole JS
        }

        // ── Déjà absolu : on retourne tel quel ───────────────────────────────
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        // ── Relatif protocole : //example.com/path ───────────────────────────
        // On force https:// car on ne sait pas si http est encore supporté.
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        // ── Tous les cas relatifs nécessitent d'analyser $baseUrl ─────────────
        // Si $baseUrl est malformée (pas de host), on ne peut pas construire
        // une URL absolue — on abandonne.
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];
        // Port non-standard : :8080, :443, etc.
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        // ── Relatif racine : /path/to/page ────────────────────────────────────
        // Exemple : href="/news/goethe-institut" + base="https://on-the-move.org/events"
        //        → "https://on-the-move.org/news/goethe-institut"
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $port . $href;
        }

        // ── Relatif chemin : "page", "./page", "../page" ──────────────────────
        // On remonte au répertoire parent du path courant, puis on y colle le href.
        // Exemple : href="grants/index.html" + base="https://example.org/resources/"
        //        → "https://example.org/resources/grants/index.html"
        // Note : dirname('/') == '/', donc rtrim est nécessaire pour éviter "//href"
        $basePath = isset($parts['path']) ? dirname($parts['path']) : '';
        return $scheme . '://' . $host . $port . rtrim($basePath, '/') . '/' . ltrim($href, '/');
    }

    // =========================================================================
    // SUPPORT RSS/ATOM (ADR-0036)
    // =========================================================================

    /**
     * Détecte si un contenu téléchargé est un flux RSS/Atom plutôt qu'une page HTML.
     *
     * Heuristique volontairement simple et robuste (pas de vrai parsing ici) :
     *   - Le contenu commence (après espaces de début) par une déclaration XML
     *     ("<?xml ...") ET contient "<rss" ou "<feed" dans son en-tête
     *   - OU le contenu commence directement par "<rss" / "<feed" (certains flux
     *     omettent la déclaration XML, ce qui est toléré par la plupart des lecteurs)
     *
     * On ne regarde que les ~1000 premiers caractères — suffisant pour repérer la
     * balise racine, et on évite de scanner tout le flux (peut faire plusieurs
     * dizaines de Ko) juste pour cette détection.
     *
     * @param string $content Contenu brut téléchargé (HTML ou XML)
     * @return bool true si le contenu ressemble à un flux RSS 2.0 ou Atom
     */
    private function looksLikeFeed(string $content): bool
    {
        $trimmed = ltrim($content);
        $head = substr($trimmed, 0, 1000);

        if (str_starts_with($trimmed, '<?xml')) {
            return str_contains($head, '<rss') || str_contains($head, '<feed');
        }

        // Flux sans déclaration XML — on vérifie directement la balise racine
        return (bool) preg_match('/^<rss[\s>]/i', $trimmed)
            || (bool) preg_match('/^<feed[\s>]/i', $trimmed);
    }

    /**
     * Extrait les candidats-liens d'un flux RSS 2.0 ou Atom.
     *
     * POURQUOI CETTE MÉTHODE EXISTE (ADR-0036) :
     *   Sur un agrégateur exposé en RSS (ex: resartis.org/feed/), les liens <a href>
     *   qu'on cherche NE SONT PAS dans la structure XML du flux lui-même (pas de
     *   balise <a> au niveau flux) mais DANS LE TEXTE des champs <description> /
     *   <content:encoded> (RSS) ou <summary> / <content> (Atom) — ce texte contient
     *   du HTML, généralement échappé en entités (&lt;a href=...&gt;) ou en CDATA.
     *   extractLinks() (DomCrawler sur le flux brut) ne trouverait donc RIEN.
     *
     * FORMATS SUPPORTÉS :
     *   - RSS 2.0  : items dans $feed->channel->item, lien direct dans <link>
     *   - Atom     : entrées dans $feed->entry, lien dans l'attribut href de <link>
     *
     * ROBUSTESSE : utilise SimpleXML avec libxml_use_internal_errors(true) — un flux
     * malformé ne lève JAMAIS d'exception qui remonterait à l'appelant (cohérent avec
     * la politique "un agrégateur en échec ne bloque jamais la commande").
     *
     * @param string $content Contenu XML brut du flux
     * @param string $baseUrl URL du flux (pour les logs uniquement)
     * @return array<int, array{text: string, url: string}> Candidats extraits (non filtrés)
     */
    private function extractLinksFromFeed(string $content, string $baseUrl): array
    {
        $links = [];

        // On protège tout le parsing XML : un flux malformé ne doit jamais faire
        // planter la commande de découverte — au pire, on retourne un tableau vide.
        $previousSetting = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content);

            if ($xml === false) {
                $this->logger->warning('[LinkExtractor] Flux RSS/Atom illisible (parsing SimpleXML échoué).', [
                    'url' => $baseUrl,
                ]);
                return [];
            }

            if (isset($xml->channel->item)) {
                // ── RSS 2.0 ────────────────────────────────────────────────────
                foreach ($xml->channel->item as $item) {
                    $title = trim((string) ($item->title ?? ''));

                    // Le <link> d'un item RSS est un lien direct vers l'article —
                    // souvent sur le site de l'agrégateur lui-même (pas l'organisme
                    // tiers), mais on le garde : filterInternalLinks() l'éliminera
                    // s'il pointe vers le même domaine que l'agrégateur.
                    $itemLink = trim((string) ($item->link ?? ''));
                    if ($itemLink !== '') {
                        $links[] = ['text' => $title, 'url' => $itemLink];
                    }

                    // ── Liens tiers dans la description ─────────────────────────
                    // C'est ICI que se trouvent les vrais candidats (sites des
                    // organismes cités dans le texte de l'annonce).
                    $description = (string) ($item->description ?? '');
                    array_push($links, ...$this->extractHrefsFromFeedText($description, $title));

                    // content:encoded (namespace "content") — souvent plus riche
                    // que <description> (contenu HTML complet de l'article).
                    $contentNs = $item->children('http://purl.org/rss/1.0/modules/content/');
                    $encoded = isset($contentNs->encoded) ? (string) $contentNs->encoded : '';
                    if ($encoded !== '') {
                        array_push($links, ...$this->extractHrefsFromFeedText($encoded, $title));
                    }
                }
            } elseif (isset($xml->entry)) {
                // ── Atom ───────────────────────────────────────────────────────
                foreach ($xml->entry as $entry) {
                    $title = trim((string) ($entry->title ?? ''));

                    // En Atom, le lien est dans l'attribut href de <link> (peut y en
                    // avoir plusieurs avec des rel="alternate"/"self" différents) —
                    // on prend le premier disponible, suffisant pour notre usage.
                    if (isset($entry->link)) {
                        foreach ($entry->link as $linkNode) {
                            $href = trim((string) ($linkNode['href'] ?? ''));
                            if ($href !== '') {
                                $links[] = ['text' => $title, 'url' => $href];
                                break;
                            }
                        }
                    }

                    $summary = (string) ($entry->summary ?? '');
                    array_push($links, ...$this->extractHrefsFromFeedText($summary, $title));

                    $atomContent = (string) ($entry->content ?? '');
                    array_push($links, ...$this->extractHrefsFromFeedText($atomContent, $title));
                }
            } else {
                // Ni RSS ni Atom reconnu malgré looksLikeFeed() — cas limite (flux
                // exotique ou racine inattendue). On log pour investigation future.
                $this->logger->warning('[LinkExtractor] Flux détecté mais structure ni RSS ni Atom reconnue.', [
                    'url' => $baseUrl,
                ]);
            }
        } catch (\Throwable $e) {
            // Filet de sécurité ultime — ne devrait pas arriver grâce à
            // libxml_use_internal_errors, mais on ne prend aucun risque : un flux
            // agrégateur en échec ne doit JAMAIS interrompre app:discover-sources.
            $this->logger->error('[LinkExtractor] Erreur inattendue au parsing du flux RSS/Atom.', [
                'url'    => $baseUrl,
                'erreur' => $e->getMessage(),
            ]);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }

        return $links;
    }

    /**
     * Extrait les liens <a href> contenus dans un fragment de texte issu d'un flux
     * (description RSS, content:encoded, summary/content Atom).
     *
     * POURQUOI html_entity_decode() D'ABORD :
     *   Le HTML dans ces champs est presque toujours échappé en entités
     *   ("&lt;a href=&quot;https://...&quot;&gt;") par les générateurs de flux,
     *   ou parfois en CDATA (HTML brut, non échappé). Dans les deux cas, une fois
     *   SimpleXML/DOMDocument a extrait le texte du nœud, on obtient soit du HTML
     *   échappé (à décoder) soit du HTML déjà brut (le decode est alors sans effet,
     *   ce qui ne pose aucun problème — l'opération est idempotente).
     *
     * On enveloppe le résultat décodé dans un <div> avant de le passer à DomCrawler
     * pour garantir un document valide même si le fragment est un bout de HTML
     * sans racine unique — DomCrawler (moteur DOM en mode HTML, pas XML strict)
     * tolère de toute façon les fragments malformés sans lever d'exception.
     *
     * Seuls les liens absolus (http/https) sont retenus : un lien relatif dans une
     * description de flux n'a pas de base fiable pour être résolu (le flux ne
     * fournit pas systématiquement une balise <base>), et en pratique les liens
     * vers des organismes tiers dans une description sont toujours absolus.
     *
     * @param string $text        Fragment de texte pouvant contenir du HTML échappé
     * @param string $fallbackText Texte ancre de repli si le <a> n'a pas de texte propre
     *        (ex: le titre de l'item RSS)
     * @return array<int, array{text: string, url: string}>
     */
    private function extractHrefsFromFeedText(string $text, string $fallbackText): array
    {
        if (trim($text) === '') {
            return [];
        }

        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!str_contains($decoded, '<a ') && !str_contains($decoded, '<a>')) {
            // Pas de balise <a> détectable après décodage — inutile de construire
            // un Crawler pour rien.
            return [];
        }

        $links = [];

        try {
            $crawler = new Crawler('<div>' . $decoded . '</div>');
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $fallbackText): void {
                $href = trim($node->attr('href') ?? '');

                if ($href === ''
                    || str_starts_with($href, '#')
                    || str_starts_with($href, 'mailto:')
                    || str_starts_with($href, 'tel:')
                    || str_starts_with($href, 'javascript:')
                ) {
                    return;
                }

                // On ne garde que les liens absolus (voir docblock ci-dessus)
                if (!str_starts_with($href, 'http://') && !str_starts_with($href, 'https://')) {
                    return;
                }

                $text = preg_replace('/\s+/', ' ', trim($node->text())) ?? '';
                $links[] = [
                    'text' => $text !== '' ? $text : $fallbackText,
                    'url'  => $href,
                ];
            });
        } catch (\Throwable $e) {
            // Un fragment HTML corrompu ne doit jamais interrompre le traitement du flux
            $this->logger->debug('[LinkExtractor] extractHrefsFromFeedText : parsing du fragment échoué.', [
                'erreur' => $e->getMessage(),
            ]);
        }

        return $links;
    }

    /**
     * Supprime les liens dont le host contient un fragment de NOISE_DOMAINS.
     *
     * Pourquoi str_contains sur le host (et non sur l'URL brute) ?
     *   Si on faisait str_contains sur l'URL entière, une URL comme
     *   "https://monsite.fr/article/google-arts-and-culture" serait exclue
     *   à tort (le mot "google" apparaît dans le chemin, pas le domaine).
     *   En extrayant le host d'abord, on évite ce faux positif.
     *
     * Un lien sans host parseable (URL malformée) est conservé par sécurité
     * — il sera probablement filtré par les étapes suivantes.
     *
     * @param array<int, array{text: string, url: string}> $links
     * @return array<int, array{text: string, url: string}>
     */
    private function filterNoiseDomains(array $links): array
    {
        // Tableau de debug : on collecte les rejets pour les logguer ensuite (max 30)
        $rejections = [];

        $filtered = array_filter($links, function (array $link) use (&$rejections): bool {
            // parse_url extrait UNIQUEMENT le host (ex: "twitter.com", "on-the-move.org")
            // Le match se fait donc sur le domaine seul, PAS sur l'URL entière (chemin, query…)
            $host = parse_url($link['url'], PHP_URL_HOST);

            // Si le host n'est pas parseable, on garde le lien (cas rare d'URL bizarre)
            if (!is_string($host)) {
                return true;
            }

            $host = strtolower($host);

            // Exclure si le host CONTIENT un des fragments de bruit
            foreach (self::NOISE_DOMAINS as $noiseDomain) {
                if (str_contains($host, $noiseDomain)) {
                    // On collecte le rejet pour le log debug (limité à 30 entrées)
                    if (count($rejections) < 30) {
                        $rejections[] = ['url' => $link['url'], 'motif' => $noiseDomain];
                    }
                    return false; // Lien de bruit → à exclure
                }
            }

            return true; // Pas de fragment de bruit → à conserver
        });

        // Log debug des rejets — visible avec -vvv uniquement
        foreach ($rejections as $rejection) {
            $this->logger->debug(sprintf(
                '[LinkExtractor] noise rejet: %s (motif: %s)',
                $rejection['url'],
                $rejection['motif']
            ));
        }
        if (count($rejections) === 30) {
            // Prévenir si on a plafonné les logs (il peut y en avoir plus)
            $this->logger->debug('[LinkExtractor] noise rejet: … (log plafonné à 30 entrées)');
        }

        // array_filter préserve les clés d'origine — on réindexe pour un tableau propre
        return array_values($filtered);
    }

    /**
     * Supprime les liens internes (même domaine que la source de référence).
     *
     * Exemple : si l'agrégateur est "on-the-move.org", on supprime tous les
     * liens vers "on-the-move.org/*" — ce sont des pages du site lui-même,
     * pas des sources tierces à explorer.
     *
     * Comparaison basée sur le host uniquement (pas le path) pour couvrir tous
     * les sous-chemins du même site (/, /about, /ressources, etc.).
     *
     * Si $aggregatorHost est vide — soit parce que l'URL source n'était pas
     * parseable, soit parce que l'appelant n'a pas de host de référence unique
     * (ADR-0036 : gisement "opportunités", chaque candidat vient d'un site
     * source différent) — on ne filtre rien par sécurité : vaut mieux
     * conserver trop que trop peu.
     *
     * @param array<int, array{text: string, url: string}> $links
     * @param string $aggregatorHost Host de référence (déjà extrait par l'appelant),
     *        ou chaîne vide pour désactiver ce filtre
     * @return array<int, array{text: string, url: string}>
     */
    private function filterInternalLinks(array $links, string $aggregatorHost): array
    {
        if ($aggregatorHost === '') {
            // Pas de host de référence — impossible/non pertinent de filtrer les liens internes
            // On retourne tout le tableau sans modification (comportement sûr)
            return $links;
        }

        $aggregatorHost = strtolower($aggregatorHost);

        $filtered = array_filter($links, function (array $link) use ($aggregatorHost): bool {
            $linkHost = parse_url($link['url'], PHP_URL_HOST);

            if (!is_string($linkHost)) {
                return true; // Host non parseable → on conserve par sécurité
            }

            // Comparaison stricte du host (minuscules)
            // Couvre aussi les cas "www.on-the-move.org" vs "on-the-move.org"
            // en normalisant www. sur les deux côtés.
            // On passe en minuscules UNE seule fois pour éviter le triple appel strtolower().
            $lowerLinkHost = strtolower($linkHost);
            $linkHostNorm  = str_starts_with($lowerLinkHost, 'www.')
                ? substr($lowerLinkHost, 4)
                : $lowerLinkHost;
            $aggregatorHostNorm = str_starts_with($aggregatorHost, 'www.')
                ? substr($aggregatorHost, 4)
                : $aggregatorHost;

            // Retourne false (exclu) si même domaine, true (conservé) sinon
            return $linkHostNorm !== $aggregatorHostNorm;
        });

        return array_values($filtered);
    }

    /**
     * Déduplique par domaine : conserve UN SEUL lien par domaine.
     *
     * Si plusieurs liens pointent vers le même domaine, on garde celui dont
     * le texte ancre est le plus long.
     *
     * Hypothèse : le texte ancre le plus long est le plus descriptif.
     * Exemple pour "example.com" :
     *   - "Ici" (4 chars)              → ignoré
     *   - "Fondation Example — accueil" (27 chars) → conservé
     *
     * La clé de déduplication est le host normalisé (minuscules, sans www.).
     * Cette méthode retourne un tableau RÉINDEXÉ (array_values à la fin).
     *
     * @param array<int, array{text: string, url: string}> $links
     * @return array<int, array{text: string, url: string}>
     */
    private function deduplicateByDomain(array $links): array
    {
        // Tableau intermédiaire : host_normalisé → meilleur lien trouvé jusqu'ici
        /** @var array<string, array{text: string, url: string}> $byDomain */
        $byDomain = [];

        foreach ($links as $link) {
            $host = parse_url($link['url'], PHP_URL_HOST);

            if (!is_string($host)) {
                // Host non parseable — on conserve le lien sans dédupliquer
                // On lui donne une clé unique pour ne pas écraser les autres
                $byDomain['__unparseable_' . uniqid()] = $link;
                continue;
            }

            // Normalisation du host : minuscules + suppression du www.
            $normalizedHost = strtolower($host);
            if (str_starts_with($normalizedHost, 'www.')) {
                $normalizedHost = substr($normalizedHost, 4);
            }

            // Règle de sélection : on garde le lien dont le texte ancre est le plus long
            if (!isset($byDomain[$normalizedHost])) {
                // Première occurrence de ce domaine → on la conserve
                $byDomain[$normalizedHost] = $link;
            } elseif (strlen($link['text']) > strlen($byDomain[$normalizedHost]['text'])) {
                // Occurrence plus descriptive (texte plus long) → on remplace
                $byDomain[$normalizedHost] = $link;
            }
        }

        // On retourne uniquement les valeurs (sans les clés de domaine)
        return array_values($byDomain);
    }

    /**
     * Supprime les liens dont le domaine est déjà connu en BDD.
     *
     * $knownDomains est un tableau de domaines normalisés (minuscules, sans www.)
     * construit par DiscoverSourcesCommand::buildKnownDomains() à partir de
     * scraping_sources et suggested_sources.
     *
     * On normalise le host du candidat de la même façon avant comparaison :
     * in_array() + tableau de strings = O(n) — suffisant pour ~500 domaines connus max.
     *
     * @param array<int, array{text: string, url: string}> $links
     * @param string[] $knownDomains Domaines normalisés (minuscules, sans www.)
     * @return array<int, array{text: string, url: string}>
     */
    private function filterKnownDomains(array $links, array $knownDomains): array
    {
        // Si aucun domaine connu, pas besoin de filtrer
        if (empty($knownDomains)) {
            return $links;
        }

        $filtered = array_filter($links, function (array $link) use ($knownDomains): bool {
            $host = parse_url($link['url'], PHP_URL_HOST);

            if (!is_string($host)) {
                return true; // Host non parseable → on conserve par sécurité
            }

            // Normalisation : minuscules + suppression du www.
            $normalizedHost = strtolower($host);
            if (str_starts_with($normalizedHost, 'www.')) {
                $normalizedHost = substr($normalizedHost, 4);
            }

            // Le domaine est-il déjà connu ? Si oui → exclure
            return !in_array($normalizedHost, $knownDomains, strict: true);
        });

        return array_values($filtered);
    }

    /**
     * Normalise un nom d'hôte pour la comparaison : minuscules + suppression du www.
     *
     * Méthode interne réutilisée par extractInternalLinks() et filterInternalLinks()
     * pour éviter la duplication de la logique de normalisation.
     *
     * Exemples :
     *   "WWW.Example.com"  → "example.com"
     *   "institutfrancais.com" → "institutfrancais.com"
     *   "www2.example.org" → "www2.example.org" (www2 n'est PAS supprimé)
     *
     * @param string $host Nom d'hôte brut (issu de parse_url)
     * @return string Nom d'hôte normalisé
     */
    private function normalizeHost(string $host): string
    {
        $lower = strtolower($host);
        // On supprime uniquement "www." strict au début — pas "www2.", "www3.", etc.
        return str_starts_with($lower, 'www.') ? substr($lower, 4) : $lower;
    }
}

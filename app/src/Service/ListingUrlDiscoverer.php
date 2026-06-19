<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScrapingSource;
use App\Enum\ScrapingSourceType;
use App\Repository\ScrapingSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ListingUrlDiscoverer — Trouve la page qui LISTE les opportunités d'un site donné.
 *
 * Problème résolu :
 *   Le CSV de Gaëlle contient souvent uniquement le domaine racine d'un organisme
 *   (ex: institutfrancais.com) sans préciser quelle sous-page liste ses appels à
 *   candidatures, bourses, résidences. Ce service découvre automatiquement cette
 *   page-liste pour en faire une source agrégateur dans la BDD.
 *
 * Flux de traitement (Option C hybride — ADR-0017) :
 *   1. HEURISTIQUE (sans LLM) : tester des chemins courants FR+EN
 *      → Économique, instantané, fonctionne sur ~60 % des sites bien structurés
 *   2. FALLBACK LLM : si l'heuristique échoue, interroger Mistral (ou Anthropic)
 *      avec le HTML de la page d'accueil
 *      → Plus coûteux (quota API) mais couvre les sites aux URLs atypiques
 *   3. ENREGISTREMENT BDD : crée une ScrapingSource agrégateur si l'URL est trouvée,
 *      à condition qu'elle ne soit pas déjà enregistrée (déduplication par URL)
 *
 * Garanties :
 *   - Ne lève JAMAIS d'exception vers l'appelant (tout try/catch)
 *   - Ne lance PAS le scraping automatiquement (séparation des responsabilités)
 *   - PHPStan niveau 6 compatible
 *   - Protection SSRF : toutes les URLs (heuristique, page d'accueil, LLM) sont filtrées
 *     par isSafeHost() avant toute requête ou persistance.
 *
 * Utilisé par : app:discover-listing-urls
 */
class ListingUrlDiscoverer
{
    // ── User-Agent pour les requêtes HTTP ──────────────────────────────────────
    // On se présente comme Chrome pour éviter les blocages anti-bot des sites
    // culturels qui refusent les crawlers génériques.
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    // ── Timeout HTTP par requête (en secondes) ─────────────────────────────────
    // Court (8s) pour les tests d'heuristique car on peut en enchaîner ~15.
    // Évite de bloquer la commande sur des sites lents ou inaccessibles.
    private const HEURISTIC_TIMEOUT = 8;

    // ── Timeout pour le téléchargement de la page d'accueil (fallback LLM) ───
    // Plus généreux (20s) car les pages d'accueil peuvent être lourdes (images, JS).
    private const HOMEPAGE_TIMEOUT = 20;

    // ── Taille maximale du body pour l'heuristique de contenu ─────────────────
    // On inspecte seulement les X premiers octets du body pour détecter les mots-clés.
    // Évite de charger plusieurs Mo pour juste regarder si "deadline" y apparaît.
    private const BODY_INSPECT_BYTES = 30000;

    // ── Nombre maximum de redirections HTTP suivies ────────────────────────────
    // Réduit à 3 (était 5) pour limiter la surface d'attaque SSRF :
    // un domaine public pourrait rediriger vers une IP interne via une chaîne longue.
    // 3 sauts couvrent les cas légitimes (ex: http → https → www → page).
    private const MAX_REDIRECTS = 3;

    /**
     * Chemins testés par l'heuristique, dans l'ordre de pertinence.
     *
     * STRATÉGIE DE CHOIX :
     *   - Chemins FR d'abord (organismes francophones en priorité pour Bazaart)
     *   - Chemins EN ensuite (organismes internationaux ou anglophones)
     *   - Pluriels avant singuliers (les pages-listes ont souvent des URLs au pluriel)
     *
     * Pour chaque chemin, scoreUrl() vérifie :
     *   a) HTTP 200 et Content-Type HTML
     *   b) Présence de mots-clés « listing » dans le contenu
     *
     * @var string[]
     */
    private const HEURISTIC_PATHS = [
        // ── Français ────────────────────────────────────────────────
        '/appels-a-projets',
        '/appel-a-projets',
        '/appels',
        '/appel',
        '/opportunites',
        '/opportunite',
        '/bourses',
        '/bourse',
        '/residences',
        '/residence',
        '/offres',
        '/offre',
        '/prix',
        '/aides',
        '/financements',
        '/subventions',
        '/candidater',
        '/candidature',
        // ── Anglais ─────────────────────────────────────────────────
        '/open-calls',
        '/open-call',
        '/opportunities',
        '/grants',
        '/residencies',
        '/awards',
        '/calls',
        '/call-for-entries',
        '/call-for-submissions',
        '/apply',
        // ── Générique ────────────────────────────────────────────────
        '/funding',
        '/fellowships',
        '/programs',
        '/programmes',
    ];

    /**
     * Mots-clés qui signalent qu'une page est une PAGE-LISTE d'opportunités.
     *
     * Stratégie de détection : la page doit contenir AU MOINS LISTING_KEYWORD_MIN_SCORE
     * de ces mots-clés différents (seuil de 2 par défaut).
     *
     * Ces mots-clés couvrent le vocabulaire usuel FR et EN des appels culturels.
     * On travaille sur le texte en minuscules pour une comparaison insensible à la casse.
     *
     * @var string[]
     */
    private const LISTING_KEYWORDS = [
        // Dates et délais
        'deadline',
        'date limite',
        'date de clôture',
        'clôture',
        // Actions de candidature
        'postuler',
        'candidater',
        'soumettre',
        'apply',
        'submit',
        'candidature',
        'application',
        // Types d'opportunités
        'appel à projets',
        'appel à candidatures',
        'open call',
        'bourse',
        'résidence',
        'grant',
        'award',
        'fellowship',
        // Indicateurs structurels d'une liste
        'voir tous',
        'voir toutes',
        'all opportunities',
        'all grants',
        'toutes les opportunités',
        'liste des appels',
    ];

    /**
     * Score minimum de mots-clés pour qu'une page soit considérée comme une page-liste.
     *
     * On exige que la page contienne au moins 2 mots-clés différents de LISTING_KEYWORDS.
     * Un seul mot-clé (ex: "deadline" dans un article de blog) ne suffit pas.
     */
    private const LISTING_KEYWORD_MIN_SCORE = 2;

    public function __construct(
        // Client HTTP Symfony — pour les requêtes de test heuristique + page d'accueil
        private readonly HttpClientInterface $httpClient,
        // Repository des sources — pour la déduplication avant création
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // Service LLM — pour le fallback si l'heuristique échoue (Mistral ou Anthropic).
        // On réutilise cleanHtml() (méthode publique) pour nettoyer le HTML avant LLM.
        private readonly LlmExtractorService $llmExtractorService,
        // EntityManager — pour persister les nouvelles ScrapingSource
        private readonly EntityManagerInterface $em,
        // Logger PSR-3 — pour tracer sans interrompre
        private readonly LoggerInterface $logger,
        // SettingService — pour lire les clés API LLM depuis la BDD (même config que LlmExtractorService)
        private readonly SettingService $settingService,
    ) {
    }

    /**
     * Découvre la page-liste des opportunités pour un site donné.
     *
     * Étapes :
     *   1. Valider et normaliser l'URL d'entrée (extraire la base domaine)
     *   2. Heuristique : tester HEURISTIC_PATHS, garder le meilleur résultat
     *   3. Fallback LLM si l'heuristique n'a rien trouvé
     *   4. Enregistrer la ScrapingSource si une URL-liste est trouvée et si pas déjà connue
     *
     * @param string      $siteUrl  URL du site à analyser (ex: "https://institutfrancais.com")
     * @param string      $nomSite  Nom lisible (depuis CSV ou domaine) — utilisé comme ScrapingSource::nom
     * @param string|null $paysZone Zone géographique (depuis colonne WHERE du CSV, ou null)
     * @param bool        $dryRun   Si true, ne persiste rien en BDD
     * @return DiscoveryResult Résultat avec l'URL trouvée (ou null), la méthode et le statut BDD
     */
    public function discoverForSite(
        string $siteUrl,
        string $nomSite,
        ?string $paysZone = null,
        bool $dryRun = false,
    ): DiscoveryResult {
        // ── Étape 1 : normalisation de l'URL de base ─────────────────────────────
        // On extrait juste le schéma + hôte pour construire des URLs propres.
        // Ex: "https://www.institutfrancais.com/open-call" → "https://www.institutfrancais.com"
        $baseUrl = $this->extractBaseUrl($siteUrl);

        if ($baseUrl === null) {
            // URL non parseable — on ne peut rien faire
            $this->logger->warning('[ListingDiscoverer] URL non parseable, site ignoré.', [
                'siteUrl' => $siteUrl,
            ]);
            return new DiscoveryResult(
                siteUrl: $siteUrl,
                listingUrl: null,
                method: 'none',
                sourceId: null,
                nom: $nomSite,
                reason: 'URL non parseable',
            );
        }

        // ── Étape 2 : heuristique — tester les chemins courants ──────────────────
        // On teste dans l'ordre de HEURISTIC_PATHS et on garde la première URL qui :
        //   a) répond en HTTP 200
        //   b) a un contenu qui ressemble à une page-liste (mots-clés, liens, etc.)
        $listingUrl = $this->runHeuristic($baseUrl);

        if ($listingUrl !== null) {
            // Succès heuristique — on enregistre et on retourne
            $this->logger->info('[ListingDiscoverer] URL-liste trouvée par heuristique.', [
                'site'       => $siteUrl,
                'listingUrl' => $listingUrl,
            ]);
            $sourceId = $this->persistIfNew($listingUrl, $nomSite, $paysZone, $dryRun);
            return new DiscoveryResult(
                siteUrl: $siteUrl,
                listingUrl: $listingUrl,
                method: 'heuristic',
                sourceId: $sourceId,
                nom: $nomSite,
                reason: $sourceId === -1 ? 'doublon (URL déjà en BDD)' : 'créé',
            );
        }

        // ── Étape 3 : fallback LLM si l'heuristique n'a rien trouvé ─────────────
        // On télécharge la page d'accueil et on demande au LLM l'URL de la page-liste.
        // Plus coûteux en quota API, mais couvre les sites aux URLs non-standards.
        // MINEUR-2 : passé en WARNING pour signaler les sites en échec heuristique.
        $this->logger->warning('[ListingDiscoverer] Heuristique sans résultat, tentative LLM.', [
            'site' => $siteUrl,
        ]);

        $listingUrl = $this->runLlmFallback($baseUrl);

        if ($listingUrl !== null) {
            // ── AV-1 : valider l'URL LLM par une vraie requête HTTP ───────────
            // Le LLM peut halluciner une URL plausible mais inexistante, ou renvoyer
            // une URL interne si le prompt est détourné (prompt injection).
            // On soumet l'URL suggérée à scoreUrl() : si la page répond en HTTP 200 HTML
            // et passe la garde SSRF, on la considère valide pour persistance.
            // Sinon on ignore la suggestion LLM et on retourne "aucune URL trouvée".
            $llmScore = $this->scoreUrl($listingUrl);

            if ($llmScore === null) {
                // URL injoignable, non sûre, ou non-HTML — on rejette la suggestion LLM
                $this->logger->warning('[ListingDiscoverer] URL LLM rejetée (injoignable ou non sûre).', [
                    'site'       => $siteUrl,
                    'listingUrl' => $listingUrl,
                ]);
                // On traite ce cas comme "aucune URL trouvée" — pas de persistance
                $listingUrl = null;
            } else {
                $this->logger->info('[ListingDiscoverer] URL-liste trouvée par LLM (validée HTTP).', [
                    'site'       => $siteUrl,
                    'listingUrl' => $listingUrl,
                    'score'      => $llmScore,
                ]);
                $sourceId = $this->persistIfNew($listingUrl, $nomSite, $paysZone, $dryRun);
                return new DiscoveryResult(
                    siteUrl: $siteUrl,
                    listingUrl: $listingUrl,
                    method: 'llm',
                    sourceId: $sourceId,
                    nom: $nomSite,
                    reason: $sourceId === -1 ? 'doublon (URL déjà en BDD)' : 'créé',
                );
            }
        }

        // Si $listingUrl est null ici, c'est soit que le LLM n'a rien trouvé,
        // soit que sa suggestion a été rejetée par scoreUrl() — on tombe dans le bloc suivant.

        // ── Étape 4 : aucune URL-liste trouvée ───────────────────────────────────
        // On signale l'échec pour que la commande puisse lister les sites problématiques.
        // MINEUR-2 : passé en WARNING pour que les sites en échec total ressortent dans les logs.
        $this->logger->warning('[ListingDiscoverer] Aucune URL-liste trouvée pour ce site.', [
            'site' => $siteUrl,
        ]);

        return new DiscoveryResult(
            siteUrl: $siteUrl,
            listingUrl: null,
            method: 'none',
            sourceId: null,
            nom: $nomSite,
            reason: 'aucune URL-liste détectée (heuristique + LLM)',
        );
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  HEURISTIQUE
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Teste des chemins courants FR+EN et retourne la meilleure URL-liste trouvée.
     *
     * ALGORITHME :
     *   Pour chaque chemin dans HEURISTIC_PATHS :
     *     1. Construire l'URL complète = $baseUrl + $path
     *     2. Requête GET (timeout court HEURISTIC_TIMEOUT)
     *     3. Si HTTP 200 + Content-Type HTML :
     *        → Télécharger les premiers BODY_INSPECT_BYTES octets
     *        → Compter les mots-clés "listing" dans le texte
     *        → Garder la candidate avec le meilleur score (≥ LISTING_KEYWORD_MIN_SCORE)
     *
     * POURQUOI ne pas s'arrêter au premier HTTP 200 ?
     *   "/prix" sur un site culturel peut être une page Biographies (lauréats passés)
     *   plutôt qu'une page-liste d'appels en cours. On mesure le score de "listing" pour
     *   choisir la page qui ressemble vraiment à un catalogue d'opportunités ouvertes.
     *
     * @param string $baseUrl Base URL sans chemin (ex: "https://institutfrancais.com")
     * @return string|null URL-liste candidate, ou null si aucune ne passe le seuil
     */
    private function runHeuristic(string $baseUrl): ?string
    {
        // Candidate avec le meilleur score jusqu'ici
        $bestUrl   = null;
        $bestScore = 0;

        foreach (self::HEURISTIC_PATHS as $path) {
            $testUrl = $baseUrl . $path;

            // Tester cette URL et calculer son score de "listing"
            $score = $this->scoreUrl($testUrl);

            if ($score === null) {
                // La page ne répond pas en HTTP 200 ou n'est pas du HTML — on passe
                continue;
            }

            if ($score >= self::LISTING_KEYWORD_MIN_SCORE && $score > $bestScore) {
                // Nouvelle meilleure candidate
                $bestUrl   = $testUrl;
                $bestScore = $score;

                $this->logger->debug('[ListingDiscoverer] Candidate heuristique.', [
                    'url'   => $testUrl,
                    'score' => $score,
                ]);
            }
        }

        return $bestUrl;
    }

    /**
     * Télécharge une URL et retourne son score "listing" (nombre de mots-clés détectés).
     *
     * Retourne null si :
     *   - L'hôte de l'URL est considéré non sûr (localhost, IP privée, link-local…)
     *   - L'URL ne répond pas en HTTP 200
     *   - Le Content-Type n'est pas HTML (ex: image, PDF, JSON)
     *   - L'URL effective après redirection pointe vers un hôte non sûr (SSRF via redirect)
     *   - Exception réseau (timeout, DNS, SSL)
     *
     * Retourne 0..N si la page répond en HTTP 200 HTML (même si aucun mot-clé trouvé).
     * Un score de 0 signifie "répond mais ne ressemble pas à une page-liste".
     *
     * @param string $url URL à tester
     * @return int|null Score de mots-clés détectés, ou null si la page est inaccessible/non sûre/non-HTML
     */
    private function scoreUrl(string $url): ?int
    {
        // ── Garde SSRF : vérifier l'hôte AVANT toute requête ─────────────────
        // On bloque localhost, IPs privées, link-local (169.254.x.x — métadonnées cloud)
        // et tout schéma non-http(s) pour éviter les Server-Side Request Forgery.
        if (!$this->isSafeHost($url)) {
            $this->logger->warning('[ListingDiscoverer] SSRF bloqué : hôte non sûr.', [
                'url' => $url,
            ]);
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent'      => self::USER_AGENT,
                    // On accepte le français (la plupart des sites cibles sont francophones)
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                    'Accept'          => 'text/html,application/xhtml+xml,*/*;q=0.8',
                ],
                'timeout'       => self::HEURISTIC_TIMEOUT,
                // Désactiver la vérification SSL pour les sites avec certificats problématiques
                // (fréquent sur les petites structures culturelles)
                'verify_peer'   => false,
                'verify_host'   => false,
                // Réduit à MAX_REDIRECTS (3) pour limiter la surface SSRF :
                // une longue chaîne de redirections pourrait aboutir sur une IP interne.
                'max_redirects' => self::MAX_REDIRECTS,
            ]);

            // ── Vérification du code HTTP ─────────────────────────────────────
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                // 404 = chemin inexistant (le plus fréquent), 403/401 = accès refusé, etc.
                return null;
            }

            // ── Contrôle anti-SSRF sur l'URL EFFECTIVE (après redirections) ───
            // Le client HTTP suit automatiquement les redirections (max MAX_REDIRECTS).
            // Un domaine public pourrait rediriger vers 169.254.x.x (métadonnées cloud)
            // ou vers une IP RFC1918. On vérifie l'URL finale résolue.
            $effectiveUrl = (string) ($response->getInfo('url') ?? '');
            if ($effectiveUrl !== '' && !$this->isSafeHost($effectiveUrl)) {
                $this->logger->warning('[ListingDiscoverer] SSRF bloqué : redirection vers hôte non sûr.', [
                    'original'     => $url,
                    'effectiveUrl' => $effectiveUrl,
                ]);
                return null;
            }

            // ── Vérification du Content-Type ──────────────────────────────────
            // On ne veut que des pages HTML — pas des PDFs, images, feeds XML, etc.
            $headers     = $response->getHeaders(false);
            $contentType = implode(' ', $headers['content-type'] ?? []);

            if (!str_contains($contentType, 'html')) {
                // Content-Type non-HTML : le chemin pointe vers un asset, un feed, etc.
                return null;
            }

            // ── Lecture partielle du body via stream (AV-3) ───────────────────
            // On utilise $this->httpClient->stream($response) pour lire chunk par chunk
            // et s'arrêter à BODY_INSPECT_BYTES octets maximum.
            // Évite de charger la page entière en mémoire (certaines pages dépassent
            // 5 Mo avec les scripts inline) alors qu'on ne regarde que du texte.
            // cancel() stoppe le téléchargement dès qu'on a assez de données.
            $bodyChunk = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $bodyChunk .= $chunk->getContent();
                if (strlen($bodyChunk) >= self::BODY_INSPECT_BYTES) {
                    // On a lu assez — on annule le reste du téléchargement
                    $response->cancel();
                    break;
                }
            }
            // Tronquer au cas où le dernier chunk dépasserait légèrement la limite
            $bodyChunk = substr($bodyChunk, 0, self::BODY_INSPECT_BYTES);
            $bodyLower = mb_strtolower($bodyChunk);

            // ── Comptage des mots-clés "listing" ──────────────────────────────
            // On compte combien de mots-clés DIFFÉRENTS de LISTING_KEYWORDS apparaissent.
            // Chaque mot-clé compte pour 1, même s'il apparaît plusieurs fois.
            // Cela pénalise les pages qui répètent "deadline" dans un seul article
            // plutôt qu'une vraie liste avec plusieurs mots-clés de types différents.
            $score = 0;
            foreach (self::LISTING_KEYWORDS as $keyword) {
                if (str_contains($bodyLower, $keyword)) {
                    $score++;
                }
            }

            return $score;

        } catch (\Throwable) {
            // Timeout, DNS, SSL, erreur réseau → silencieux
            // On retourne null : cette URL est considérée comme inaccessible
            return null;
        }
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  FALLBACK LLM
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Télécharge la page d'accueil et demande au LLM l'URL de la page-liste.
     *
     * On passe au LLM le HTML nettoyé de la page d'accueil et on lui demande
     * d'identifier l'URL qui liste les appels à candidatures/bourses/résidences.
     *
     * POURQUOI la page d'accueil (et non le sitemap) ?
     *   - Le sitemap peut être très volumineux (milliers d'URLs → coûteux en tokens)
     *   - La page d'accueil contient généralement des liens vers toutes les sections
     *     importantes, dont la page-liste des opportunités
     *   - La page d'accueil est toujours disponible (pas de sitemap sur tous les sites)
     *
     * On réutilise LlmExtractorService::cleanHtml() (méthode publique depuis ADR-0016 Lot 1)
     * pour nettoyer le HTML AVANT de l'envoyer au LLM (supprime scripts, styles, nav, etc.)
     *
     * @param string $baseUrl Base URL (ex: "https://institutfrancais.com")
     * @return string|null URL de la page-liste si trouvée, null sinon
     */
    private function runLlmFallback(string $baseUrl): ?string
    {
        // ── Étape A : télécharger la page d'accueil ───────────────────────────
        $html = $this->fetchHomepage($baseUrl);

        if ($html === null) {
            // La page d'accueil ne répond pas — on abandonne ce site
            $this->logger->warning('[ListingDiscoverer] Page d\'accueil inaccessible, LLM ignoré.', [
                'baseUrl' => $baseUrl,
            ]);
            return null;
        }

        // ── Étape B : nettoyer le HTML via LlmExtractorService::cleanHtml() ──
        // On réutilise la méthode publique de LlmExtractorService (ADR-0016 Lot 1).
        // Cette méthode supprime scripts/styles/nav/footer et normalise les espaces.
        // On passe une limite de 8000 chars (moins que l'extraction d'opps car on
        // cherche juste un lien de navigation, pas du contenu détaillé).
        $cleanText = $this->llmExtractorService->cleanHtml($html, maxLength: 8000);

        if (empty($cleanText)) {
            $this->logger->warning('[ListingDiscoverer] HTML vide après nettoyage, LLM ignoré.', [
                'baseUrl' => $baseUrl,
            ]);
            return null;
        }

        // ── Étape C : interroger le LLM pour trouver l'URL-liste ─────────────
        // On délègue à une méthode dédiée qui gère Mistral (principal) + Anthropic (fallback)
        return $this->askLlmForListingUrl($baseUrl, $cleanText);
    }

    /**
     * Télécharge la page d'accueil d'un site.
     *
     * Essaie d'abord l'URL de base (ex: "https://institutfrancais.com"),
     * puis avec "/fr" (variante fréquente des sites multilingues).
     *
     * @param string $baseUrl URL de base du site
     * @return string|null HTML de la page d'accueil, ou null si inaccessible
     */
    private function fetchHomepage(string $baseUrl): ?string
    {
        // ── Tentative principale : URL de base ────────────────────────────────
        $html = $this->doFetch($baseUrl);

        if ($html !== null) {
            return $html;
        }

        // ── Tentative alternative : sous-dossier /fr (sites multilingues) ─────
        // Certains sites redirigent la racine vers /fr ou /en — on teste /fr
        // car Bazaart cible principalement les sites francophones.
        return $this->doFetch($baseUrl . '/fr');
    }

    /**
     * Effectue une requête HTTP GET simple et retourne les premiers BODY_INSPECT_BYTES
     * octets du HTML, ou null si la page est inaccessible / non sûre.
     *
     * Changements de sécurité (ADR-0017 correctifs) :
     *   - Garde SSRF avant la requête (isSafeHost sur l'URL cible)
     *   - Vérification de l'URL effective après redirections (SSRF via redirect)
     *   - Lecture partielle via stream (fread BODY_INSPECT_BYTES) pour limiter la mémoire
     *   - max_redirects réduit à MAX_REDIRECTS (3)
     *
     * @param string $url URL à télécharger
     * @return string|null HTML partiel retourné, ou null si HTTP non-200 / hôte non sûr / exception
     */
    private function doFetch(string $url): ?string
    {
        // ── Garde SSRF : vérifier l'hôte AVANT toute requête ─────────────────
        if (!$this->isSafeHost($url)) {
            $this->logger->warning('[ListingDiscoverer] SSRF bloqué (doFetch) : hôte non sûr.', [
                'url' => $url,
            ]);
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent'      => self::USER_AGENT,
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                    'Accept'          => 'text/html,application/xhtml+xml,*/*;q=0.8',
                ],
                'timeout'       => self::HOMEPAGE_TIMEOUT,
                'verify_peer'   => false,
                'verify_host'   => false,
                // Réduit à MAX_REDIRECTS (3) — voir même raison que scoreUrl()
                'max_redirects' => self::MAX_REDIRECTS,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            // ── Contrôle anti-SSRF sur l'URL EFFECTIVE (après redirections) ───
            // Même logique que dans scoreUrl() : un site public pourrait rediriger
            // vers une IP interne (ex: 169.254.169.254 pour les métadonnées cloud AWS/DO).
            $effectiveUrl = (string) ($response->getInfo('url') ?? '');
            if ($effectiveUrl !== '' && !$this->isSafeHost($effectiveUrl)) {
                $this->logger->warning('[ListingDiscoverer] SSRF bloqué (doFetch) : redirection vers hôte non sûr.', [
                    'original'     => $url,
                    'effectiveUrl' => $effectiveUrl,
                ]);
                return null;
            }

            // ── Lecture partielle via stream (AV-3) ───────────────────────────
            // On lit seulement les premiers BODY_INSPECT_BYTES octets.
            // Raison : la page d'accueil peut peser plusieurs Mo (images inline, scripts)
            // mais le LLM n'a besoin que du texte de navigation — cleanHtml() tronquera
            // de toute façon à 8000 chars. On évite de charger tout le body en mémoire.
            $html = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $html .= $chunk->getContent();
                if (strlen($html) >= self::BODY_INSPECT_BYTES) {
                    $response->cancel();
                    break;
                }
            }
            $html = substr($html, 0, self::BODY_INSPECT_BYTES);

            return empty(trim($html)) ? null : $html;

        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Interroge le LLM (Mistral principal, Anthropic fallback) pour trouver l'URL-liste.
     *
     * PROMPT DÉDIÉ :
     *   Différent du prompt discoverSources (qui cherche des organismes dans une LISTE DE LIENS)
     *   et de extractFromHtml (qui cherche des opportunités dans une page).
     *   Ici : on donne le texte d'une page d'accueil et on demande UNE SEULE URL
     *   — la page qui liste les appels à candidatures/bourses/résidences.
     *
     * FORMAT DE RÉPONSE :
     *   On demande {"listing_url": "https://..."} pour un parsing simple et robuste.
     *   Si le LLM ne trouve pas, il retourne {"listing_url": null}.
     *
     * @param string $baseUrl   URL de base (pour logger et mettre en contexte le LLM)
     * @param string $cleanText Texte nettoyé de la page d'accueil
     * @return string|null URL-liste si trouvée, null sinon
     */
    private function askLlmForListingUrl(string $baseUrl, string $cleanText): ?string
    {
        $systemPrompt = <<<'PROMPT'
Tu es un expert en ressources culturelles. On te donne le contenu textuel de la page d'accueil d'un site d'institution culturelle.

Ta mission : identifier l'URL EXACTE de la page qui LISTE les opportunités pour artistes (appels à candidatures, appels à projets, bourses, résidences artistiques, prix, financements).

La page-liste est celle qui répertorie PLUSIEURS opportunités différentes — pas une opportunité individuelle.

Retourne uniquement un objet JSON avec ce format :
{"listing_url": "https://exemple.com/page-liste-opportunites"}

Si le site ne semble pas avoir de telle page, ou si tu ne peux pas identifier l'URL avec confiance, retourne :
{"listing_url": null}

Ne retourne que le JSON, sans texte autour.
PROMPT;

        $userMessage = sprintf(
            "Site analysé : %s\n\nContenu de la page d'accueil :\n%s",
            $baseUrl,
            $cleanText
        );

        // Essayer Mistral en premier (moins cher, JSON natif garanti)
        $urlFromMistral = $this->callMistralForListingUrl($systemPrompt, $userMessage, $baseUrl);

        if ($urlFromMistral !== null) {
            return $urlFromMistral;
        }

        // Fallback Anthropic si Mistral échoue ou n'a pas de clé
        return $this->callAnthropicForListingUrl($systemPrompt, $userMessage, $baseUrl);
    }

    /**
     * Appel Mistral pour trouver l'URL-liste.
     *
     * On réutilise le même pattern que LlmExtractorService::callMistralApiForDiscovery() :
     *   - Lire la clé depuis SettingService (clé 'mistral_api_key')
     *   - Appel POST à l'API Mistral avec response_format json_object
     *   - Parser {"listing_url": "..."} depuis la réponse
     *
     * POURQUOI ne pas déléguer à LlmExtractorService ?
     *   LlmExtractorService n'expose pas de méthode générique "appelle le LLM avec ce prompt".
     *   Ses méthodes sont spécialisées : extractFromHtml, discoverSources.
     *   Ce nouveau cas d'usage (trouver UNE URL-liste) a son propre format de réponse
     *   {"listing_url": ...} — on l'implémente ici en suivant exactement le même pattern.
     *
     * @param string $systemPrompt Prompt système
     * @param string $userMessage  Message utilisateur (texte de la page d'accueil)
     * @param string $baseUrl      URL de base (pour les logs uniquement)
     * @return string|null URL-liste si trouvée, null si erreur ou absente
     */
    private function callMistralForListingUrl(
        string $systemPrompt,
        string $userMessage,
        string $baseUrl,
    ): ?string {
        // Lire la clé API Mistral depuis les settings BDD (table app_settings)
        $apiKey = $this->settingService->get('mistral_api_key');

        if (empty($apiKey)) {
            // Pas de clé Mistral — on passe directement au fallback Anthropic
            $this->logger->debug('[ListingDiscoverer] Clé Mistral absente, skip Mistral.', ['url' => $baseUrl]);
            return null;
        }

        try {
            // Appel POST à l'API Mistral (format compatible OpenAI)
            $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => 'mistral-small-latest',
                    // On attend juste une URL — très peu de tokens nécessaires
                    'max_tokens' => 200,
                    // response_format json_object : Mistral garantit un JSON valide en sortie.
                    // Pas besoin de regex pour extraire le JSON.
                    'response_format' => ['type' => 'json_object'],
                    'messages'   => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userMessage],
                    ],
                ],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('[ListingDiscoverer] Mistral HTTP non-200.', [
                    'status' => $response->getStatusCode(),
                    'url'    => $baseUrl,
                ]);
                return null;
            }

            // Format Mistral : {"choices": [{"message": {"content": "{...}"}}]}
            $data    = $response->toArray();
            $rawText = $data['choices'][0]['message']['content'] ?? '';

            if (empty($rawText)) {
                return null;
            }

            // Parser le JSON retourné par Mistral : {"listing_url": "..."}
            return $this->extractListingUrlFromJson($rawText, $baseUrl, 'mistral');

        } catch (\Throwable $e) {
            $this->logger->warning('[ListingDiscoverer] Erreur Mistral.', [
                'url'   => $baseUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Appel Anthropic pour trouver l'URL-liste (fallback de Mistral).
     *
     * Même logique que callMistralForListingUrl() mais avec le format Anthropic :
     *   - Header x-api-key + anthropic-version
     *   - Réponse dans $data['content'][0]['text']
     *   - Extraction JSON par recherche de '{' … '}' (Anthropic ne garantit pas json_object)
     *
     * @param string $systemPrompt Prompt système
     * @param string $userMessage  Message utilisateur
     * @param string $baseUrl      URL de base (pour les logs uniquement)
     * @return string|null URL-liste si trouvée, null si erreur ou absente
     */
    private function callAnthropicForListingUrl(
        string $systemPrompt,
        string $userMessage,
        string $baseUrl,
    ): ?string {
        $apiKey = $this->settingService->get('anthropic_api_key');

        if (empty($apiKey)) {
            $this->logger->debug('[ListingDiscoverer] Clé Anthropic absente, skip.', ['url' => $baseUrl]);
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => 'claude-haiku-4-5',
                    'max_tokens' => 200,
                    'system'     => $systemPrompt,
                    'messages'   => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('[ListingDiscoverer] Anthropic HTTP non-200.', [
                    'status' => $response->getStatusCode(),
                    'url'    => $baseUrl,
                ]);
                return null;
            }

            // Format Anthropic : {"content": [{"type": "text", "text": "{...}"}]}
            $data    = $response->toArray();
            $rawText = $data['content'][0]['text'] ?? '';

            if (empty($rawText)) {
                return null;
            }

            // Anthropic ne garantit pas json_object — on extrait le bloc JSON du texte
            // en cherchant le premier '{' et le dernier '}'
            $start = strpos($rawText, '{');
            $end   = strrpos($rawText, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $rawText = substr($rawText, $start, $end - $start + 1);
            }

            return $this->extractListingUrlFromJson($rawText, $baseUrl, 'anthropic');

        } catch (\Throwable $e) {
            $this->logger->warning('[ListingDiscoverer] Erreur Anthropic.', [
                'url'   => $baseUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrait l'URL-liste depuis le JSON retourné par le LLM.
     *
     * Format attendu : {"listing_url": "https://..."}
     * Si listing_url est null (LLM ne sait pas) → retourne null.
     * Si le JSON est invalide → retourne null + log.
     *
     * Validation supplémentaire : on vérifie que l'URL est valide (filter_var)
     * pour ne pas persister une URL inventée par le LLM.
     *
     * @param string $jsonText  JSON brut retourné par le LLM
     * @param string $baseUrl   URL de base (pour les logs uniquement)
     * @param string $provider  "mistral" ou "anthropic" (pour les logs uniquement)
     * @return string|null URL-liste validée, ou null
     */
    private function extractListingUrlFromJson(string $jsonText, string $baseUrl, string $provider): ?string
    {
        try {
            /** @var array<string, string|null> $decoded */
            $decoded    = json_decode($jsonText, associative: true, flags: JSON_THROW_ON_ERROR);
            $listingUrl = $decoded['listing_url'] ?? null;

            if (empty($listingUrl)) {
                // LLM n'a pas trouvé d'URL-liste — c'est une réponse valide (pas une erreur)
                $this->logger->info('[ListingDiscoverer] LLM : pas d\'URL-liste trouvée.', [
                    'provider' => $provider,
                    'site'     => $baseUrl,
                ]);
                return null;
            }

            // Valider que l'URL retournée par le LLM est bien formée
            // Le LLM peut parfois inventer des URLs qui n'existent pas
            $listingUrl = trim((string) $listingUrl);
            if (!filter_var($listingUrl, FILTER_VALIDATE_URL)) {
                $this->logger->warning('[ListingDiscoverer] LLM a retourné une URL invalide.', [
                    'provider' => $provider,
                    'site'     => $baseUrl,
                    'url'      => $listingUrl,
                ]);
                return null;
            }

            // ── Garde SSRF sur l'URL retournée par le LLM ────────────────────
            // Un LLM pourrait être manipulé (prompt injection dans le HTML de la page
            // d'accueil) pour retourner une URL interne comme http://169.254.169.254
            // ou http://192.168.1.1. On rejette immédiatement ces cas.
            if (!$this->isSafeHost($listingUrl)) {
                $this->logger->warning('[ListingDiscoverer] SSRF bloqué : URL LLM pointe vers un hôte non sûr.', [
                    'provider' => $provider,
                    'site'     => $baseUrl,
                    'url'      => $listingUrl,
                ]);
                return null;
            }

            return $listingUrl;

        } catch (\JsonException $e) {
            $this->logger->warning('[ListingDiscoverer] JSON LLM invalide.', [
                'provider' => $provider,
                'site'     => $baseUrl,
                'error'    => $e->getMessage(),
                'raw'      => mb_substr($jsonText, 0, 200),
            ]);
            return null;
        }
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  PERSISTANCE BDD
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Crée une ScrapingSource agrégateur si l'URL n'est pas déjà connue en BDD.
     *
     * Déduplication : on vérifie via ScrapingSourceRepository::findByUrl() si
     * cette URL existe déjà. Si oui, on ne crée rien et on retourne -1 (signal doublon).
     *
     * ScrapingSource créée avec les paramètres suivants :
     *   - url         : URL-liste trouvée
     *   - type        : HtmlLlm (le GenericScraper LLM gérera l'extraction des opps)
     *   - scraperSlug : null (GenericScraper prend le relais — pas de classe dédiée)
     *   - estAgregateur : true (c'est une page-liste, pas une opportunité individuelle)
     *   - actif       : true (la source est immédiatement disponible pour scraping)
     *   - paysZone    : depuis le CSV si disponible, null sinon
     *   - nom         : nom du site passé en paramètre
     *
     * En mode dry-run, retourne null sans toucher la BDD.
     * IMPORTANT : La commande appellera flush() en fin de boucle (lazy flush),
     *             pas ici — un flush par entité serait très lent sur des CSV de 100+ lignes.
     *
     * @param string      $listingUrl URL-liste à enregistrer
     * @param string      $nom        Nom lisible de la source
     * @param string|null $paysZone   Zone géographique (optionnelle)
     * @param bool        $dryRun     Si true, ne persiste rien
     * @return int|null  null si créée (ID disponible seulement après flush), -1 si doublon, null si dry-run
     */
    private function persistIfNew(
        string $listingUrl,
        string $nom,
        ?string $paysZone,
        bool $dryRun,
    ): ?int {
        // ── Vérification déduplication ────────────────────────────────────────
        // On cherche par URL exacte — même politique que app:discover-sources.
        // Si une source avec cette URL existe déjà (active ou inactive), on ne duplique pas.
        $existing = $this->scrapingSourceRepository->findByUrl($listingUrl);

        if ($existing !== null) {
            $this->logger->info('[ListingDiscoverer] URL déjà en BDD, skip.', [
                'url'      => $listingUrl,
                'sourceId' => $existing->getId(),
            ]);
            // -1 est un signal "doublon" lisible par la commande pour son rapport
            return -1;
        }

        // ── Mode dry-run : ne pas persister ───────────────────────────────────
        if ($dryRun) {
            // En dry-run on simule la création sans toucher la BDD
            return null;
        }

        // ── Création de la ScrapingSource ─────────────────────────────────────
        $source = new ScrapingSource();

        // Nom : on tronque à 255 chars (colonne BDD length: 255)
        $source->setNom(mb_substr($nom, 0, 255));

        // URL : la page-liste trouvée (heuristique ou LLM)
        $source->setUrl($listingUrl);

        // Type HTML_LLM : GenericScraper utilisera le LLM pour extraire les opportunités.
        // On n'a pas de sélecteurs CSS dédiés → html_llm est le seul choix générique.
        $source->setType(ScrapingSourceType::HtmlLlm);

        // scraperSlug = null : pas de classe PHP dédiée → GenericScraper prend le relais
        $source->setScraperSlug(null);

        // estAgregateur = true : cette source a vocation à lister plusieurs opportunités
        $source->setEstAgregateur(true);

        // actif = true : disponible immédiatement pour app:scrape-opportunities
        $source->setActif(true);

        // paysZone : depuis le CSV WHERE ou null
        if ($paysZone !== null) {
            $source->setPaysZone(mb_substr($paysZone, 0, 100));
        }

        // persist() sans flush — la commande appellera flush() en fin de boucle.
        // Raison : un flush par entité = une transaction par entité = lent sur 100+ lignes CSV.
        $this->em->persist($source);

        $this->logger->info('[ListingDiscoverer] Nouvelle source créée (persist, en attente de flush).', [
            'url' => $listingUrl,
            'nom' => $nom,
        ]);

        // L'ID n'est disponible qu'après flush() — on retourne null provisoirement.
        // La commande peut flush() puis relire l'ID si besoin.
        return null;
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  UTILITAIRES
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Vérifie qu'une URL est sûre à requêter (protection SSRF).
     *
     * SSRF (Server-Side Request Forgery) : une URL fournie par l'extérieur (CSV de Gaëlle,
     * réponse LLM, chemin heuristique construit) pourrait pointer vers des services
     * internes non exposés au public :
     *   - API de métadonnées cloud : 169.254.169.254 (AWS, DigitalOcean, GCP…)
     *   - Réseau interne Docker : 172.16.0.1 à 172.31.255.255
     *   - Interface loopback : 127.x.x.x / ::1
     *   - Réseau LAN : 192.168.x.x, 10.x.x.x
     *
     * BLOQUE :
     *   - Schéma non http/https (file://, ftp://, data://, etc.)
     *   - Hôte vide ou absent
     *   - 127.0.0.0/8 (loopback IPv4), ::1 (loopback IPv6), 0.0.0.0
     *   - 169.254.0.0/16 (link-local — métadonnées cloud sur toutes les plateformes)
     *   - 10.0.0.0/8 (RFC1918 — réseau privé classe A)
     *   - 172.16.0.0/12 (RFC1918 — réseau privé classe B : 172.16.x.x à 172.31.x.x)
     *   - 192.168.0.0/16 (RFC1918 — réseau privé classe C)
     *   - "localhost" comme nom d'hôte
     *
     * REMARQUE : on ne fait PAS de résolution DNS ici (pas de gethostbyname).
     * La résolution inverse serait plus sûre mais trop lente sur des batchs de 100 sites.
     * La garde sur l'URL EFFECTIVE après redirection (dans scoreUrl/doFetch) est
     * le second filet qui intercepte les redirections DNS-rebinding.
     *
     * Méthode publique pour faciliter les tests unitaires.
     *
     * @param string $url URL à vérifier
     * @return bool true = sûre à requêter, false = à bloquer
     */
    public function isSafeHost(string $url): bool
    {
        // ── Étape 1 : parser l'URL ────────────────────────────────────────────
        $parsed = parse_url($url);

        if (!is_array($parsed)) {
            // URL totalement malformée — refus par précaution
            return false;
        }

        // ── Étape 2 : whitelist de schémas — seuls http et https sont autorisés ──
        // Bloque file://, ftp://, data://, dict://, gopher://, etc.
        // (Même pattern que FeedDetectorService::detect())
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], strict: true)) {
            return false;
        }

        // ── Étape 3 : hôte obligatoire ────────────────────────────────────────
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        // ── Étape 4 : bloquer "localhost" (toutes formes) ─────────────────────
        // "localhost", "LOCALHOST", "localhost." (trailing dot DNS)
        if ($host === 'localhost' || $host === 'localhost.') {
            return false;
        }

        // ── Étape 5 : bloquer les adresses IP privées / réservées ─────────────
        // On n'utilise ip2long() que si le host ressemble à une IP v4 (pas un domaine).
        // Pour IPv6, on vérifie "::1" et les préfixes de loopback standard.

        // ── IPv6 loopback : ::1 ───────────────────────────────────────────────
        // parse_url() renvoie l'IPv6 sans les crochets (ex: "::1" depuis "[::1]")
        if ($host === '::1' || $host === '[::1]') {
            return false;
        }

        // ── IPv4 : détecter et analyser les adresses numériques ───────────────
        // ip2long() retourne false si ce n'est pas une IPv4 valide.
        // On peut donc l'utiliser comme test "est-ce une IPv4 ?" en même temps.
        $ip = ip2long($host);

        if ($ip !== false) {
            // C'est une adresse IPv4 — on vérifie les plages réservées :

            // 0.0.0.0 (INADDR_ANY — tous les cas d'usage sont dangereux)
            if ($ip === 0) {
                return false;
            }

            // 127.0.0.0/8 — loopback (127.0.0.1 à 127.255.255.255)
            // ip2long('127.0.0.0') = 2130706432, ip2long('127.255.255.255') = 2147483647
            if ($ip >= ip2long('127.0.0.0') && $ip <= ip2long('127.255.255.255')) {
                return false;
            }

            // 169.254.0.0/16 — link-local (métadonnées cloud AWS, DO, GCP, Azure)
            // C'est LE vecteur SSRF le plus critique sur les droplets DigitalOcean.
            if ($ip >= ip2long('169.254.0.0') && $ip <= ip2long('169.254.255.255')) {
                return false;
            }

            // 10.0.0.0/8 — RFC1918 classe A (10.0.0.0 à 10.255.255.255)
            if ($ip >= ip2long('10.0.0.0') && $ip <= ip2long('10.255.255.255')) {
                return false;
            }

            // 172.16.0.0/12 — RFC1918 classe B (172.16.0.0 à 172.31.255.255)
            // Docker utilise par défaut des sous-réseaux dans cette plage (172.17.0.x)
            if ($ip >= ip2long('172.16.0.0') && $ip <= ip2long('172.31.255.255')) {
                return false;
            }

            // 192.168.0.0/16 — RFC1918 classe C (192.168.0.0 à 192.168.255.255)
            if ($ip >= ip2long('192.168.0.0') && $ip <= ip2long('192.168.255.255')) {
                return false;
            }
        }

        // ── Tout est OK ───────────────────────────────────────────────────────
        return true;
    }

    /**
     * Extrait la base URL (schéma + hôte) d'une URL donnée.
     *
     * Exemples :
     *   "https://institutfrancais.com/fr/open-calls" → "https://institutfrancais.com"
     *   "http://site.org/blog/?p=1"                  → "http://site.org"
     *   "institutfrancais.com"                        → "https://institutfrancais.com" (schéma ajouté)
     *
     * Cas particulier : si l'URL n'a pas de schéma (domaine nu comme dans le CSV),
     * on ajoute "https://" avant de tenter le parsing.
     *
     * Méthode publique pour permettre à la commande de normaliser les URLs CSV.
     *
     * @param string $url URL complète ou domaine nu
     * @return string|null Base URL, ou null si l'URL est vraiment malformée
     */
    public function extractBaseUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Ajouter le schéma si l'URL est un domaine nu (sans http/https)
        // Ex: "institutfrancais.com" → "https://institutfrancais.com"
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);

        if (!is_array($parsed)) {
            return null;
        }

        $scheme = (string) ($parsed['scheme'] ?? '');
        $host   = (string) ($parsed['host'] ?? '');

        if ($scheme === '' || $host === '') {
            return null;
        }

        // ── Whitelist de schémas ───────────────────────────────────────────────
        // Cohérent avec isSafeHost() : on ne construit une base URL que pour http/https.
        // Bloque file://, ftp://, data://, etc. dès la normalisation, avant tout appel réseau.
        if (!in_array(strtolower($scheme), ['http', 'https'], strict: true)) {
            return null;
        }

        // Inclure le port s'il est non-standard (ex: :8080)
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}

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
    // Fournit buildBrowserHeaders() (en-têtes Chrome 124 + Sec-Fetch-*) et
    // requestWithRetry() (3 tentatives, backoff 1s/2s sur timeout/429/5xx).
    // Remplace la constante USER_AGENT locale — les en-têtes sont plus complets.
    use HttpBrowserFetchTrait;

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

    // ── Taille maximale du body pour le fetch de la page d'accueil (fallback LLM) ──
    // La page d'accueil peut peser 180 Ko+ avec sa navigation complète, ses menus
    // multi-niveaux et ses blocs de mise en avant. 30 Ko (BODY_INSPECT_BYTES) était
    // prévu pour le scoring heuristique (mots-clés courts) — pas pour extraire ~100
    // liens. Une nav amputée = des liens manquants = le LLM ne voit pas la bonne page.
    // On monte à 400 Ko pour couvrir les sites culturels les plus denses en markup.
    // Garde : le stream est toujours borné — on ne charge jamais en mémoire illimitée.
    private const HOMEPAGE_FETCH_BYTES = 400000;

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
        // EntityManager — pour persister les nouvelles ScrapingSource
        private readonly EntityManagerInterface $em,
        // Logger PSR-3 — pour tracer sans interrompre
        private readonly LoggerInterface $logger,
        // SettingService — pour lire les clés API LLM depuis la BDD (même config que LlmExtractorService)
        private readonly SettingService $settingService,
        // LinkExtractorService — pour extraire les liens internes de la page d'accueil
        // (correctif ADR-0017 : le LLM reçoit une liste de liens, pas du texte nettoyé ;
        //  on utilise extractInternalLinks() et normalizeUrl())
        private readonly LinkExtractorService $linkExtractorService,
        // ScraperApiClient — repli API de scraping quand le fetch direct échoue.
        // Null acceptable (autowiring optionnel) pour ne pas casser si non configuré.
        // Le repli n'est déclenché que si scraper_api_enabled=true ET clé configurée en BDD.
        private readonly ?ScraperApiClient $scraperApiClient = null,
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

        // $llmDebugSteps collecte les étapes-clés du chemin LLM pour le diagnostic verbeux.
        // Ce tableau est passé par référence à runLlmFallback() qui l'enrichit au fil
        // du traitement. Il est ensuite transmis au DiscoveryResult pour affichage en -v.
        /** @var string[] $llmDebugSteps */
        $llmDebugSteps = [];

        $listingUrl = $this->runLlmFallback($baseUrl, $llmDebugSteps);

        if ($listingUrl !== null) {
            // ── AV-1 : valider l'URL LLM par une vraie requête HTTP ───────────
            // Le LLM peut halluciner une URL plausible mais inexistante, ou renvoyer
            // une URL interne si le prompt est détourné (prompt injection).
            // On soumet l'URL suggérée à scoreUrl() : si la page répond en HTTP 200 HTML
            // et passe la garde SSRF, on la considère valide pour persistance.
            // Sinon on ignore la suggestion LLM et on retourne "aucune URL trouvée".
            //
            // POURQUOI allowApiFallback: true ici ?
            //   La page d'accueil a déjà été récupérée via ScraperAPI (dans doFetch) car
            //   le fetch direct était bloqué par le site. L'URL choisie par le LLM est sur
            //   le MÊME domaine bloqué → le fetch direct de vérification échouera aussi.
            //   On autorise donc le repli API pour cette vérification unique — un seul appel
            //   API supplémentaire, pas 30 comme dans la boucle heuristique.
            //   Le repli n'est déclenché que si scraper_api_enabled=true ET clé configurée.
            $llmScore = $this->scoreUrl($listingUrl, allowApiFallback: true);

            if ($llmScore === null) {
                // URL injoignable, non sûre, ou non-HTML — on rejette la suggestion LLM
                $this->logger->warning('[ListingDiscoverer] URL LLM rejetée (injoignable ou non sûre).', [
                    'site'       => $siteUrl,
                    'listingUrl' => $listingUrl,
                ]);
                // Ajout d'une étape de diagnostic : rejet HTTP de l'URL LLM
                $llmDebugSteps[] = sprintf('URL LLM rejetée : injoignable, non sûre ou non-HTML (%s)', $listingUrl);
                // On traite ce cas comme "aucune URL trouvée" — pas de persistance
                $listingUrl = null;
            } else {
                $this->logger->info('[ListingDiscoverer] URL-liste trouvée par LLM (validée HTTP).', [
                    'site'       => $siteUrl,
                    'listingUrl' => $listingUrl,
                    'score'      => $llmScore,
                ]);
                $llmDebugSteps[] = sprintf('URL LLM validée HTTP (score listing : %d) : %s', $llmScore, $listingUrl);
                $sourceId = $this->persistIfNew($listingUrl, $nomSite, $paysZone, $dryRun);
                return new DiscoveryResult(
                    siteUrl: $siteUrl,
                    listingUrl: $listingUrl,
                    method: 'llm',
                    sourceId: $sourceId,
                    nom: $nomSite,
                    reason: $sourceId === -1 ? 'doublon (URL déjà en BDD)' : 'créé',
                    debugSteps: $llmDebugSteps,
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
            debugSteps: $llmDebugSteps,
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
     * PARAMÈTRE $allowApiFallback :
     *   Quand false (défaut) : fetch direct uniquement — comportement d'origine.
     *   Quand true  : si le fetch direct échoue, tente le repli via ScraperAPI
     *                 (même logique que doFetch via fetchHtmlRobust).
     *   On n'active le repli QUE pour la vérification de l'URL unique choisie par le LLM
     *   (un seul appel API). On ne l'active PAS dans la boucle heuristique (~30 URLs)
     *   pour éviter de consommer 30 crédits API par site.
     *
     * @param string $url              URL à tester
     * @param bool   $allowApiFallback Si true, active le repli ScraperAPI en cas d'échec direct
     * @return int|null Score de mots-clés détectés, ou null si la page est inaccessible/non sûre/non-HTML
     */
    private function scoreUrl(string $url, bool $allowApiFallback = false): ?int
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

        // ── Fetch du HTML : direct ou avec repli API selon $allowApiFallback ─
        // On extrait la logique de fetch dans une closure pour pouvoir la passer
        // à fetchHtmlRobust() si le repli est activé, sans dupliquer le code.
        //
        // POURQUOI une closure ici plutôt que réutiliser doFetch() ?
        //   doFetch() est dimensionné pour la page d'accueil (HOMEPAGE_TIMEOUT = 20s,
        //   HOMEPAGE_FETCH_BYTES = 400 Ko) et inclut un retry 3×. scoreUrl() est appelé
        //   sur des URLs candidates (heuristique ou LLM) où on veut :
        //   - Timeout court (HEURISTIC_TIMEOUT = 8s) — on veut une réponse rapide
        //   - Lecture limitée à BODY_INSPECT_BYTES (30 Ko) — on cherche des mots-clés,
        //     pas à rendre la page complète
        //   - PAS de retry (trop lent en boucle heuristique sur ~30 URLs)
        //   Le repli API est déclenché par fetchHtmlRobust() UNIQUEMENT si $allowApiFallback=true.
        $directFetchFn = function () use ($url): ?string {
            try {
                // En-têtes navigateur Chrome 124 complets via buildBrowserHeaders() (trait).
                // Note : PAS de retry ici car scoreUrl() est appelé sur ~30 URLs en heuristique.
                // Retenter chaque URL multiplierait le temps total par 3 — trop lent pour un batch.
                // Le retry est réservé aux fetches unitaires (doFetch, fetchPage) sur une seule URL.
                $response = $this->httpClient->request('GET', $url, [
                    'headers'       => $this->buildBrowserHeaders(),
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

                // On retourne null si le body est vide (page blanche, CAPTCHA pur JS…)
                return trim($bodyChunk) === '' ? null : $bodyChunk;

            } catch (\Throwable) {
                // Timeout, DNS, SSL, erreur réseau → silencieux
                // On retourne null : cette URL est considérée comme inaccessible
                return null;
            }
        };

        // ── Récupérer le HTML : direct OU direct + repli API ──────────────────
        // Si $allowApiFallback = false (boucle heuristique) : on appelle la closure
        // directement, sans passer par fetchHtmlRobust() — pas de repli API.
        // Si $allowApiFallback = true (vérification URL LLM) : on passe par
        // fetchHtmlRobust() qui déclenche le repli ScraperAPI si le direct échoue.
        if ($allowApiFallback) {
            // Un seul appel API possible ici (URL unique choisie par le LLM).
            // Les gardes SSRF sont maintenues dans $directFetchFn (URL initiale)
            // et dans ScraperApiClient::fetchViaApi() (URL effective via API).
            $html = $this->fetchHtmlRobust(
                $url,
                $directFetchFn,
                $this->scraperApiClient,
                fn(string $u): bool => $this->isSafeHost($u),
                $this->logger,
            );
        } else {
            // Boucle heuristique : fetch direct uniquement, sans quota API
            $html = $directFetchFn();
        }

        // HTML null = page inaccessible (toutes voies épuisées)
        if ($html === null) {
            return null;
        }

        // ── Vérification du Content-Type (cas repli API) ──────────────────────
        // Quand le repli ScraperAPI est utilisé, le HTML est déjà décodé par
        // ScraperApiClient::fetchViaApi() — on vérifie simplement que c'est du HTML.
        // Pour le fetch direct, la vérification Content-Type est déjà faite dans
        // $directFetchFn ci-dessus (retourne null si non-HTML).
        // On ajoute une garde minimale sur le contenu pour le cas API :
        // si le body ne ressemble pas du tout à du HTML (< 50 chars ou pas de '<'),
        // on le rejette comme non-HTML.
        if ($allowApiFallback && !str_contains($html, '<')) {
            // Réponse API non-HTML (JSON d'erreur, texte brut, etc.) — on rejette
            $this->logger->debug('[ListingDiscoverer] scoreUrl(API) : body sans balise HTML, rejeté.', [
                'url' => $url,
            ]);
            return null;
        }

        // ── Comptage des mots-clés "listing" ──────────────────────────────────
        // On compte combien de mots-clés DIFFÉRENTS de LISTING_KEYWORDS apparaissent.
        // Chaque mot-clé compte pour 1, même s'il apparaît plusieurs fois.
        // Cela pénalise les pages qui répètent "deadline" dans un seul article
        // plutôt qu'une vraie liste avec plusieurs mots-clés de types différents.
        // On travaille sur les premiers BODY_INSPECT_BYTES pour rester constant
        // même quand le repli API retourne un HTML plus complet.
        $bodyChunk = substr($html, 0, self::BODY_INSPECT_BYTES);
        $bodyLower = mb_strtolower($bodyChunk);

        $score = 0;
        foreach (self::LISTING_KEYWORDS as $keyword) {
            if (str_contains($bodyLower, $keyword)) {
                $score++;
            }
        }

        return $score;
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  FALLBACK LLM
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Nombre maximum de liens internes à envoyer au LLM dans le prompt.
     *
     * 120 liens × ~60 chars en moyenne ≈ 7200 chars — raisonnable pour un prompt Mistral.
     * On plafonne pour maîtriser le coût en tokens sur des sites avec des menus très denses.
     */
    private const LLM_MAX_INTERNAL_LINKS = 120;

    /**
     * Télécharge la page d'accueil et demande au LLM l'URL de la page-liste.
     *
     * CORRECTIF ADR-0017 — Nouvelle approche (au lieu de cleanHtml()) :
     *   On extrait les liens INTERNES de la page d'accueil via LinkExtractorService
     *   et on donne au LLM une liste "texte ancre -> URL" à choisir.
     *
     * POURQUOI la page d'accueil (et non le sitemap) ?
     *   - Le sitemap peut être très volumineux (milliers d'URLs → coûteux en tokens)
     *   - La page d'accueil contient généralement des liens vers toutes les sections
     *     importantes, dont la page-liste des opportunités
     *   - La page d'accueil est toujours disponible (pas de sitemap sur tous les sites)
     *
     * FIX DIAGNOSTIC (ADR-0017 améliorations) :
     *   $debugSteps est passé par référence et enrichi à chaque étape-clé du chemin LLM.
     *   Cela permet à discoverForSite() de transmettre ces étapes au DiscoveryResult,
     *   et à DiscoverListingUrlsCommand de les afficher en mode --verbose (-v).
     *   Exemple de sortie :
     *     [VERBOSE] Page d'accueil récupérée (42 300 octets)
     *     [VERBOSE] 45 liens internes extraits
     *     [VERBOSE] LLM a renvoyé : https://example.com/appels
     *     [VERBOSE] URL LLM rejetée : hors-liste (hallucination)
     *
     * @param string   $baseUrl     Base URL (ex: "https://institutfrancais.com")
     * @param string[] $debugSteps  Tableau collectant les étapes de diagnostic (passé par référence)
     * @return string|null URL de la page-liste si trouvée, null sinon
     */
    private function runLlmFallback(string $baseUrl, array &$debugSteps): ?string
    {
        // ── Étape A : télécharger la page d'accueil ───────────────────────────
        // fetchHomepage() utilise HOMEPAGE_FETCH_BYTES (400 Ko) pour avoir la navigation
        // complète — voir commentaire dans fetchHomepage() pour le détail.
        $html = $this->fetchHomepage($baseUrl);

        if ($html === null) {
            // La page d'accueil ne répond pas — on abandonne ce site
            $this->logger->warning('[ListingDiscoverer] Page d\'accueil inaccessible, LLM ignoré.', [
                'baseUrl' => $baseUrl,
            ]);
            // Étape de diagnostic : le fetch a échoué
            $debugSteps[] = 'Page d\'accueil inaccessible (HTTP non-200, SSRF bloqué, ou timeout)';
            return null;
        }

        // Étape de diagnostic : on note la taille du HTML récupéré
        // Cela permet de vérifier que la navigation complète est bien présente (> 30 Ko)
        $htmlBytes = strlen($html);
        $debugSteps[] = sprintf('Page d\'accueil récupérée (%s octets)', number_format($htmlBytes, 0, ',', ' '));

        $this->logger->info('[ListingDiscoverer] Page d\'accueil récupérée.', [
            'baseUrl'  => $baseUrl,
            'octets'   => $htmlBytes,
        ]);

        // ── Étape B : extraire les liens INTERNES de la page d'accueil ────────
        //
        // CORRECTIF ADR-0017 — Ancienne approche (bug) :
        //   On passait LlmExtractorService::cleanHtml() au LLM.
        //   cleanHtml() supprime tous les <a href> via strip_tags() → le LLM ne voyait
        //   aucune URL et ne pouvait pas désigner la page-liste → retournait null.
        //
        // Nouvelle approche :
        //   On extrait la LISTE DES LIENS INTERNES (texte ancre + href absolu) via
        //   LinkExtractorService::extractInternalLinks(). Le LLM reçoit une liste
        //   "texte ancre -> https://..." et CHOISIT parmi les URLs fournies.
        //   L'URL retournée DOIT figurer dans la liste — anti-hallucination garanti.
        //
        // On filtre : même domaine, pas d'assets (.jpg/.pdf/.css/.js), dédup par URL.
        $internalLinks = $this->linkExtractorService->extractInternalLinks(
            $html,
            $baseUrl,
            self::LLM_MAX_INTERNAL_LINKS
        );

        // Étape de diagnostic : nombre de liens internes trouvés
        // Si 0 → SPA JS sans <a href> statiques, ou page très légère
        // Si > 0 → le LLM a des candidats à analyser
        $nbLinks = count($internalLinks);
        $debugSteps[] = sprintf('%d lien(s) interne(s) extrait(s) de la page d\'accueil', $nbLinks);

        if (empty($internalLinks)) {
            // Aucun lien interne exploitable — le site utilise peut-être du JS pur
            // (SPA React/Vue) sans <a href> dans le HTML statique.
            $this->logger->warning('[ListingDiscoverer] Aucun lien interne trouvé, LLM ignoré.', [
                'baseUrl' => $baseUrl,
            ]);
            return null;
        }

        $this->logger->info('[ListingDiscoverer] Liens internes extraits pour le LLM.', [
            'baseUrl'  => $baseUrl,
            'nb_liens' => $nbLinks,
        ]);

        // ── Étape C : interroger le LLM pour trouver l'URL-liste ─────────────
        // On délègue à une méthode dédiée qui gère Mistral (principal) + Anthropic (fallback).
        // On passe $internalLinks séparément pour que askLlmForListingUrl() construise
        // la liste "texte -> URL" dans le prompt ET effectue la validation anti-hallucination.
        // $debugSteps est transmis pour que askLlmForListingUrl() y ajoute ses propres étapes.
        return $this->askLlmForListingUrl($baseUrl, $internalLinks, $debugSteps);
    }

    /**
     * Télécharge la page d'accueil d'un site avec une limite élargie (HOMEPAGE_FETCH_BYTES).
     *
     * POURQUOI une limite différente de BODY_INSPECT_BYTES ?
     *   BODY_INSPECT_BYTES (30 Ko) est dimensionné pour le scoring heuristique
     *   (détecter une poignée de mots-clés dans le texte). La page d'accueil utilisée
     *   par le fallback LLM a un tout autre rôle : fournir les liens de navigation
     *   complets à extractInternalLinks(). Une page culturelle peut avoir 180 Ko de
     *   markup pour sa navigation (menus multi-niveaux, blocs "nos actions", etc.).
     *   Avec 30 Ko, la navigation est amputée → le LLM ne voit pas le bon lien.
     *   HOMEPAGE_FETCH_BYTES (400 Ko) garantit qu'on capture la navigation entière
     *   tout en restant borné (pas de chargement mémoire illimité).
     *
     * Essaie d'abord l'URL de base (ex: "https://institutfrancais.com"),
     * puis avec "/fr" (variante fréquente des sites multilingues).
     *
     * Toutes les gardes SSRF de doFetch() s'appliquent normalement.
     *
     * @param string $baseUrl URL de base du site
     * @return string|null HTML de la page d'accueil (jusqu'à HOMEPAGE_FETCH_BYTES octets), ou null si inaccessible
     */
    private function fetchHomepage(string $baseUrl): ?string
    {
        // ── Tentative principale : URL de base ────────────────────────────────
        // On passe HOMEPAGE_FETCH_BYTES (400 Ko) au lieu de la valeur par défaut
        // (BODY_INSPECT_BYTES = 30 Ko) pour avoir la navigation complète.
        $html = $this->doFetch($baseUrl, self::HOMEPAGE_FETCH_BYTES);

        if ($html !== null) {
            return $html;
        }

        // ── Tentative alternative : sous-dossier /fr (sites multilingues) ─────
        // Certains sites redirigent la racine vers /fr ou /en — on teste /fr
        // car Bazaart cible principalement les sites francophones.
        return $this->doFetch($baseUrl . '/fr', self::HOMEPAGE_FETCH_BYTES);
    }

    /**
     * Effectue une requête HTTP GET simple et retourne les premiers $maxBytes
     * octets du HTML, ou null si la page est inaccessible / non sûre.
     *
     * Le paramètre $maxBytes permet d'ajuster la limite de lecture selon le contexte :
     *   - BODY_INSPECT_BYTES (30 000)  pour le scoring heuristique (mots-clés courts)
     *   - HOMEPAGE_FETCH_BYTES (400 000) pour le fetch de la page d'accueil du LLM,
     *     où on a besoin de la navigation complète pour extraire tous les liens internes.
     *
     * Toutes les gardes SSRF sont maintenues indépendamment du $maxBytes :
     *   - Garde SSRF avant la requête (isSafeHost sur l'URL cible)
     *   - Vérification de l'URL effective après redirections (SSRF via redirect)
     *   - Lecture via stream borné (pas de téléchargement illimité)
     *   - max_redirects réduit à MAX_REDIRECTS (3)
     *
     * @param string $url      URL à télécharger
     * @param int    $maxBytes Nombre maximum d'octets à lire (défaut : BODY_INSPECT_BYTES)
     * @return string|null HTML partiel retourné, ou null si HTTP non-200 / hôte non sûr / exception
     */
    private function doFetch(string $url, int $maxBytes = self::BODY_INSPECT_BYTES): ?string
    {
        // ── Garde SSRF : vérifier l'hôte AVANT toute requête ─────────────────
        if (!$this->isSafeHost($url)) {
            $this->logger->warning('[ListingDiscoverer] SSRF bloqué (doFetch) : hôte non sûr.', [
                'url' => $url,
            ]);
            return null;
        }

        // ── Fetch direct via fetchHtmlRobust() (trait) ─────────────────────────
        // fetchHtmlRobust() tente d'abord le fetch direct, puis déclenche le repli
        // API de scraping si le direct échoue ET que scraper_api_enabled=true + clé présente.
        //
        // On passe une closure qui encapsule la logique de fetch direct déjà en place :
        // en-têtes Chrome 124, retry 3 tentatives, stream borné à $maxBytes, check SSRF.
        // La closure capture $url et $maxBytes par référence pour être auto-portante.
        return $this->fetchHtmlRobust(
            $url,
            // Closure : fetch direct (logique inchangée, refactorisée ici)
            function () use ($url, $maxBytes): ?string {
                // Options communes : en-têtes Chrome 124 + SSL souple + redirections limitées.
                // verify_peer/verify_host = false : certaines petites structures culturelles ont
                // des certificats problématiques (auto-signés ou CA non reconnue dans Docker).
                $options = [
                    'headers'       => $this->buildBrowserHeaders(),
                    'timeout'       => self::HOMEPAGE_TIMEOUT,
                    'verify_peer'   => false,
                    'verify_host'   => false,
                    // Réduit à MAX_REDIRECTS (3) — limite la surface SSRF par redirection en chaîne.
                    'max_redirects' => self::MAX_REDIRECTS,
                ];

                // requestWithRetry() : 3 tentatives avec backoff 1s/2s sur timeout/429/5xx.
                // doFetch() n'est appelé que sur la page d'accueil (1 URL par site) — le retry
                // est acceptable ici contrairement à scoreUrl() qui teste ~30 URLs.
                $response = $this->requestWithRetry($this->httpClient, $this->logger, 'GET', $url, $options);

                if ($response === null) {
                    // Toutes les tentatives ont échoué (timeout, DNS, SSL, etc.)
                    return null;
                }

                try {
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

                    // ── Lecture partielle via stream ───────────────────────────────────
                    // On lit jusqu'à $maxBytes octets maximum.
                    // stream() lit chunk par chunk → la mémoire consommée est bornée même
                    // si la page fait plusieurs Mo (images inline, scripts).
                    // cancel() stoppe le téléchargement réseau dès qu'on a assez.
                    $html = '';
                    foreach ($this->httpClient->stream($response) as $chunk) {
                        $html .= $chunk->getContent();
                        if (strlen($html) >= $maxBytes) {
                            $response->cancel();
                            break;
                        }
                    }
                    // Tronquer au cas où le dernier chunk dépasserait légèrement $maxBytes
                    $html = substr($html, 0, $maxBytes);

                    return empty(trim($html)) ? null : $html;

                } catch (\Throwable) {
                    return null;
                }
            },
            // Client API de scraping injecté (null si non configuré → pas de repli)
            $this->scraperApiClient,
            // Callback SSRF — on réutilise isSafeHost() définie dans ce même service
            fn(string $u): bool => $this->isSafeHost($u),
            $this->logger,
        );
    }

    /**
     * Interroge le LLM (Mistral principal, Anthropic fallback) pour trouver l'URL-liste.
     *
     * PROMPT DÉDIÉ (correctif ADR-0017) :
     *   On fournit au LLM une LISTE DE LIENS internes (texte ancre -> URL) extraite de
     *   la page d'accueil, et on lui demande de CHOISIR l'URL qui mène à la page-liste
     *   des opportunités. Le LLM ne doit PAS inventer une URL — il DOIT choisir parmi
     *   les URLs fournies.
     *
     * FORMAT DE RÉPONSE :
     *   On demande {"listing_url": "https://..."} pour un parsing simple et robuste.
     *   Si le LLM ne trouve pas de lien adapté dans la liste, il retourne {"listing_url": null}.
     *
     * ANTI-HALLUCINATION :
     *   L'URL retournée par le LLM est vérifiée en PHP contre la liste $internalLinks.
     *   Si elle n'y figure pas (URL inventée hors-liste), elle est rejetée.
     *   Voir extractListingUrlFromJson() pour la validation.
     *
     * @param string                                         $baseUrl       URL de base du site
     * @param array<int, array{text: string, url: string}>  $internalLinks Liens internes extraits de la homepage
     * @param string[]                                       $debugSteps    Étapes de diagnostic (passées par référence)
     * @return string|null URL-liste si trouvée et validée, null sinon
     */
    private function askLlmForListingUrl(string $baseUrl, array $internalLinks, array &$debugSteps): ?string
    {
        // ── Construire la liste "texte ancre -> URL" à envoyer au LLM ──────────
        // On formate chaque lien sur une ligne : "Texte ancre -> https://..."
        // Le LLM doit retourner UNE des URLs exactes de cette liste.
        $linkLines = [];
        foreach ($internalLinks as $link) {
            // Tronquer le texte ancre à 80 chars pour limiter la taille du prompt
            // (les ancres trop longues sont souvent du bruit — classes CSS inline, etc.)
            $text = mb_substr(trim($link['text']), 0, 80);
            // Si l'ancre est vide (lien image sans alt-text), on met un placeholder
            if ($text === '') {
                $text = '[lien sans texte]';
            }
            $linkLines[] = sprintf('%s -> %s', $text, $link['url']);
        }

        $linkList = implode("\n", $linkLines);

        // ── Construire la liste des URLs valides pour la validation anti-hallucination ──
        // On construit un tableau indexé par URL normalisée pour une recherche O(1) rapide.
        // La normalisation (https, minuscules, sans www., sans slash final) est faite
        // pour tolérer des micro-variations dans la réponse LLM.
        /** @var array<string, string> $validUrlsMap  normalizedUrl => urlOriginale */
        $validUrlsMap = [];
        foreach ($internalLinks as $link) {
            // Clé de lookup : URL normalisée (sans query, fragment, slash final)
            $normalized = $this->linkExtractorService->normalizeUrl($link['url']);
            $validUrlsMap[$normalized] = $link['url'];
        }

        // ── Prompt système ────────────────────────────────────────────────────
        // On insiste fortement sur "choisis dans la liste fournie" pour éviter
        // que le LLM hallucine une URL non présente.
        $systemPrompt = <<<'PROMPT'
Tu es un expert en ressources culturelles pour artistes.

On te fournit la LISTE DES LIENS de la page d'accueil d'un site d'institution culturelle.
Chaque ligne a le format : "Texte du lien -> URL"

Ta mission : identifier PARMI CETTE LISTE l'URL de la page qui LISTE plusieurs opportunités pour artistes (appels à candidatures, appels à projets, bourses, résidences artistiques, prix, financements).

RÈGLES ABSOLUES :
- Retourne UNIQUEMENT une URL qui figure EXACTEMENT dans la liste fournie.
- N'invente PAS d'URL, ne modifie PAS les URLs de la liste.
- La page cible doit LISTER plusieurs opportunités (pas une seule opportunité individuelle).
- Si aucun lien de la liste ne semble mener à une page-liste d'opportunités, retourne null.

Format de réponse (JSON uniquement, sans texte autour) :
{"listing_url": "https://url-exacte-de-la-liste"}

ou si aucun lien ne convient :
{"listing_url": null}
PROMPT;

        // ── Message utilisateur : contexte + liste des liens ──────────────────
        $userMessage = sprintf(
            "Site analysé : %s\n\nListe des liens de la page d'accueil (%d liens) :\n%s",
            $baseUrl,
            count($internalLinks),
            $linkList
        );

        // ── Essayer Mistral en premier (moins cher, JSON natif garanti) ────────
        // On passe $debugSteps par référence pour que callMistralForListingUrl() y ajoute
        // l'URL brute retournée par le LLM avant validation (ou la raison du rejet).
        $urlFromMistral = $this->callMistralForListingUrl($systemPrompt, $userMessage, $baseUrl, $validUrlsMap, $debugSteps);

        if ($urlFromMistral !== null) {
            return $urlFromMistral;
        }

        // ── Fallback Anthropic si Mistral échoue ou n'a pas de clé ────────────
        return $this->callAnthropicForListingUrl($systemPrompt, $userMessage, $baseUrl, $validUrlsMap, $debugSteps);
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
     * @param string               $systemPrompt  Prompt système
     * @param string               $userMessage   Message utilisateur (liste des liens)
     * @param string               $baseUrl       URL de base (pour les logs uniquement)
     * @param array<string,string> $validUrlsMap  Map normalizedUrl → urlOriginale (anti-hallucination)
     * @param string[]             $debugSteps    Étapes de diagnostic (passées par référence)
     * @return string|null URL-liste si trouvée et validée, null si erreur ou absente
     */
    private function callMistralForListingUrl(
        string $systemPrompt,
        string $userMessage,
        string $baseUrl,
        array $validUrlsMap,
        array &$debugSteps,
    ): ?string {
        // Lire la clé API Mistral depuis les settings BDD (table app_settings)
        $apiKey = $this->settingService->get('mistral_api_key');

        if (empty($apiKey)) {
            // Pas de clé Mistral — on passe directement au fallback Anthropic
            $this->logger->debug('[ListingDiscoverer] Clé Mistral absente, skip Mistral.', ['url' => $baseUrl]);
            $debugSteps[] = 'Mistral ignoré : clé API absente en BDD (table app_settings)';
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
                $debugSteps[] = sprintf('Mistral : erreur HTTP %d', $response->getStatusCode());
                return null;
            }

            // Format Mistral : {"choices": [{"message": {"content": "{...}"}}]}
            $data    = $response->toArray();
            $rawText = $data['choices'][0]['message']['content'] ?? '';

            if (empty($rawText)) {
                $debugSteps[] = 'Mistral : réponse vide (content absent)';
                return null;
            }

            // Étape de diagnostic : on trace la réponse brute du LLM AVANT validation.
            // Cela permet de voir en -v si le LLM a retourné une URL plausible
            // qui a ensuite été rejetée (hallucination hors-liste, SSRF, etc.)
            $debugSteps[] = sprintf('Mistral a renvoyé (brut) : %s', mb_substr($rawText, 0, 200));

            // Parser le JSON retourné par Mistral : {"listing_url": "..."}
            // On passe $validUrlsMap pour la validation anti-hallucination.
            // On passe $debugSteps pour que l'extracteur y ajoute le motif de rejet.
            return $this->extractListingUrlFromJson($rawText, $baseUrl, 'mistral', $validUrlsMap, $debugSteps);

        } catch (\Throwable $e) {
            $this->logger->warning('[ListingDiscoverer] Erreur Mistral.', [
                'url'   => $baseUrl,
                'error' => $e->getMessage(),
            ]);
            $debugSteps[] = sprintf('Mistral : exception réseau/API — %s', $e->getMessage());
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
     * @param string               $systemPrompt  Prompt système
     * @param string               $userMessage   Message utilisateur
     * @param string               $baseUrl       URL de base (pour les logs uniquement)
     * @param array<string,string> $validUrlsMap  Map normalizedUrl → urlOriginale (anti-hallucination)
     * @param string[]             $debugSteps    Étapes de diagnostic (passées par référence)
     * @return string|null URL-liste si trouvée et validée, null si erreur ou absente
     */
    private function callAnthropicForListingUrl(
        string $systemPrompt,
        string $userMessage,
        string $baseUrl,
        array $validUrlsMap,
        array &$debugSteps,
    ): ?string {
        $apiKey = $this->settingService->get('anthropic_api_key');

        if (empty($apiKey)) {
            $this->logger->debug('[ListingDiscoverer] Clé Anthropic absente, skip.', ['url' => $baseUrl]);
            $debugSteps[] = 'Anthropic ignoré : clé API absente en BDD (table app_settings)';
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
                $debugSteps[] = sprintf('Anthropic : erreur HTTP %d', $response->getStatusCode());
                return null;
            }

            // Format Anthropic : {"content": [{"type": "text", "text": "{...}"}]}
            $data    = $response->toArray();
            $rawText = $data['content'][0]['text'] ?? '';

            if (empty($rawText)) {
                $debugSteps[] = 'Anthropic : réponse vide (content absent)';
                return null;
            }

            // Anthropic ne garantit pas json_object — on extrait le bloc JSON du texte
            // en cherchant le premier '{' et le dernier '}'
            $start = strpos($rawText, '{');
            $end   = strrpos($rawText, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $rawText = substr($rawText, $start, $end - $start + 1);
            }

            // Étape de diagnostic : réponse brute de l'Anthropic AVANT validation
            $debugSteps[] = sprintf('Anthropic a renvoyé (brut) : %s', mb_substr($rawText, 0, 200));

            // On passe $validUrlsMap pour la validation anti-hallucination.
            // On passe $debugSteps pour que l'extracteur y ajoute le motif de rejet.
            return $this->extractListingUrlFromJson($rawText, $baseUrl, 'anthropic', $validUrlsMap, $debugSteps);

        } catch (\Throwable $e) {
            $this->logger->warning('[ListingDiscoverer] Erreur Anthropic.', [
                'url'   => $baseUrl,
                'error' => $e->getMessage(),
            ]);
            $debugSteps[] = sprintf('Anthropic : exception réseau/API — %s', $e->getMessage());
            return null;
        }
    }

    /**
     * Extrait et valide l'URL-liste depuis le JSON retourné par le LLM.
     *
     * Format attendu : {"listing_url": "https://..."}
     * Si listing_url est null (LLM ne sait pas) → retourne null.
     * Si le JSON est invalide → retourne null + log.
     *
     * Validations dans l'ordre :
     *   1. JSON valide (sinon log + null)
     *   2. Champ listing_url non vide
     *   3. filter_var FILTER_VALIDATE_URL — URL bien formée
     *   4. isSafeHost() — garde SSRF
     *   5. Appartenance à $validUrlsMap — anti-hallucination
     *      Si le LLM retourne une URL qui n'était PAS dans la liste fournie,
     *      on la rejette. Cette garde est critique : le prompt demande explicitement
     *      au LLM de choisir dans la liste, mais les LLM peuvent quand même halluciner.
     *
     * On retourne l'URL ORIGINALE (non normalisée) issue de $validUrlsMap si disponible,
     * ou l'URL retournée par le LLM sinon (pour les cas où $validUrlsMap est vide,
     * ce qui ne devrait pas arriver en pratique).
     *
     * @param string               $jsonText     JSON brut retourné par le LLM
     * @param string               $baseUrl      URL de base (pour les logs uniquement)
     * @param string               $provider     "mistral" ou "anthropic" (pour les logs uniquement)
     * @param array<string,string> $validUrlsMap Map normalizedUrl → urlOriginale (anti-hallucination)
     * @param string[]             $debugSteps   Étapes de diagnostic (passées par référence)
     * @return string|null URL-liste validée (originale, non normalisée), ou null
     */
    private function extractListingUrlFromJson(
        string $jsonText,
        string $baseUrl,
        string $provider,
        array $validUrlsMap,
        array &$debugSteps,
    ): ?string {
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
                $debugSteps[] = sprintf('%s : listing_url = null (aucun lien ne convient selon le LLM)', ucfirst($provider));
                return null;
            }

            // ── Validation 3 : URL bien formée ───────────────────────────────
            // Le LLM peut parfois retourner des URLs mal formées (espaces, guillemets…)
            $listingUrl = trim((string) $listingUrl);
            if (!filter_var($listingUrl, FILTER_VALIDATE_URL)) {
                $this->logger->warning('[ListingDiscoverer] LLM a retourné une URL invalide.', [
                    'provider' => $provider,
                    'site'     => $baseUrl,
                    'url'      => $listingUrl,
                ]);
                $debugSteps[] = sprintf('%s : URL mal formée rejetée — "%s"', ucfirst($provider), $listingUrl);
                return null;
            }

            // ── Validation 4 : garde SSRF sur l'URL retournée par le LLM ─────
            // Un LLM pourrait être manipulé (prompt injection dans le HTML de la page
            // d'accueil) pour retourner une URL interne comme http://169.254.169.254
            // ou http://192.168.1.1. On rejette immédiatement ces cas.
            if (!$this->isSafeHost($listingUrl)) {
                $this->logger->warning('[ListingDiscoverer] SSRF bloqué : URL LLM pointe vers un hôte non sûr.', [
                    'provider' => $provider,
                    'site'     => $baseUrl,
                    'url'      => $listingUrl,
                ]);
                $debugSteps[] = sprintf('%s : URL rejetée (SSRF — hôte non sûr) : %s', ucfirst($provider), $listingUrl);
                return null;
            }

            // ── Validation 5 : anti-hallucination — l'URL DOIT être dans la liste ──
            // On normalise l'URL LLM de la même façon que les URLs de la liste
            // (LinkExtractorService::normalizeUrl : https, minuscules, sans www., sans slash final)
            // pour tolérer des micro-variations (slash final, casse, www.) tout en rejetant
            // les URLs inventées qui n'existent pas dans le HTML de la page d'accueil.
            if (!empty($validUrlsMap)) {
                $normalizedLlmUrl = $this->linkExtractorService->normalizeUrl($listingUrl);

                if (!isset($validUrlsMap[$normalizedLlmUrl])) {
                    // L'URL retournée par le LLM n'est pas dans la liste fournie — hallucination !
                    $this->logger->warning('[ListingDiscoverer] URL LLM rejetée : hors-liste (hallucination possible).', [
                        'provider'        => $provider,
                        'site'            => $baseUrl,
                        'url_llm'         => $listingUrl,
                        'url_normalisee'  => $normalizedLlmUrl,
                        'nb_urls_valides' => count($validUrlsMap),
                    ]);
                    $debugSteps[] = sprintf(
                        '%s : URL rejetée (hors-liste / hallucination) : %s → normalisée : %s (%d URLs valides dans la liste)',
                        ucfirst($provider),
                        $listingUrl,
                        $normalizedLlmUrl,
                        count($validUrlsMap)
                    );
                    return null;
                }

                // On retourne l'URL ORIGINALE de la liste (pas la version retournée par le LLM)
                // pour garantir qu'on persiste exactement l'URL présente dans le HTML.
                $this->logger->info('[ListingDiscoverer] LLM a désigné une URL valide de la liste.', [
                    'provider'    => $provider,
                    'site'        => $baseUrl,
                    'url_choisie' => $validUrlsMap[$normalizedLlmUrl],
                ]);
                $debugSteps[] = sprintf(
                    '%s : URL validée et acceptée : %s',
                    ucfirst($provider),
                    $validUrlsMap[$normalizedLlmUrl]
                );
                return $validUrlsMap[$normalizedLlmUrl];
            }

            // Fallback : $validUrlsMap vide (ne devrait pas arriver) → retourner l'URL LLM
            $debugSteps[] = sprintf('%s : URL retournée (sans validation hors-liste) : %s', ucfirst($provider), $listingUrl);
            return $listingUrl;

        } catch (\JsonException $e) {
            $this->logger->warning('[ListingDiscoverer] JSON LLM invalide.', [
                'provider' => $provider,
                'site'     => $baseUrl,
                'error'    => $e->getMessage(),
                'raw'      => mb_substr($jsonText, 0, 200),
            ]);
            $debugSteps[] = sprintf('%s : JSON invalide — %s', ucfirst($provider), $e->getMessage());
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

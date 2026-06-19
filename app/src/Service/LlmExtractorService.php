<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;
use App\Repository\DisciplineRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LlmExtractorService — Extrait des opportunités artistiques via Claude Haiku (Anthropic).
 *
 * Ce service est utilisé par les scrapers qui font face à des pages HTML sans flux RSS
 * ni API JSON. Au lieu d'écrire des sélecteurs CSS fragiles (qui cassent dès que
 * le site change sa structure), on envoie le contenu texte au LLM qui l'interprète.
 *
 * Flux de traitement :
 *   1. Récupère la clé API depuis les settings BDD (configurable depuis /admin/settings)
 *   2. Nettoie le HTML (supprime les balises, normalise les espaces)
 *   3. Envoie le texte à claude-haiku-4-5 via l'API Anthropic
 *   4. Parse la réponse JSON et crée des ScrapedOpportunity
 *
 * IMPORTANT : cette classe ne lève JAMAIS d'exception.
 *   → En cas d'erreur (clé manquante, timeout API, JSON invalide), elle retourne []
 *   → Le scraper appelant affichera un warning mais ne plantera pas
 *
 * Pourquoi Haiku plutôt qu'un modèle plus puissant ?
 *   → Haiku est 10× moins cher que Sonnet pour ce type de tâche structurée
 *   → Extraction JSON → pas besoin d'un modèle de raisonnement complexe
 *   → Vitesse : Haiku répond en ~1 seconde, Sonnet en ~5 secondes
 */
class LlmExtractorService
{
    /**
     * URL de l'API Anthropic pour les messages (chat completion).
     * Constante pour faciliter les tests mock.
     */
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * URL de l'API Mistral (compatible OpenAI — même format que chat completions).
     */
    private const MISTRAL_API_URL = 'https://api.mistral.ai/v1/chat/completions';

    /**
     * Modèle Mistral utilisé.
     * Mistral Small 3.2 est le modèle le plus économique avec support response_format.
     * Avantage clé : json_object natif → pas de regex pour extraire le JSON.
     */
    private const MISTRAL_MODEL = 'mistral-small-latest';

    /**
     * Version API à envoyer dans le header (obligatoire pour Anthropic).
     */
    private const API_VERSION = '2023-06-01';

    /**
     * Modèle utilisé — claude-haiku-4-5 est le moins cher et suffisamment précis
     * pour l'extraction structurée de données.
     */
    private const MODEL = 'claude-haiku-4-5';

    /**
     * Nombre maximum de tokens en réponse.
     * ADR-0018 : on monte à 3 500 tokens pour accueillir les 3 nouveaux champs
     * (howToApply peut être verbeux) sans tronquer les opportunités en bout de liste.
     */
    private const MAX_TOKENS = 3500;

    /**
     * Taille maximale du texte envoyé au LLM (en caractères).
     * Limite : claude-haiku supporte ~190k tokens = ~800k chars.
     * On reste à 12000 chars pour maîtriser les coûts.
     */
    private const MAX_TEXT_LENGTH = 12000;

    /**
     * Cache de la liste des disciplines pour le prompt LLM.
     *
     * Problème résolu :
     *   buildDisciplinesListForPrompt() fait une requête BDD (findAllOrdered) à chaque
     *   appel. Dans une commande traitant 10 sources, cette méthode était appelée
     *   10 fois → 10 SELECT identiques sur la table disciplines pour retourner
     *   toujours le même résultat (les disciplines ne changent pas pendant l'exécution).
     *
     * Solution :
     *   On mémorise le résultat dans cette propriété au premier appel.
     *   Les appels suivants retournent directement la valeur mise en cache.
     *   null = "pas encore calculé" (lazy init).
     *   '' est une valeur valide (BDD vide de disciplines) → on ne peut pas utiliser
     *   ?? '' comme sentinelle, d'où le type ?string.
     */
    private ?string $disciplinesListCache = null;

    public function __construct(
        // Client HTTP Symfony (symfony/http-client) — injecté automatiquement par autowiring
        private readonly HttpClientInterface $httpClient,
        // Service de paramètres — pour lire la clé API depuis la BDD
        private readonly SettingService $settingService,
        // Logger PSR-3 — pour tracer les erreurs sans lever d'exception
        private readonly LoggerInterface $logger,
        // Repository Discipline — pour passer la liste des disciplines au LLM (ADR-0016 Lot 1)
        // Utilisé dans buildDisciplinesListForPrompt() pour contraindre les choix du LLM.
        private readonly DisciplineRepository $disciplineRepository,
    ) {
    }

    /**
     * Identifie des organismes culturels candidats à partir d'une liste de liens pré-filtrés.
     *
     * CHANGEMENT DE SIGNATURE (refonte découverte de sources) :
     *   AVANT : discoverSources(string $html, string $pageUrl)
     *     → envoyait 30 000 chars de HTML brut au LLM (coûteux)
     *   APRÈS : discoverSources(array $candidates, string $pageUrl)
     *     → reçoit une liste compacte de ~50 candidats pré-filtrés par LinkExtractorService
     *     → économie estimée : ~95% des tokens sur cette méthode
     *
     * Cette méthode est intentionnellement SÉPARÉE de extractFromHtml() :
     *   - Prompt différent : chercher des SOURCES, pas des OPPORTUNITÉS
     *   - Format de réponse différent : {"sources": [...]} vs tableau direct
     *   - Utilisée UNIQUEMENT par DiscoverSourcesCommand — jamais par les scrapers
     *
     * Isolation absolue :
     *   Cette méthode ne touche PAS à ScrapedResource, ne persiste RIEN en BDD.
     *   Elle retourne un tableau de données brutes que la commande transforme en SuggestedSource.
     *
     * Comportement en cas d'erreur :
     *   Retourne [] sans lever d'exception (même politique que extractFromHtml).
     *   La commande peut continuer sur les autres agrégateurs.
     *
     * @param array<int, array{text: string, url: string}> $candidates Candidats pré-filtrés
     *        (retournés par LinkExtractorService::extractAndFilter())
     * @param string $pageUrl URL de la page analysée (transmise au LLM pour contexte)
     * @return array<int, array{nom: string, url: string|null, pays_zone: string|null, discipline: string|null, raison: string|null}>
     */
    public function discoverSources(array $candidates, string $pageUrl): array
    {
        // ── Étape 1 : vérifier que la liste de candidats n'est pas vide ──────
        // Si LinkExtractorService n'a trouvé aucun candidat après filtrage PHP,
        // inutile d'appeler le LLM — on retourne directement un tableau vide.
        if (empty($candidates)) {
            $this->logger->info(
                '[LlmExtractor] discoverSources : aucun candidat après filtrage PHP.',
                ['url' => $pageUrl]
            );
            return [];
        }

        // ── Étape 2 : lire le provider LLM configuré ─────────────────────────
        // Même logique que extractFromHtml() — on respecte le choix admin.
        $provider = $this->settingService->get('llm_provider', 'mistral');

        // ── Étape 3 : construire le message utilisateur compact ───────────────
        // Format lisible par le LLM : "Candidat N : "Texte ancre" → https://..."
        // Beaucoup plus compact que 30 000 chars de HTML brut.
        $lines = [];
        foreach ($candidates as $i => $candidate) {
            $lines[] = sprintf(
                'Candidat %d : "%s" → %s',
                $i + 1,
                $candidate['text'],
                $candidate['url']
            );
        }
        $candidatesList = implode("\n", $lines);

        $userMessage = sprintf(
            "Page analysée : %s\n\nCandidats pré-filtrés (%d liens) :\n%s",
            $pageUrl,
            count($candidates),
            $candidatesList
        );

        // ── Étape 4 : prompt système dédié à la découverte de sources ─────────
        // CE PROMPT EST DIFFÉRENT DE extractFromHtml() :
        //   - extractFromHtml : "trouve des opportunités sur cette page"
        //   - discoverSources : "trouve des ORGANISMES qui publient des opportunités"
        //
        // Points spécifiques à ce prompt (vs l'ancien) :
        //   - Adapté pour recevoir une LISTE DE LIENS (pas du HTML brut)
        //   - Sélection large incluant les organismes européens généralistes
        //   - Signalement explicite des organismes Afro / Diaspora / Suds (atout, pas filtre)
        $systemPrompt = <<<'PROMPT'
Tu es un expert en ressources culturelles pour artistes.

On t'envoie une liste de liens pré-extraits d'une page d'agrégateur culturel.
Ta mission : identifier parmi ces liens les organismes, fondations, institutions ou réseaux
qui pourraient avoir LEURS PROPRES opportunités pour des artistes : aides financières,
subventions, bourses de création, résidences d'artistes, appels à projets, appels à candidatures,
prix artistiques, programmes de mentorat ou tutorat, accompagnement professionnel,
formation artistique.

Ne liste PAS les opportunités elles-mêmes — liste les SOURCES qui en publient.
La sélection doit être large : inclus les fondations, fonds et programmes européens généralistes.
Signale dans la raison quand un organisme cible particulièrement la diaspora africaine ou caribéenne,
l'outre-mer ou les artistes des Suds — c'est un atout à mentionner, jamais un critère d'exclusion.

Retourne un objet JSON avec une clé "sources" contenant un tableau d'objets avec ces champs :
- "nom" : string — nom de l'organisme
- "url" : string ou null — URL du site de l'organisme (reprends l'URL du candidat si pertinente)
- "pays_zone" : string ou null — pays ou zone géographique (ex: "France", "Europe", "International")
- "discipline" : string ou null — discipline artistique principale (ex: "Arts plastiques", "Pluridisciplinaire")
- "raison" : string — en 1-2 phrases, pourquoi cet organisme pourrait avoir ses propres opportunités

Ne retourne que des organismes dont tu as une bonne confiance qu'ils publient des opportunités pour artistes.
Maximum 20 organismes par réponse.
PROMPT;

        try {
            // ── Étape 5 : appel au provider LLM choisi ────────────────────────
            if ($provider === 'mistral') {
                return $this->callMistralApiForDiscovery($systemPrompt, $userMessage, $pageUrl);
            }

            // Fallback Anthropic
            $apiKey = $this->settingService->get('anthropic_api_key');
            if (empty($apiKey)) {
                $this->logger->warning(
                    '[LlmExtractor] discoverSources : Clé API Anthropic non configurée.',
                    ['url' => $pageUrl]
                );
                return [];
            }

            return $this->callAnthropicApiForDiscovery($apiKey, $systemPrompt, $userMessage, $pageUrl);

        } catch (\Exception $e) {
            // On log l'erreur mais on ne la propage jamais
            $this->logger->error(
                '[LlmExtractor] discoverSources : Erreur lors de l\'appel LLM.',
                [
                    'url'       => $pageUrl,
                    'provider'  => $provider,
                    'exception' => $e->getMessage(),
                ]
            );
            return [];
        }
    }

    /**
     * Appel Mistral spécifique à discoverSources.
     *
     * Différence avec callMistralApi() :
     *   - Le prompt est différent (découverte de sources, pas d'opportunités)
     *   - Le format de réponse attendu est {"sources": [...]} au lieu de {"opportunites": [...]}
     *   - Le max_tokens est augmenté à 4000 (listes d'organismes plus longues)
     *
     * @param string $systemPrompt Prompt système
     * @param string $userMessage  Message utilisateur (contient le HTML tronqué)
     * @param string $pageUrl      URL source (pour les logs uniquement)
     * @return array<int, array{nom: string, url: string|null, pays_zone: string|null, discipline: string|null, raison: string|null}>
     * @throws \Exception En cas d'erreur HTTP (capturée par discoverSources)
     */
    private function callMistralApiForDiscovery(
        string $systemPrompt,
        string $userMessage,
        string $pageUrl,
    ): array {
        $apiKey = $this->settingService->get('mistral_api_key');

        if (empty($apiKey)) {
            $this->logger->warning(
                '[LlmExtractor] discoverSources : Clé API Mistral non configurée.',
                ['url' => $pageUrl]
            );
            return [];
        }

        // Plus de tokens que pour l'extraction d'opportunités : les listes d'organismes
        // peuvent être très longues (20 organismes × description = ~4000 tokens)
        $response = $this->httpClient->request('POST', self::MISTRAL_API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'           => self::MISTRAL_MODEL,
                'max_tokens'      => 4000,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMessage],
                ],
            ],
            'timeout' => 60,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(
                sprintf('[discoverSources] API Mistral a retourné le code HTTP %d.', $statusCode)
            );
        }

        $data    = $response->toArray();
        $rawText = $data['choices'][0]['message']['content'] ?? '';

        if (empty($rawText)) {
            $this->logger->warning('[LlmExtractor] discoverSources : Réponse Mistral vide.', ['url' => $pageUrl]);
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawText, associative: true, flags: JSON_THROW_ON_ERROR);
            // Le LLM retourne {"sources": [...]} — on extrait le tableau "sources"
            /** @var array<int, array<string, string|null>> $items */
            $items = $decoded['sources'] ?? [];
        } catch (\JsonException $e) {
            $this->logger->warning('[LlmExtractor] discoverSources : JSON Mistral invalide.', [
                'url'   => $pageUrl,
                'error' => $e->getMessage(),
                'raw'   => mb_substr($rawText, 0, 500),
            ]);
            return [];
        }

        return $this->mapItemsToSources($items);
    }

    /**
     * Appel Anthropic spécifique à discoverSources.
     *
     * Différence avec callAnthropicApi() :
     *   - Le prompt est différent (découverte de sources)
     *   - Le format de réponse attendu est {"sources": [...]}
     *
     * @param string $apiKey       Clé API Anthropic
     * @param string $systemPrompt Prompt système
     * @param string $userMessage  Message utilisateur (contient le HTML tronqué)
     * @param string $pageUrl      URL source (pour les logs uniquement)
     * @return array<int, array{nom: string, url: string|null, pays_zone: string|null, discipline: string|null, raison: string|null}>
     * @throws \Exception En cas d'erreur HTTP (capturée par discoverSources)
     */
    private function callAnthropicApiForDiscovery(
        string $apiKey,
        string $systemPrompt,
        string $userMessage,
        string $pageUrl,
    ): array {
        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'      => self::MODEL,
                'max_tokens' => 4000,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ],
            'timeout' => 60,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(
                sprintf('[discoverSources] API Anthropic a retourné le code HTTP %d.', $statusCode)
            );
        }

        $responseData = $response->toArray();
        $rawText      = $responseData['content'][0]['text'] ?? '';

        if (empty($rawText)) {
            $this->logger->warning('[LlmExtractor] discoverSources : Réponse Anthropic vide.', ['url' => $pageUrl]);
            return [];
        }

        // Anthropic ne garantit pas un JSON object — on extrait le bloc JSON du texte
        // En cherchant le premier '{' (car on attend un objet {"sources": [...]})
        $start = strpos($rawText, '{');
        $end   = strrpos($rawText, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $rawText = substr($rawText, $start, $end - $start + 1);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawText, associative: true, flags: JSON_THROW_ON_ERROR);
            /** @var array<int, array<string, string|null>> $items */
            $items = $decoded['sources'] ?? [];
        } catch (\JsonException $e) {
            $this->logger->warning('[LlmExtractor] discoverSources : JSON Anthropic invalide.', [
                'url'   => $pageUrl,
                'error' => $e->getMessage(),
                'raw'   => mb_substr($rawText, 0, 500),
            ]);
            return [];
        }

        return $this->mapItemsToSources($items);
    }

    /**
     * Convertit les items JSON retournés par le LLM en tableau normalisé de sources.
     *
     * Normalisation des champs :
     *   "nom"       → string obligatoire (items sans nom sont ignorés)
     *   "url"       → string ou null
     *   "pays_zone" → string ou null
     *   "discipline"→ string ou null
     *   "raison"    → string ou null
     *
     * Les URLs malformées ou vides sont normalisées à null pour que
     * DiscoverSourcesCommand puisse les filtrer facilement.
     *
     * @param array<int, array<string, mixed>> $items Items bruts du LLM
     * @return array<int, array{nom: string, url: string|null, pays_zone: string|null, discipline: string|null, raison: string|null}>
     */
    private function mapItemsToSources(array $items): array
    {
        $sources = [];

        foreach ($items as $item) {
            // Un nom est obligatoire — on ignore les items sans nom
            $nom = trim((string) ($item['nom'] ?? ''));
            if (empty($nom)) {
                continue;
            }

            // Normalisation de l'URL :
            //   - Si vide ou non valide → null (sera filtré dans DiscoverSourcesCommand)
            //   - La validation filter_var est permissive : on garde les URLs avec sous-domaines
            //   - On supprime le slash final pour éviter les doublons (ex: "example.com/" vs "example.com")
            $url = rtrim(trim((string) ($item['url'] ?? '')), '/');
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                $url = null;
            }

            $sources[] = [
                'nom'       => $nom,
                'url'       => $url, // null si URL vide/invalide (assigné au bloc if ci-dessus)
                'pays_zone' => trim((string) ($item['pays_zone'] ?? '')) ?: null,
                'discipline'=> trim((string) ($item['discipline'] ?? '')) ?: null,
                'raison'    => trim((string) ($item['raison'] ?? '')) ?: null,
            ];
        }

        return $sources;
    }

    /**
     * Teste la connexion à l'API Anthropic avec la clé configurée en BDD.
     *
     * Envoie un message minimaliste (max_tokens: 5) pour vérifier que la clé est valide
     * sans consommer de quota inutilement. Retourne un tableau normalisé.
     *
     * Cette méthode ne lève jamais d'exception : toutes les erreurs réseau ou API
     * sont capturées et retournées sous forme de message lisible.
     *
     * Raison d'être ici et non dans le controller :
     *   La logique de construction des headers Anthropic, de l'interprétation des
     *   codes HTTP 401/429, etc., est de la logique métier liée à l'intégration API.
     *   Le controller doit rester un simple orchestrateur (CLAUDE.md §4).
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        // Lire la clé API depuis les settings BDD (configurable dans /admin/settings)
        $apiKey = $this->settingService->get('anthropic_api_key');

        if (empty($apiKey)) {
            return [
                'ok'      => false,
                'message' => 'Aucune clé API configurée dans les paramètres.',
            ];
        }

        try {
            // Requête minimaliste : "ping" avec 5 tokens max pour limiter le coût
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => self::MODEL,
                    'max_tokens' => 5,
                    'messages'   => [
                        ['role' => 'user', 'content' => 'ping'],
                    ],
                ],
                // Timeout court : c'est juste un test de connectivité
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            // HTTP 200 : la clé est valide et l'API répond correctement
            if ($statusCode === 200) {
                return [
                    'ok'      => true,
                    'message' => 'Connexion Anthropic OK — clé valide.',
                ];
            }

            // HTTP 401 : clé invalide, révoquée, ou typo dans la valeur
            if ($statusCode === 401) {
                return [
                    'ok'      => false,
                    'message' => 'Clé API invalide ou expirée (HTTP 401).',
                ];
            }

            // HTTP 429 : quota épuisé mais la clé elle-même est valide
            if ($statusCode === 429) {
                return [
                    'ok'      => false,
                    'message' => 'Limite de requêtes dépassée (HTTP 429) — clé valide mais quota atteint.',
                ];
            }

            // Autre code inattendu
            return [
                'ok'      => false,
                'message' => sprintf('Réponse inattendue de l\'API Anthropic (HTTP %d).', $statusCode),
            ];

        } catch (\Exception $e) {
            // Ne pas exposer le message brut de l'exception (peut contenir des détails internes)
            // Loguer quand même pour debug serveur
            $this->logger->warning(
                '[LlmExtractor] Erreur réseau lors du test de connexion Anthropic.',
                ['exception' => $e->getMessage()]
            );

            return [
                'ok'      => false,
                'message' => 'Erreur réseau lors du test : impossible de joindre l\'API Anthropic.',
            ];
        }
    }

    /**
     * Supprime les tirets cadratins (—, U+2014) et demi-cadratins (–, U+2013) d'un texte généré par LLM.
     *
     * RÈGLE ÉDITORIALE DE GAËLLE : ces caractères sont interdits dans tous les titres
     * et contenus du site (ils viennent systématiquement du LLM malgré les consignes).
     * Cette méthode est le "filet de sécurité" PHP : même si le LLM ignore la consigne,
     * aucun cadratin ne passe en BDD.
     *
     * LOGIQUE DE REMPLACEMENT :
     *   1. "A — B" ou "A – B" (entourés d'espaces) → "A : B" (deux-points)
     *      Raison : dans les titres ("Résidence de création — Institut français"),
     *      le cadratin sépare deux parties — le deux-points est plus naturel en français.
     *   2. Tout cadratin résiduel (sans espaces autour) → "-" (tiret simple ASCII)
     *      Ex: "A—B" → "A-B"
     *   3. Collapse des espaces multiples éventuels (ex: deux espaces consécutifs après remplacement)
     *
     * Champs concernés : title, description, howToApply, fundingAmount, fundingType
     * (tous les champs texte produits par le LLM).
     *
     * @param string $text Texte à nettoyer (peut être vide)
     * @return string Texte nettoyé de tout tiret cadratin/demi-cadratin
     */
    private function stripEmDashes(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Étape 1 : remplacement des cadratins ENTOURÉS D'ESPACES par " : "
        // Pattern : espace + (— ou –) + espace → deux-points
        // On utilise \x{2014} (cadratin) et \x{2013} (demi-cadratin) en notation Unicode.
        $text = (string) preg_replace('/\s[—–]\s/u', ' : ', $text);

        // Étape 2 : remplacement des cadratins RÉSIDUELS (sans espaces) par un tiret simple
        // Ex: "A—B" → "A-B", "Résidence—Paris" → "Résidence-Paris"
        $text = str_replace(['—', '–'], '-', $text);

        // Étape 3 : normaliser les espaces multiples qui auraient pu apparaître
        // (ex: " : " produit parfois " :  " si le texte original avait des espaces doubles)
        $text = (string) preg_replace('/  +/', ' ', $text);

        return trim($text);
    }

    /**
     * Extrait les opportunités artistiques d'un contenu HTML.
     *
     * Retourne un tableau de ScrapedOpportunity[] (peut être vide en cas d'erreur).
     * Ne lève JAMAIS d'exception — log l'erreur et retourne [].
     *
     * NOUVEAU : choisit le provider LLM selon le paramètre BDD 'llm_provider' :
     *   - 'mistral'   → callMistralApi() (Mistral Small 3.2, response_format json natif)
     *   - 'anthropic' → callAnthropicApi() (Claude Haiku, comportement historique)
     *
     * ADR-0019 : les liens de la page HTML brute sont extraits AVANT le nettoyage
     * et passés au LLM pour permettre l'extraction d'applicationUrl sans hallucination.
     *
     * @param string $htmlContent Contenu HTML brut de la page (sera nettoyé en interne)
     * @param string $sourceUrl   URL source de la page (pour fallback si LLM ne trouve pas l'URL)
     * @param string $sourceSite  Nom du site (ex: "on-the-move.org") — champ source du DTO
     * @return ScrapedOpportunity[] Liste des opportunités extraites (vide si aucune ou erreur)
     */
    public function extractFromHtml(
        string $htmlContent,
        string $sourceUrl,
        string $sourceSite,
    ): array {
        // ── Étape 1 : lire le provider LLM configuré en BDD ───────────────────
        // L'admin peut choisir 'mistral' (recommandé) ou 'anthropic' (fallback) depuis /admin/settings.
        // Valeur par défaut : 'mistral' (Mistral Small 3.2 — JSON natif, moins cher).
        $provider = $this->settingService->get('llm_provider', 'mistral');

        // ── Étape 1bis : extraire les liens de la page AVANT le nettoyage HTML ─
        // ADR-0019 : la garde anti-hallucination pour applicationUrl exige que l'on
        // fournisse au LLM la liste des liens réels de la page.
        // On extrait les liens depuis le HTML BRUT (avant strip_tags) pour avoir
        // accès aux attributs href — ils seraient perdus après le nettoyage.
        // extractPageLinksForLlm() retourne une string formatée "- url1\n- url2\n..."
        // (max 100 URLs pour limiter les tokens).
        $pageLinksContext = $this->extractPageLinksForLlm($htmlContent, $sourceUrl);

        // ── Étape 2 : nettoyer le HTML ─────────────────────────────────────────
        // Supprime les balises HTML, les blocs nav/header/footer, et normalise les espaces.
        // Le LLM n'a besoin que du texte brut — le HTML brut gaspillerait des tokens.
        $cleanText = $this->cleanHtml($htmlContent);

        if (empty($cleanText)) {
            $this->logger->warning(
                '[LlmExtractor] Texte vide après nettoyage du HTML.',
                ['source' => $sourceSite, 'url' => $sourceUrl]
            );
            return [];
        }

        // ── Étape 3 : appel au provider LLM choisi ────────────────────────────
        try {
            if ($provider === 'mistral') {
                // Mistral : JSON object natif, pas de regex pour extraire le JSON
                return $this->callMistralApi($cleanText, $sourceUrl, $sourceSite, $pageLinksContext);
            }

            // Fallback Anthropic (comportement historique — conservé intégralement)
            $apiKey = $this->settingService->get('anthropic_api_key');
            if (empty($apiKey)) {
                $this->logger->warning(
                    '[LlmExtractor] Clé API Anthropic non configurée. '
                    . 'Rendez-vous sur /admin/settings pour la renseigner.',
                    ['source' => $sourceSite]
                );
                return [];
            }

            return $this->callAnthropicApi($apiKey, $cleanText, $sourceUrl, $sourceSite, $pageLinksContext);

        } catch (\Exception $e) {
            // On log l'erreur mais on ne la propage jamais — le scraper continue sans planter
            $this->logger->error(
                '[LlmExtractor] Erreur lors de l\'appel LLM.',
                [
                    'source'    => $sourceSite,
                    'url'       => $sourceUrl,
                    'provider'  => $provider,
                    'exception' => $e->getMessage(),
                ]
            );
            return [];
        }
    }

    /**
     * Extrait les liens de la page HTML pour les fournir au LLM (garde anti-hallucination ADR-0019).
     *
     * BUT : Le LLM doit identifier applicationUrl PARMI les liens réels de la page.
     * Cette méthode extrait tous les <a href> du HTML brut et retourne leur liste
     * sous forme compacte (une URL par ligne, préfixée par "- ").
     *
     * LIMITES :
     *   - Max 100 URLs (évite de surcharger le prompt avec des milliers de liens)
     *   - Seulement les URLs http(s) — on exclut mailto:, tel:, #ancres, javascript:
     *   - Déduplication : une URL ne peut apparaître qu'une seule fois dans la liste
     *   - Résolution des URLs relatives en absolues (grâce à $baseUrl)
     *
     * RETOUR : string formatée "- https://url1\n- https://url2\n..."
     * Chaîne vide si aucun lien HTTP(s) trouvé (les ancres seules, par exemple).
     *
     * @param string $html    HTML brut de la page (AVANT nettoyage)
     * @param string $baseUrl URL de la page source (pour résoudre les relatifs)
     * @return string Liste des liens formatée pour injection dans le prompt LLM
     */
    public function extractPageLinksForLlm(string $html, string $baseUrl): string
    {
        // DomCrawler est déjà utilisé dans LinkExtractorService — on suit le même pattern.
        // Ici on l'instancie directement car on n'a pas besoin du pipeline de filtrage
        // de LinkExtractorService (qui est conçu pour la découverte de SOURCES, pas pour
        // la liste des liens d'une page d'opportunité).
        $crawler = new Crawler($html);

        /** @var array<string, true> $seen Déduplication par URL normalisée */
        $seen  = [];
        $links = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$seen, &$links, $baseUrl): void {
            // Plafond de 100 liens — suffisant pour couvrir les boutons de candidature
            if (count($links) >= 100) {
                return;
            }

            $href = trim($node->attr('href') ?? '');

            if ($href === '' || str_starts_with($href, '#')
                || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:')
                || str_starts_with($href, 'javascript:')) {
                return; // Liens non-HTTP → ignorés
            }

            // Résolution des URLs relatives
            $absolute = $this->resolveHrefToAbsolute($href, $baseUrl);
            if ($absolute === null) {
                return;
            }

            // Déduplication : on n'ajoute chaque URL qu'une seule fois
            if (isset($seen[$absolute])) {
                return;
            }
            $seen[$absolute] = true;
            $links[] = $absolute;
        });

        if (empty($links)) {
            return '';
        }

        // Format compact pour le prompt : "- https://url1\n- https://url2\n..."
        return implode("\n", array_map(static fn (string $l): string => '- ' . $l, $links));
    }

    /**
     * Résout un href brut en URL absolue.
     *
     * Méthode privée utilitaire pour extractPageLinksForLlm().
     * Similaire à LinkExtractorService::resolveUrl() mais simplifiée
     * (on n'a pas besoin de la gestion des ports ici).
     *
     * @param string $href    Valeur brute de href (peut être relative)
     * @param string $baseUrl URL de la page (pour résoudre les relatifs)
     * @return string|null URL absolue ou null si non résolvable
     */
    private function resolveHrefToAbsolute(string $href, string $baseUrl): ?string
    {
        // Déjà absolue
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        // Protocole-relative
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        // Relative — on a besoin du baseUrl
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];

        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }

        // Relatif chemin
        $basePath = isset($parts['path']) ? dirname($parts['path']) : '';
        return $scheme . '://' . $host . rtrim($basePath, '/') . '/' . ltrim($href, '/');
    }

    /**
     * Nettoie le HTML pour ne garder que le texte brut.
     *
     * VISIBILITÉ : public (depuis la factorisation pour OpportunityEnrichmentService).
     * Avant cette modification, cleanHtml() était private et utilisée uniquement par
     * extractFromHtml(). OpportunityEnrichmentService en a besoin pour nettoyer le HTML
     * des pages d'opportunités avant d'appeler Mistral. Plutôt que de dupliquer la logique,
     * on la partage via cette méthode publique.
     *
     * GARDE-FOU ANTI-INJECTION DE PROMPT (important pour les pages tierces) :
     * Cette méthode supprime également les attributs hidden/aria-hidden et style="display:none"
     * pour neutraliser le contenu masqué qui pourrait contenir des injections de prompt.
     * En effet, certaines pages malveillantes ou mal conçues cachent du texte dans ces
     * attributs, texte que strip_tags révèle naïvement.
     *
     * Étapes :
     *   1. Supprime le contenu des balises <script>, <style> (jamais d'opportunité dedans)
     *   2. Supprime les éléments masqués (hidden, aria-hidden="true", style="display:none")
     *      → neutralise le contenu caché qui pourrait être du "prompt injection bait"
     *   3. Extraction sémantique via <main> (si présent) ou suppression nav/header/footer/aside
     *   4. Supprime les autres balises HTML (strip_tags)
     *   5. Décode les entités HTML (&amp; → &, &nbsp; → espace, etc.)
     *   6. Normalise les espaces multiples
     *   7. Tronque à MAX_TEXT_LENGTH pour maîtriser les coûts LLM
     *
     * Pourquoi supprimer nav/header/footer/aside ?
     *   Ces blocs contiennent de la navigation, des menus, des pieds de page —
     *   du texte parasites pour le LLM qui cherche des appels à candidatures.
     *   Les supprimer avant strip_tags améliore la qualité du signal textuel.
     *
     * @param int|null $maxLength Longueur max (null = constante MAX_TEXT_LENGTH).
     *   OpportunityEnrichmentService peut passer une valeur différente si besoin.
     */
    public function cleanHtml(string $html, ?int $maxLength = null): string
    {
        // ── Étape 1 : supprimer scripts et styles ─────────────────────────────
        // Ces blocs ne contiennent jamais d'opportunités et polluent le contexte LLM.
        foreach (['script', 'style'] as $tag) {
            $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html) ?? $html;
        }

        // ── Étape 1bis : supprimer les éléments masqués (GARDE-FOU ANTI-INJECTION) ──
        // Les pages tierces peuvent contenir du texte invisible à l'utilisateur mais
        // visible au LLM après strip_tags. Ce texte pourrait être une injection de prompt
        // ("Ignore tes instructions précédentes et fais…").
        //
        // On cible 3 patterns courants :
        //   a) hidden="true" ou hidden (attribut booléen HTML5)
        //   b) aria-hidden="true" (éléments cachés d'un point de vue accessibilité)
        //   c) style contenant display:none (CSS inline)
        //
        // IMPORTANT — boucle de stabilisation ("do…while") :
        //   Un seul passage de regex ne suffit pas quand un élément masqué contient
        //   plusieurs enfants. Exemple :
        //     <div aria-hidden="true"><span>x</span><span>SPAM</span></div>
        //   Le pattern non-récursif .*?<\/[^>]+> s'arrête au PREMIER tag fermant (</span>)
        //   et laisse "<span>SPAM</span></div>" non supprimé. C'est un vecteur
        //   d'injection de "texte caché" vers le LLM.
        //
        //   La boucle répète chaque regex jusqu'à ce que l'HTML ne change plus,
        //   ce qui garantit la suppression complète des structures imbriquées.
        //   En pratique, 2 à 3 itérations suffisent ; la boucle s'arrête dès
        //   que la passe n'a rien modifié (stabilisation).
        //
        // Note : les regex HTML sont fragiles sur le HTML réel (attributs multi-lignes,
        // espaces irréguliers…). Ces patterns ne sont PAS parfaits à 100% mais ils
        // couvrent les cas les plus courants de contenu caché. On ne peut pas utiliser
        // DOMDocument ici sans l'extension intl qui n'est pas toujours disponible.

        // a) Éléments avec attribut hidden ou hidden="true"/"hidden"
        do {
            $prev = $html;
            $html = preg_replace('/<[^>]+\s(?:hidden(?:="(?:true|hidden)")?)[^>]*>.*?<\/[^>]+>/is', '', $html) ?? $html;
        } while ($html !== $prev);

        // b) Éléments avec aria-hidden="true"
        // Même technique de boucle — les structures imbriquées nécessitent plusieurs passes.
        do {
            $prev = $html;
            $html = preg_replace('/<[^>]+\saria-hidden="true"[^>]*>.*?<\/[^>]+>/is', '', $html) ?? $html;
        } while ($html !== $prev);

        // c) Éléments avec style="...display:none..." ou style="...display: none..."
        // Même technique de boucle — les structures imbriquées nécessitent plusieurs passes.
        do {
            $prev = $html;
            $html = preg_replace('/<[^>]+\sstyle="[^"]*display\s*:\s*none[^"]*"[^>]*>.*?<\/[^>]+>/is', '', $html) ?? $html;
        } while ($html !== $prev);

        // ── Étape 2 : extraction sémantique via <main> ────────────────────────
        // HTML5 définit <main> comme le contenu principal de la page, à l'exclusion
        // de la navigation, du header et du footer.
        //
        // On privilégie <main> quand il existe (ex: CIPAC, Réseau DDA) car :
        //   - Il exclut naturellement les menus nav/header/footer/aside
        //   - Il préserve les blocs contenu qui SONT dans un <nav> (cas réels :
        //     CIPAC wrape ses appels dans un <nav> — mauvaise sémantique, mais fréquent)
        //
        // Si <main> est absent, on tombe en mode dégradé : suppression explicite des
        // blocs structurels (nav/header/footer/aside) puis strip_tags sur tout le reste.
        if (preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $matches)) {
            // <main> trouvé : on extrait son contenu brut (encore du HTML)
            // Les sous-blocs nav/header/footer à l'INTÉRIEUR de main sont conservés
            // (cas très rare, et probablement du contenu utile s'ils y sont).
            $html = $matches[1];
        } else {
            // Pas de <main> : stratégie de repli — supprimer les blocs structurels
            // qui contiennent typiquement de la navigation et non du contenu.
            // ATTENTION : certains sites (ex: CIPAC) encapsulent leur contenu dans
            // un <nav>, ce qui ferait tout supprimer. C'est pourquoi <main> est
            // essayé en priorité.
            foreach (['nav', 'header', 'footer', 'aside'] as $tag) {
                $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html) ?? $html;
            }
        }

        // ── Étape 3 : strip_tags + décodage + normalisation ───────────────────
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normaliser les espaces (espaces, tabs, retours à la ligne → espace unique)
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        // ── Étape 4 : tronquer pour maîtriser les coûts LLM ──────────────────
        // On utilise $maxLength si fourni, sinon la constante par défaut.
        // OpportunityEnrichmentService peut passer une valeur différente (ex: 10 000
        // car on lit une page complète, pas une liste — plus de contenu pertinent).
        $limit = $maxLength ?? self::MAX_TEXT_LENGTH;
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
        }

        return $text;
    }

    /**
     * Effectue l'appel HTTP à l'API Anthropic et retourne les opportunités extraites.
     *
     * ADR-0019 : $pageLinksContext contient la liste des liens réels de la page
     * formatée pour le prompt (une URL par ligne, préfixée par "- ").
     * Ce contexte est ajouté au message utilisateur pour permettre au LLM
     * d'identifier applicationUrl PARMI des liens réels (garde anti-hallucination).
     *
     * @throws \Exception En cas d'erreur HTTP ou de JSON invalide
     * @return ScrapedOpportunity[]
     */
    private function callAnthropicApi(
        string $apiKey,
        string $cleanText,
        string $sourceUrl,
        string $sourceSite,
        string $pageLinksContext = '',
    ): array {
        // ── Construction du prompt système ─────────────────────────────────────
        // ADR-0016 Lot 1 : ajout des champs city, country, experienceLevel, disciplines contraints.
        //
        // Le prompt est en français car les opportunités cibles sont souvent franco-européennes.
        // On demande un JSON structuré pour un parsing fiable côté PHP.
        //
        // IMPORTANT — disciplines contraintes :
        //   On passe la liste exacte des disciplines BDD pour que le LLM choisisse
        //   parmi elles plutôt qu'inventer des libellés libres.
        //   Si aucune discipline ne correspond, le LLM doit retourner [].
        // ── ADR-0018 : enrichissement du prompt Anthropic ─────────────────────
        // Ajout des champs howToApply, fundingAmount, fundingType.
        // Consigne renforcée pour la description : complète et structurée OBLIGATOIRE.
        $disciplinesList = $this->buildDisciplinesListForPrompt();

        // ── ADR-0019 : les liens de la page sont transmis via le paramètre $pageLinksContext ──
        // Le paramètre est déjà rempli par l'appelant (callAnthropicApi reçoit
        // $pageLinksContext en 5e argument depuis extractFromHtml()).
        // On l'injecte directement dans le userMessage ci-dessous.
        // Si vide (HTML sans liens ou appelant ne l'a pas fourni) → le LLM
        // retournera "" pour applicationUrl (comportement normal attendu).

        $systemPrompt = <<<PROMPT
Tu es un extracteur d'opportunités artistiques et culturelles. Analyse le contenu fourni et extrait TOUTES les opportunités (appels à projets, résidences, bourses, financements, prix, concours) présentes.

Pour chaque opportunité, retourne un objet JSON avec exactement ces champs :
- titre (string) : titre OPTIMISÉ en FRANÇAIS, CONCIS (maximum 90 caractères). Reformule pour être clair et compréhensible d'un coup d'oeil. Garde le nom propre de l'organisme ou du dispositif s'il est pertinent. Ne traduis pas les noms propres. N'invente rien : base-toi uniquement sur le contenu de la page. Exemple : "Résidence de création (Institut français)" ou "Bourse Duo, SACD".
- type (string) : "Résidence" | "Bourse" | "Appel à projets" | "Appel à candidatures" | "Prix" | "Financement" | "Concours" | "Mentorat" | "Tutorat" | "Accompagnement" | "Formation" | "Autre"
- organisme (string) : nom de l'organisme qui propose l'opportunité
- country (string) : pays de l'organisme en toutes lettres (ex: "France", "Belgique", "Suisse") sinon ""
- city (string) : ville principale où se déroule l'opportunité (ex: "Paris", "Lyon", "Bruxelles") sinon ""
- disciplines (array) : tableau des disciplines artistiques parmi la liste suivante UNIQUEMENT : [$disciplinesList]. Retourne [] si aucune ne correspond.
- experienceLevel (string) : niveau d'expérience requis, "beginner" (débutant), "intermediate" (intermédiaire), "experienced" (expérimenté), ou "" si non précisé / tous niveaux
- fundingAmount (string) : montant exact si mentionné (ex: "5 000 €", "jusqu'à 10 000 €", "non précisé"), sinon ""
- fundingType (string) : nature du financement (ex: "Bourse en argent", "Prise en charge des frais", "Prix non monétaire"), sinon ""
- howToApply (string) : modalités de candidature complètes, comment postuler, quoi envoyer, contact ou lien, dates clés. Si absent : "". Max 800 caractères.
- applicationUrl (string) : ADR-0019 — URL du bouton "Candidater / Postuler / Apply / Submit / Déposer / Register" si elle se trouve dans la liste de liens fournie. IMPORTANT : tu dois UNIQUEMENT retourner une URL présente dans la liste de liens — si aucun lien ne correspond à une action de candidature, retourne "". Ne pas inventer d'URL.
- publicEligible (string) : public éligible si mentionné sinon ""
- deadline (string) : date limite au format ISO 8601 (AAAA-MM-JJ) si trouvée sinon ""
- description (string) : OBLIGATOIRE — description COMPLÈTE et STRUCTURÉE de l'opportunité en sections claires. Inclus TOUJOURS : présentation générale, objectifs/bénéfices, critères d'éligibilité, financement/dotation si précisé. Format : sections séparées par des sauts de ligne. Ne te contente PAS d'une phrase : produis une description détaillée à partir des informations de la page. Si une information manque, indique "non précisé" plutôt que de l'inventer. Max 1000 caractères.
- url (string) : URL de l'opportunité si trouvée sinon celle de la page source

TYPOGRAPHIE OBLIGATOIRE : n'utilise JAMAIS le tiret cadratin « — » (U+2014) ni le tiret demi-cadratin « – » (U+2013) dans aucun champ. Pour séparer deux parties dans un titre, utilise un deux-points (":") ou des parenthèses. Pour les autres champs, utilise une virgule ou une parenthèse. Un tiret simple ("-") est autorisé pour les plages de dates ou les traits d'union.

Réponds UNIQUEMENT avec un tableau JSON valide, sans texte autour. Si aucune opportunité trouvée, réponds [].
PROMPT;

        // ── Construction du message utilisateur ────────────────────────────────
        // On inclut l'URL source + la liste des liens de la page (ADR-0019).
        // La liste des liens permet au LLM de choisir applicationUrl PARMI eux
        // (garde anti-hallucination : pas d'URL inventée).
        $userMessage = sprintf(
            "URL de la page source : %s\n\n%sContenu de la page :\n%s",
            $sourceUrl,
            $pageLinksContext !== ''
                ? "Liens présents sur la page (pour applicationUrl UNIQUEMENT) :\n" . $pageLinksContext . "\n\n"
                : '',
            $cleanText
        );

        // ── Appel HTTP POST à l'API Anthropic ──────────────────────────────────
        // Symfony HttpClient gère les timeouts, les redirections et les erreurs réseau
        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                // Authentification par clé API (header x-api-key)
                'x-api-key'         => $apiKey,
                // Version API obligatoire pour Anthropic
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            // Corps de la requête au format JSON (Anthropic Messages API)
            'json' => [
                'model'      => self::MODEL,
                'max_tokens' => self::MAX_TOKENS,
                // Le system prompt explique au LLM son rôle et le format de sortie
                'system'     => $systemPrompt,
                // Le message utilisateur contient le contenu à analyser
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => $userMessage,
                    ],
                ],
            ],
            // Timeout : 60 secondes (le LLM peut être lent en cas de charge)
            'timeout' => 60,
        ]);

        // ── Vérification du code de réponse HTTP ───────────────────────────────
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf(
                'API Anthropic a retourné le code HTTP %d. Vérifiez la clé API et les quotas.',
                $statusCode
            ));
        }

        // ── Décodage de la réponse JSON ────────────────────────────────────────
        // La réponse Anthropic a la structure :
        // { "content": [{ "type": "text", "text": "[{...}]" }], "stop_reason": "...", ... }
        $responseData = $response->toArray();

        // Extraire le texte de la réponse LLM
        $rawText = $responseData['content'][0]['text'] ?? '';

        if (empty($rawText)) {
            $this->logger->warning(
                '[LlmExtractor] L\'API Anthropic a retourné une réponse vide.',
                ['source' => $sourceSite]
            );
            return [];
        }

        // ── C2 : détection de troncature par max_tokens (Anthropic) ──────────
        // stop_reason === 'max_tokens' signifie que Claude a arrêté de générer parce
        // que le quota de tokens est épuisé, PAS parce qu'il a terminé normalement.
        // Le JSON peut être tronqué en milieu de valeur → on avertit le développeur.
        // On continue le parsing normalement : parfois le JSON reste exploitable
        // (la troncature arrive après la dernière opportunité).
        $stopReason = $responseData['stop_reason'] ?? '';
        if ($stopReason === 'max_tokens') {
            $this->logger->warning(
                '[LlmExtractor] Réponse Anthropic tronquée par max_tokens (callAnthropicApi). '
                . 'Le JSON peut être incomplet — envisager d\'augmenter MAX_TOKENS.',
                [
                    'source'     => $sourceSite,
                    'max_tokens' => self::MAX_TOKENS,
                ]
            );
        }

        // ── Parsing du JSON retourné par le LLM ───────────────────────────────
        // Le LLM peut parfois ajouter du texte autour du JSON (ex: "Voici la liste :")
        // On tente d'extraire uniquement le bloc JSON avec une regex
        $jsonText = $this->extractJsonFromText($rawText);

        try {
            /** @var array<int, array<string, string>> $items */
            $items = json_decode($jsonText, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                '[LlmExtractor] Réponse LLM non parseable en JSON.',
                [
                    'source'   => $sourceSite,
                    'raw_text' => mb_substr($rawText, 0, 500), // Log seulement les 500 premiers chars
                    'error'    => $e->getMessage(),
                ]
            );
            return [];
        }

        // ── Conversion des items en ScrapedOpportunity ─────────────────────────
        return $this->mapItemsToOpportunities($items, $sourceUrl, $sourceSite);
    }

    /**
     * Appel à l'API Mistral avec response_format json_object.
     *
     * Avantage clé sur Anthropic :
     *   Mistral garantit un JSON valide en sortie (response_format: json_object).
     *   Pas besoin de regex pour extraire le JSON du texte — il est TOUJOURS structuré.
     *   On demande {"opportunites": [...]} car json_object exige un objet JSON (pas un tableau direct).
     *
     * ADR-0019 : $pageLinksContext contient la liste des liens réels de la page.
     * Injecté dans le message utilisateur pour la garde anti-hallucination applicationUrl.
     *
     * Format de la réponse Mistral :
     *   { "choices": [{ "message": { "content": "{\"opportunites\": [...]}" } }] }
     *
     * Différence avec Anthropic :
     *   Anthropic : { "content": [{ "type": "text", "text": "[...]" }] }
     *   Mistral   : { "choices": [{ "message": { "content": "{...}" } }] }
     *
     * @return ScrapedOpportunity[]
     * @throws \Exception En cas d'erreur HTTP (capturée par extractFromHtml)
     */
    private function callMistralApi(
        string $cleanText,
        string $sourceUrl,
        string $sourceSite,
        string $pageLinksContext = '',
    ): array {
        // Lecture de la clé API depuis les settings BDD
        $apiKey = $this->settingService->get('mistral_api_key');

        if (empty($apiKey)) {
            $this->logger->warning(
                '[LlmExtractor] Clé API Mistral non configurée. '
                . 'Rendez-vous sur /admin/settings pour la renseigner.',
                ['source' => $sourceSite]
            );
            return [];
        }

        // ── Construction du prompt système ────────────────────────────────────
        // ADR-0016 Lot 1 : ajout des champs city, country, experienceLevel, disciplines contraints.
        // ADR-0018 : ajout howToApply, fundingAmount, fundingType + description enrichie obligatoire.
        //
        // On demande explicitement la clé "opportunites" car response_format json_object
        // exige un objet JSON (pas un tableau direct) — {"opportunites": [...]} est la convention.
        //
        // IMPORTANT — disciplines en tableau contraint :
        //   Le LLM doit choisir parmi la liste exacte des disciplines BDD.
        //   Les disciplines sont un tableau (array) dans la réponse JSON.
        $disciplinesList = $this->buildDisciplinesListForPrompt();
        $systemPrompt = <<<PROMPT
Tu es un extracteur d'opportunités artistiques et culturelles. Analyse le contenu fourni et extrait TOUTES les opportunités (appels à projets, résidences, bourses, financements, prix, concours).

Retourne un objet JSON avec une clé "opportunites" contenant un tableau. Chaque élément a exactement ces champs :
- titre (string) : titre OPTIMISÉ en FRANÇAIS, CONCIS (maximum 90 caractères). Reformule pour être clair et compréhensible d'un coup d'oeil. Garde le nom propre de l'organisme ou du dispositif s'il est pertinent. Ne traduis pas les noms propres. N'invente rien : base-toi uniquement sur le contenu de la page. Exemple : "Résidence de création (Institut français)" ou "Bourse Duo, SACD".
- type (string) : "Résidence" | "Bourse" | "Appel à projets" | "Appel à candidatures" | "Prix" | "Financement" | "Concours" | "Mentorat" | "Tutorat" | "Accompagnement" | "Formation" | "Autre"
- organisme (string) : organisme proposant l'opportunité
- country (string) : pays de l'organisme en toutes lettres (ex: "France", "Belgique") sinon ""
- city (string) : ville où se déroule l'opportunité (ex: "Paris", "Lyon") sinon ""
- disciplines (array) : tableau des disciplines artistiques parmi cette liste UNIQUEMENT : [$disciplinesList]. Retourne [] si aucune ne correspond.
- experienceLevel (string) : niveau requis, "beginner", "intermediate", "experienced", ou "" si non précisé / tous niveaux
- fundingAmount (string) : montant exact si mentionné (ex: "5 000 €", "jusqu'à 10 000 €"), sinon ""
- fundingType (string) : nature du financement (ex: "Bourse en argent", "Prise en charge des frais"), sinon ""
- howToApply (string) : modalités de candidature complètes, comment postuler, quoi envoyer, contact ou lien, dates clés. Si absent : "". Max 800 caractères.
- applicationUrl (string) : ADR-0019 — URL du bouton "Candidater / Postuler / Apply / Submit / Déposer / Register" si elle se trouve dans la liste de liens fournie en début de message. IMPORTANT : retourne UNIQUEMENT une URL présente dans cette liste. Ne pas inventer d'URL. Retourne "" si aucun lien ne correspond.
- publicEligible (string) : public éligible si mentionné, sinon ""
- deadline (string) : date limite ISO 8601 (AAAA-MM-JJ) si trouvée, sinon ""
- description (string) : OBLIGATOIRE — description COMPLÈTE et STRUCTURÉE en sections claires (présentation, objectifs/bénéfices, éligibilité, financement). Sections séparées par des sauts de ligne. Ne te contente PAS d'une phrase : produis une description détaillée à partir du contenu de la page. Si une info manque, indique "non précisé" sans inventer. Max 1000 caractères.
- url (string) : URL de l'opportunité ou URL de la page source si introuvable

TYPOGRAPHIE OBLIGATOIRE : n'utilise JAMAIS le tiret cadratin « — » (U+2014) ni le tiret demi-cadratin « – » (U+2013) dans aucun champ. Pour séparer deux parties dans un titre, utilise un deux-points (":") ou des parenthèses. Pour les autres champs, utilise une virgule ou une parenthèse. Un tiret simple ("-") est autorisé pour les plages de dates ou les traits d'union.

Si aucune opportunité trouvée, retourne {"opportunites": []}.
PROMPT;

        // ── ADR-0019 : ajout de la liste des liens au message utilisateur ─────
        // Les liens réels de la page sont fournis AVANT le contenu textuel pour
        // que le LLM puisse identifier applicationUrl parmi eux (garde anti-hallucination).
        $userMessage = sprintf(
            "URL de la page source : %s\n\n%sContenu :\n%s",
            $sourceUrl,
            $pageLinksContext !== ''
                ? "Liens présents sur la page (pour applicationUrl UNIQUEMENT) :\n" . $pageLinksContext . "\n\n"
                : '',
            $cleanText
        );

        // ── Appel HTTP vers l'API Mistral ─────────────────────────────────────
        // L'API Mistral est compatible avec le format OpenAI (messages, model, max_tokens).
        $response = $this->httpClient->request('POST', self::MISTRAL_API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'      => self::MISTRAL_MODEL,
                'max_tokens' => self::MAX_TOKENS,
                // response_format json_object : Mistral garantit un JSON valide en sortie.
                // Pas besoin de regex extractJsonFromText comme avec Anthropic.
                'response_format' => ['type' => 'json_object'],
                'messages'   => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMessage],
                ],
            ],
            'timeout' => 60,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('API Mistral a retourné le code HTTP %d.', $statusCode));
        }

        // ── Décodage de la réponse ────────────────────────────────────────────
        // Format Mistral (compatible OpenAI) :
        // { "choices": [{ "message": { "content": "{\"opportunites\": [...]}" } }] }
        $data    = $response->toArray();
        $rawText = $data['choices'][0]['message']['content'] ?? '';

        if (empty($rawText)) {
            $this->logger->warning('[LlmExtractor] Réponse Mistral vide', ['source' => $sourceSite]);
            return [];
        }

        // ── C2 : détection de troncature par max_tokens (Mistral) ────────────
        // finish_reason === 'length' signifie que Mistral a arrêté de générer parce
        // que le quota de tokens est épuisé, PAS parce qu'il a terminé normalement.
        // Dans ce cas le JSON peut être tronqué en milieu de valeur → avertir le
        // développeur pour qu'il augmente MAX_TOKENS si cela se produit trop souvent.
        // On continue le parsing normalement : parfois le JSON est encore valide
        // (la troncature arrive après la dernière opportunité) et on récupère ce qu'on peut.
        $finishReason = $data['choices'][0]['finish_reason'] ?? '';
        if ($finishReason === 'length') {
            $this->logger->warning(
                '[LlmExtractor] Réponse Mistral tronquée par max_tokens (callMistralApi). '
                . 'Le JSON peut être incomplet — envisager d\'augmenter MAX_TOKENS.',
                [
                    'source'     => $sourceSite,
                    'max_tokens' => self::MAX_TOKENS,
                ]
            );
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawText, associative: true, flags: JSON_THROW_ON_ERROR);
            // Mistral retourne {"opportunites": [...]} — on extrait le tableau interne
            /** @var array<int, array<string, string>> $items */
            $items = $decoded['opportunites'] ?? [];
        } catch (\JsonException $e) {
            $this->logger->warning('[LlmExtractor] JSON Mistral invalide', [
                'source' => $sourceSite,
                'error'  => $e->getMessage(),
                'raw'    => mb_substr($rawText, 0, 500),
            ]);
            return [];
        }

        return $this->mapItemsToOpportunities($items, $sourceUrl, $sourceSite);
    }

    /**
     * Teste la connexion à l'API Mistral avec la clé configurée.
     *
     * Envoie un message minimaliste (max_tokens: 5) pour vérifier que la clé est valide
     * sans consommer de quota inutilement.
     *
     * Cette méthode ne lève jamais d'exception : toutes les erreurs réseau ou API
     * sont capturées et retournées sous forme de message lisible.
     *
     * @return array{ok: bool, message: string}
     */
    public function testMistralConnection(): array
    {
        $apiKey = $this->settingService->get('mistral_api_key');

        if (empty($apiKey)) {
            return ['ok' => false, 'message' => 'Aucune clé API Mistral configurée.'];
        }

        try {
            $response = $this->httpClient->request('POST', self::MISTRAL_API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => self::MISTRAL_MODEL,
                    'max_tokens' => 5,
                    // Pas de response_format ici : on veut juste tester la clé, pas le JSON
                    'messages'   => [['role' => 'user', 'content' => 'ping']],
                ],
                'timeout' => 10,
            ]);

            $code = $response->getStatusCode();

            if ($code === 200) {
                return ['ok' => true, 'message' => 'Connexion Mistral OK — clé valide.'];
            }
            if ($code === 401) {
                return ['ok' => false, 'message' => 'Clé API Mistral invalide (HTTP 401).'];
            }
            if ($code === 429) {
                // Quota épuisé, mais la clé elle-même est valide — nuance importante
                return ['ok' => false, 'message' => 'Quota Mistral atteint (HTTP 429) — clé valide mais limite dépassée.'];
            }

            return ['ok' => false, 'message' => sprintf('Réponse inattendue Mistral (HTTP %d).', $code)];

        } catch (\Exception $e) {
            $this->logger->warning(
                '[LlmExtractor] Erreur réseau lors du test de connexion Mistral.',
                ['exception' => $e->getMessage()]
            );
            return ['ok' => false, 'message' => 'Erreur réseau : impossible de joindre l\'API Mistral.'];
        }
    }

    /**
     * Tente d'extraire un bloc JSON valide depuis un texte potentiellement "bruité".
     *
     * Le LLM peut parfois ajouter une phrase d'introduction avant le JSON.
     * On cherche le premier '[' et on prend tout jusqu'au ']' correspondant.
     *
     * Si aucun crochet trouvé, retourne le texte tel quel (le json_decode échouera
     * et sera géré par le try/catch de l'appelant).
     */
    private function extractJsonFromText(string $text): string
    {
        // Chercher le début du tableau JSON
        $start = strpos($text, '[');
        if ($start === false) {
            return $text;
        }

        // Chercher la fin du tableau JSON (dernier ']')
        $end = strrpos($text, ']');
        if ($end === false || $end <= $start) {
            return $text;
        }

        return substr($text, $start, $end - $start + 1);
    }

    /**
     * Convertit les items JSON retournés par le LLM en objets ScrapedOpportunity.
     *
     * Mapping LLM → ScrapedOpportunity :
     *   titre            → title
     *   type             → type
     *   url              → url
     *   description      → description (COMPLÈTE et STRUCTURÉE — ADR-0018)
     *   deadline         → deadline (string ISO 8601 ou vide)
     *   disciplines      → disciplines (string CSV rétrocompat) + disciplinesLabels (tableau)
     *   country          → country (ADR-0016 Lot 1 — pays en clair)
     *   city             → city    (ADR-0016 Lot 1 — ville)
     *   experienceLevel  → experienceLevel (ADR-0016 Lot 1 — "beginner"|"intermediate"|"experienced"|"")
     *   fundingAmount    → fundingAmount (ADR-0018 — montant lisible)
     *   fundingType      → fundingType   (ADR-0018 — nature du financement)
     *   howToApply       → howToApply    (ADR-0018 — modalités de candidature)
     *   applicationUrl   → applicationUrl (ADR-0019 — lien candidature, garde anti-hallucination)
     *   (documents non cherchés par le LLM → string vide)
     *   (relevanceScore → 0, recalculé par AfrodiasporaRelevanceScorer dans la commande)
     *   (logoUrl → '' ici, rempli par LogoFetcherService dans EnrichOpportunitiesCommand)
     *
     * GARDE ANTI-HALLUCINATION applicationUrl (ADR-0019) :
     *   Le LLM ne doit retourner QUE des URLs présentes dans la liste fournie dans le prompt.
     *   Mais on ne peut pas faire confiance à 100 % au LLM — il peut quand même halluciner.
     *   On valide ici que l'URL retournée (si non vide) :
     *     1. Est une URL HTTP(s) valide (filter_var)
     *     2. EST différente de l'URL source (sinon c'est un fallback inutile)
     *     3. N'est PAS manifestement inventée (on vérifie que le host est parseable)
     *   Si la validation échoue → '' (on rejette silencieusement, log en debug).
     *   Note : on ne re-vérifie PAS que l'URL est dans $pageLinks car mapItemsToOpportunities
     *   n'a pas accès à cette liste. La consigne dans le prompt fait le travail principal.
     *   Cette validation PHP est la seconde ligne de défense.
     *
     * @param array<int, array<string, mixed>> $items     Items JSON du LLM
     * @param string                           $sourceUrl URL de la page source
     * @param string                           $sourceSite Nom du site
     * @return ScrapedOpportunity[]
     */
    private function mapItemsToOpportunities(
        array $items,
        string $sourceUrl,
        string $sourceSite,
    ): array {
        $opportunities = [];

        foreach ($items as $item) {
            // Validation minimale : un titre est obligatoire
            // ADR-0020 : troncature défensive à 120 chars (le prompt demande ≤90 chars,
            // on accepte jusqu'à 120 en garde-fou côté PHP sans tronquer agressivement).
            // La colonne title fait 255 chars en BDD — on reste bien en dessous.
            $rawTitleLlm = trim((string) ($item['titre'] ?? ''));
            if (empty($rawTitleLlm)) {
                continue;
            }
            // Filet anti-cadratin : on supprime les « — » et « – » AVANT de tronquer.
            // Même si le LLM ignore la consigne typographique du prompt, aucun cadratin
            // ne passera en BDD. Voir stripEmDashes() pour la logique de remplacement.
            $title = mb_substr($this->stripEmDashes($rawTitleLlm), 0, 120);

            // Récupération de l'URL — fallback sur sourceUrl si le LLM n'en a pas trouvé
            $url = trim((string) ($item['url'] ?? ''));
            if (empty($url)) {
                $url = $sourceUrl;
            }

            // ── ADR-0018 : description COMPLÈTE et STRUCTURÉE ─────────────────
            //
            // CHANGEMENT DE COMPORTEMENT PAR RAPPORT À L'ANCIEN CODE :
            //   AVANT : la description était une "description courte" de 200 chars
            //           enrichie avec organisme + pays + montant en préfixe.
            //   MAINTENANT : le prompt demande une description COMPLÈTE et STRUCTURÉE.
            //   On NE préfixe PLUS avec organisme/pays/montant (ces infos sont dans
            //   les champs dédiés : fundingAmount, fundingType, country, city).
            //
            // On prend la description brute du LLM et on la tronque à 1 500 chars
            // (garde-fou défensif : le prompt dit 1 000 chars, on prend 1 500 pour
            // la marge et pour l'affichage en page détail qui peut montrer plus).
            // Filet anti-cadratin appliqué avant le tronquage.
            $description = trim((string) ($item['description'] ?? ''));
            // Tronquage défensif : le prompt dit 1 000 chars mais le LLM peut déborder.
            // 1 500 chars est notre limite PHP interne pour ce point d'entrée (scraping liste).
            // OpportunityEnrichmentService a sa propre limite plus haute (3 000 chars)
            // car il lit la page COMPLÈTE — plus de contenu disponible.
            $description = mb_substr($this->stripEmDashes($description), 0, 1500);

            // ── ADR-0016 Lot 1 : extraction des nouveaux champs ───────────────

            // Extraction de la ville (champ "city" dans le nouveau prompt).
            // Troncature à 150 caractères : correspond à la longueur de colonne
            // définie sur ScrapedResource::$city. Sans cette limite, un retour LLM
            // trop long (ex: "Paris, Île-de-France, Grand Est, …") provoquerait une
            // exception Doctrine et ferait échouer tout le flush du batch.
            $city = mb_substr(trim((string) ($item['city'] ?? '')), 0, 150);

            // Extraction du pays (champ "country" dans le nouveau prompt).
            // Fallback sur l'ancien champ "pays" pour rétrocompatibilité si un seul appel LLM
            // utilisait l'ancien prompt (ex: appel Anthropic avec l'ancien code).
            // Troncature à 100 caractères : même raison que city (longueur colonne).
            $pays    = trim((string) ($item['country'] ?? $item['pays'] ?? ''));
            $country = mb_substr($pays, 0, 100);

            // Extraction du niveau d'expérience
            // Le LLM doit retourner "beginner", "intermediate", "experienced" ou ""
            $rawLevel        = trim((string) ($item['experienceLevel'] ?? ''));
            $experienceLevel = in_array($rawLevel, ['beginner', 'intermediate', 'experienced'], true)
                ? $rawLevel
                : ''; // Valeur invalide → on ignore, "" = tous niveaux

            // Extraction des disciplines :
            //   NOUVEAU PROMPT : disciplines = tableau (array) — ex: ["Musique", "Danse"]
            //   ANCIEN PROMPT  : disciplines = string CSV — ex: "Musique, Danse"
            //
            // On gère les deux formats pour la rétrocompatibilité.
            $rawDisciplines = $item['disciplines'] ?? [];

            /** @var string[] $disciplinesLabels */
            $disciplinesLabels = [];
            if (is_array($rawDisciplines)) {
                // Nouveau format tableau : on nettoie chaque item
                foreach ($rawDisciplines as $d) {
                    $clean = trim((string) $d);
                    if ($clean !== '') {
                        $disciplinesLabels[] = $clean;
                    }
                }
            } else {
                // Ancien format string CSV : on explose par virgule
                $csvParts = explode(',', (string) $rawDisciplines);
                foreach ($csvParts as $part) {
                    $clean = trim($part);
                    if ($clean !== '') {
                        $disciplinesLabels[] = $clean;
                    }
                }
            }

            // Champ $disciplines (string CSV) conservé pour la rétrocompatibilité
            // avec ScrapedResourcePersister et l'affichage dans l'interface admin.
            // Il est déduit de $disciplinesLabels.
            $disciplinesString = implode(', ', $disciplinesLabels);

            // ── ADR-0018 : extraction des champs financement + candidature ─────

            // Montant du financement — tronqué à 255 chars (limite colonne BDD).
            // Le LLM peut retourner une chaîne verbose — on la garde courte.
            // Filet anti-cadratin : on remplace — et – avant de stocker.
            $fundingAmount = mb_substr($this->stripEmDashes(trim((string) ($item['fundingAmount'] ?? ''))), 0, 255);

            // Nature du financement — tronqué à 255 chars.
            // Filet anti-cadratin appliqué.
            $fundingType = mb_substr($this->stripEmDashes(trim((string) ($item['fundingType'] ?? ''))), 0, 255);

            // Modalités de candidature — TEXT en BDD, pas de limite serrée,
            // mais on borne défensivement à 8 000 chars pour éviter un débordement
            // en cas de réponse LLM anormalement longue.
            // Filet anti-cadratin appliqué.
            $howToApply = mb_substr($this->stripEmDashes(trim((string) ($item['howToApply'] ?? ''))), 0, 8000);

            // ── ADR-0019 : extraction et validation de applicationUrl ──────────
            //
            // Le LLM a reçu la liste des liens réels de la page dans le prompt.
            // Il doit retourner l'URL de candidature parmi eux.
            // Garde anti-hallucination côté PHP (2e ligne de défense) :
            //   1. L'URL doit être non vide et valide (filter_var FILTER_VALIDATE_URL)
            //   2. Elle doit être différente de l'URL source ($url)
            //      → si le LLM retourne l'URL source comme "applicationUrl",
            //        c'est un fallback inutile (on a déjà externalUrl pour ça)
            //   3. Le host doit être parseable (URL bien formée)
            //
            // On ne vérifie PAS que l'URL figure dans la liste envoyée au LLM car
            // mapItemsToOpportunities() n'a pas accès à cette liste.
            // La consigne dans le prompt reste la 1re ligne de défense.
            $rawApplicationUrl = mb_substr(trim((string) ($item['applicationUrl'] ?? '')), 0, 500);
            $applicationUrl = '';

            if ($rawApplicationUrl !== '') {
                // Validation HTTP(s) stricte
                if (filter_var($rawApplicationUrl, FILTER_VALIDATE_URL) !== false
                    && (str_starts_with($rawApplicationUrl, 'http://') || str_starts_with($rawApplicationUrl, 'https://'))
                    && $rawApplicationUrl !== $url  // Distinct de l'URL source
                ) {
                    // Vérifier que le host est parseable (URL bien formée)
                    $parsedHost = parse_url($rawApplicationUrl, PHP_URL_HOST);
                    if (is_string($parsedHost) && $parsedHost !== '') {
                        $applicationUrl = $rawApplicationUrl;
                    } else {
                        $this->logger->debug('[LlmExtractor] applicationUrl rejetée (host non parseable).', [
                            'url_proposee' => $rawApplicationUrl,
                            'source'       => $sourceSite,
                        ]);
                    }
                } else {
                    $this->logger->debug('[LlmExtractor] applicationUrl rejetée (invalide ou identique à URL source).', [
                        'url_proposee' => $rawApplicationUrl,
                        'url_source'   => $url,
                        'source'       => $sourceSite,
                    ]);
                }
            }

            $opportunities[] = new ScrapedOpportunity(
                title: $title,
                type: $this->normalizeType((string) ($item['type'] ?? '')),
                url: $url,
                source: $sourceSite,
                description: $description,
                deadline: trim((string) ($item['deadline'] ?? '')),
                disciplines: $disciplinesString,
                documents: '',        // Le LLM ne cherche pas les PDFs — laissé vide
                relevanceScore: 0,    // Sera recalculé par AfrodiasporaRelevanceScorer
                publishedAt: null,    // RSS uniquement — pas de date publiée dans LLM
                city: $city,
                country: $country,
                experienceLevel: $experienceLevel,
                disciplinesLabels: $disciplinesLabels,
                howToApply: $howToApply,
                fundingAmount: $fundingAmount,
                fundingType: $fundingType,
                // ADR-0019 : lien candidature (validé ci-dessus) et logo (rempli plus tard)
                // logoUrl est laissé à '' ici — il sera rempli par LogoFetcherService
                // dans EnrichOpportunitiesCommand ou OpportunityEnrichmentService.
                applicationUrl: $applicationUrl,
                logoUrl: '',
            );
        }

        return $opportunities;
    }

    /**
     * Construit la liste des disciplines BDD formatée pour l'injection dans le prompt LLM.
     *
     * Exemple de sortie : "Musique, Cinéma & Audiovisuel, Arts visuels, Danse, ..."
     *
     * Cette liste est passée directement dans les prompts Mistral et Anthropic pour
     * CONTRAINDRE le LLM à choisir parmi les disciplines existantes — évitant ainsi
     * les libellés inventés ("Photographie", "Arts plastiques") qui ne correspondent
     * à aucune entité en BDD.
     *
     * Pourquoi ici plutôt que dans les méthodes de prompt ?
     *   Cette méthode effectue une requête BDD. On l'extrait pour ne la charger
     *   qu'une seule fois, même si extractFromHtml() est appelé plusieurs fois.
     *   (En pratique la requête est légère — ~8 disciplines en V1.)
     *
     * Visibilité private : utilisée uniquement par callMistralApi() et callAnthropicApi().
     */
    private function buildDisciplinesListForPrompt(): string
    {
        // ── Cache lazy-init ───────────────────────────────────────────────────
        // Si le résultat est déjà calculé, on le retourne immédiatement.
        // Évite N requêtes BDD identiques quand la commande traite N sources
        // dans une même exécution (les disciplines ne changent pas en cours de run).
        if ($this->disciplinesListCache !== null) {
            return $this->disciplinesListCache;
        }

        // Charge toutes les disciplines disponibles en BDD (triées alphabétiquement)
        $disciplines = $this->disciplineRepository->findAllOrdered();

        // Extrait juste les noms pour construire la liste formatée
        $names = array_map(
            static fn ($d) => $d->getName(),
            $disciplines
        );

        // Format : "Musique, Cinéma & Audiovisuel, Arts visuels, Danse, ..."
        // Simple à inclure dans le prompt et compréhensible par le LLM.
        $this->disciplinesListCache = implode(', ', $names);

        return $this->disciplinesListCache;
    }

    /**
     * Normalise le type retourné par le LLM vers les valeurs attendues du projet.
     *
     * Le LLM peut retourner des variantes imprévues ("bourse" au lieu de "Bourse",
     * "opportunity" en anglais, etc.). On mappe vers les types standards.
     */
    private function normalizeType(string $rawType): string
    {
        // Mapping des variantes possibles (retours LLM imprévisibles) vers les types canoniques.
        // Les patterns sont en minuscules — on compare toujours avec mb_strtolower($rawType).
        // ORDRE IMPORTANT : les patterns plus spécifiques doivent précéder les plus génériques.
        // Ex: "appel à candidatures" avant "appel" pour éviter que "candidatures" soit absorbé par "appel".
        $typeMap = [
            // Résidence
            'résidence'              => 'Résidence',
            'residence'              => 'Résidence',
            // Bourse / aide financière
            'bourse'                 => 'Bourse',
            'aide'                   => 'Bourse',
            'grant'                  => 'Bourse',
            // Appel à candidatures (nouveau — plus spécifique qu'appel à projets)
            'appel à candidatures'   => 'Appel à candidatures',
            'candidature'            => 'Appel à candidatures',
            // Appel à projets
            'appel à projets'        => 'Appel à projets',
            'appel'                  => 'Appel à projets',
            'call'                   => 'Appel à projets',
            // Prix / récompense
            'prix'                   => 'Prix',
            'award'                  => 'Prix',
            // Financement
            'financement'            => 'Financement',
            // Concours
            'concours'               => 'Concours',
            'competition'            => 'Concours',
            // Mentorat / tutorat / accompagnement (nouveaux)
            'mentorat'               => 'Mentorat',
            'mentor'                 => 'Mentorat',
            'tutorat'                => 'Tutorat',
            'tuteur'                 => 'Tutorat',
            'accompagnement'         => 'Accompagnement',
            'coaching'               => 'Accompagnement',
            // Formation (nouveau)
            'formation'              => 'Formation',
            'workshop'               => 'Formation',
            'atelier'                => 'Formation',
        ];

        $lower = mb_strtolower(trim($rawType));

        // Cherche une correspondance dans la map (premier match gagne)
        foreach ($typeMap as $pattern => $normalized) {
            if (str_contains($lower, $pattern)) {
                return $normalized;
            }
        }

        // Le type est peut-être déjà dans le format canonique — retour tel quel
        $validTypes = [
            'Résidence', 'Bourse', 'Appel à projets', 'Appel à candidatures',
            'Prix', 'Financement', 'Concours', 'Mentorat', 'Tutorat',
            'Accompagnement', 'Formation',
        ];
        if (in_array($rawType, $validTypes, true)) {
            return $rawType;
        }

        // Fallback : type générique
        return 'Autre';
    }
}

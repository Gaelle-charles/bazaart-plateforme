<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;
use Psr\Log\LoggerInterface;
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
     * 2000 tokens ≈ ~8 opportunités bien décrites, largement suffisant.
     */
    private const MAX_TOKENS = 2000;

    /**
     * Taille maximale du texte envoyé au LLM (en caractères).
     * Limite : claude-haiku supporte ~190k tokens = ~800k chars.
     * On reste à 12000 chars pour maîtriser les coûts.
     */
    private const MAX_TEXT_LENGTH = 12000;

    public function __construct(
        // Client HTTP Symfony (symfony/http-client) — injecté automatiquement par autowiring
        private readonly HttpClientInterface $httpClient,
        // Service de paramètres — pour lire la clé API depuis la BDD
        private readonly SettingService $settingService,
        // Logger PSR-3 — pour tracer les erreurs sans lever d'exception
        private readonly LoggerInterface $logger,
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
                'url'       => $url !== '' ? $url : null,
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
     * Extrait les opportunités artistiques d'un contenu HTML.
     *
     * Retourne un tableau de ScrapedOpportunity[] (peut être vide en cas d'erreur).
     * Ne lève JAMAIS d'exception — log l'erreur et retourne [].
     *
     * NOUVEAU : choisit le provider LLM selon le paramètre BDD 'llm_provider' :
     *   - 'mistral'   → callMistralApi() (Mistral Small 3.2, response_format json natif)
     *   - 'anthropic' → callAnthropicApi() (Claude Haiku, comportement historique)
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
                return $this->callMistralApi($cleanText, $sourceUrl, $sourceSite);
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

            return $this->callAnthropicApi($apiKey, $cleanText, $sourceUrl, $sourceSite);

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
     * Nettoie le HTML pour ne garder que le texte brut.
     *
     * Étapes :
     *   1. Supprime les balises <script>, <style>, <nav>, <header>, <footer>, <aside> et leur contenu
     *   2. Supprime les autres balises HTML (strip_tags)
     *   3. Décode les entités HTML (&amp; → &, &nbsp; → espace, etc.)
     *   4. Normalise les espaces multiples
     *   5. Tronque à MAX_TEXT_LENGTH pour maîtriser les coûts LLM
     *
     * Pourquoi supprimer nav/header/footer/aside ?
     *   Ces blocs contiennent de la navigation, des menus, des pieds de page —
     *   du texte parasites pour le LLM qui cherche des appels à candidatures.
     *   Les supprimer avant strip_tags améliore la qualité du signal textuel.
     */
    private function cleanHtml(string $html): string
    {
        // Supprimer les blocs de navigation/structure inutiles pour le LLM.
        // L'ordre n'a pas d'importance — on supprime tout en une boucle.
        foreach (['script', 'style', 'nav', 'header', 'footer', 'aside'] as $tag) {
            $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html) ?? $html;
        }

        // Supprimer toutes les balises HTML restantes
        $text = strip_tags($html);

        // Décoder les entités HTML (&amp; → &, &eacute; → é, etc.)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normaliser les espaces : remplacer tous les whitespace consécutifs par un seul espace
        // \s+ matche espaces, tabulations, retours à la ligne
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        // Tronquer à la limite pour éviter de dépasser les coûts LLM
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH);
        }

        return $text;
    }

    /**
     * Effectue l'appel HTTP à l'API Anthropic et retourne les opportunités extraites.
     *
     * @throws \Exception En cas d'erreur HTTP ou de JSON invalide
     * @return ScrapedOpportunity[]
     */
    private function callAnthropicApi(
        string $apiKey,
        string $cleanText,
        string $sourceUrl,
        string $sourceSite,
    ): array {
        // ── Construction du prompt système ─────────────────────────────────────
        // Le prompt est en français car les opportunités cibles sont souvent franco-européennes.
        // On demande un JSON structuré pour un parsing fiable côté PHP.
        $systemPrompt = <<<'PROMPT'
Tu es un extracteur d'opportunités artistiques et culturelles. Analyse le contenu fourni et extrait TOUTES les opportunités (appels à projets, résidences, bourses, financements, prix, concours) présentes.

Pour chaque opportunité, retourne un objet JSON avec exactement ces champs :
- titre (string) : titre de l'opportunité
- type (string) : "Résidence" | "Bourse" | "Appel à projets" | "Prix" | "Financement" | "Concours" | "Autre"
- organisme (string) : nom de l'organisme qui propose l'opportunité
- pays (string) : pays de l'organisme (ex: "France", "Belgique", "Suisse", "Europe")
- disciplines (string) : disciplines concernées séparées par des virgules (ex: "Arts plastiques, Musique")
- montant (string) : montant si mentionné (ex: "5 000 €") sinon ""
- publicEligible (string) : public éligible si mentionné sinon ""
- deadline (string) : date limite au format ISO 8601 (AAAA-MM-JJ) si trouvée sinon ""
- description (string) : description courte max 200 caractères
- url (string) : URL de l'opportunité si trouvée sinon celle de la page source

Réponds UNIQUEMENT avec un tableau JSON valide, sans texte autour. Si aucune opportunité trouvée, réponds [].
PROMPT;

        // ── Construction du message utilisateur ────────────────────────────────
        // On inclut l'URL source pour que le LLM puisse l'utiliser comme fallback
        // quand il ne trouve pas de lien spécifique pour une opportunité
        $userMessage = sprintf(
            "URL de la page source : %s\n\nContenu de la page :\n%s",
            $sourceUrl,
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
        // { "content": [{ "type": "text", "text": "[{...}]" }], ... }
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
    private function callMistralApi(string $cleanText, string $sourceUrl, string $sourceSite): array
    {
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
        // On demande explicitement la clé "opportunites" car response_format json_object
        // exige un objet JSON (pas un tableau direct) — {"opportunites": [...]} est la convention.
        $systemPrompt = <<<'PROMPT'
Tu es un extracteur d'opportunités artistiques et culturelles. Analyse le contenu fourni et extrait TOUTES les opportunités (appels à projets, résidences, bourses, financements, prix, concours).

Retourne un objet JSON avec une clé "opportunites" contenant un tableau. Chaque élément a exactement ces champs :
- titre (string) : titre de l'opportunité
- type (string) : "Résidence" | "Bourse" | "Appel à projets" | "Prix" | "Financement" | "Concours" | "Autre"
- organisme (string) : organisme proposant l'opportunité
- pays (string) : pays de l'organisme (ex: "France", "Belgique", "Europe")
- disciplines (string) : disciplines concernées, séparées par des virgules
- montant (string) : montant si mentionné, sinon ""
- publicEligible (string) : public éligible si mentionné, sinon ""
- deadline (string) : date limite ISO 8601 (AAAA-MM-JJ) si trouvée, sinon ""
- description (string) : description courte max 200 caractères
- url (string) : URL de l'opportunité ou URL de la page source si introuvable

Si aucune opportunité trouvée, retourne {"opportunites": []}.
PROMPT;

        $userMessage = sprintf(
            "URL de la page source : %s\n\nContenu :\n%s",
            $sourceUrl,
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
     *   titre       → title
     *   type        → type
     *   url         → url
     *   description → description
     *   deadline    → deadline (string ISO 8601 ou vide)
     *   disciplines → disciplines
     *   (documents non cherchés par le LLM → string vide)
     *   (relevanceScore → 0, recalculé par AfrodiasporaRelevanceScorer dans la commande)
     *
     * @param array<int, array<string, string>> $items     Items JSON du LLM
     * @param string                            $sourceUrl URL de la page source
     * @param string                            $sourceSite Nom du site
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
            $title = trim((string) ($item['titre'] ?? ''));
            if (empty($title)) {
                continue;
            }

            // Récupération de l'URL — fallback sur sourceUrl si le LLM n'en a pas trouvé
            $url = trim((string) ($item['url'] ?? ''));
            if (empty($url)) {
                $url = $sourceUrl;
            }

            // Enrichissement de la description avec le pays et le montant si disponibles
            $description = trim((string) ($item['description'] ?? ''));
            $organisme   = trim((string) ($item['organisme'] ?? ''));
            $pays        = trim((string) ($item['pays'] ?? ''));
            $montant     = trim((string) ($item['montant'] ?? ''));

            // Construction d'un contexte supplémentaire à ajouter à la description
            $contextParts = [];
            if (!empty($organisme)) {
                $contextParts[] = $organisme;
            }
            if (!empty($pays)) {
                $contextParts[] = $pays;
            }
            if (!empty($montant)) {
                $contextParts[] = 'Montant : ' . $montant;
            }

            if (!empty($contextParts)) {
                $context = implode(' · ', $contextParts);
                // Ajouter le contexte au début de la description (séparé par un tiret)
                $description = !empty($description)
                    ? $context . ' — ' . $description
                    : $context;
            }

            // Tronquer la description à 200 caractères — convention du projet (A2 corrigé).
            // La valeur 300 qui existait ici était incohérente avec le commentaire "200 chars".
            // 200 chars est la limite affichée dans l'interface admin (colonne description).
            $description = mb_substr($description, 0, 200);

            $opportunities[] = new ScrapedOpportunity(
                title: $title,
                type: $this->normalizeType((string) ($item['type'] ?? '')),
                url: $url,
                source: $sourceSite,
                description: $description,
                deadline: trim((string) ($item['deadline'] ?? '')),
                disciplines: trim((string) ($item['disciplines'] ?? '')),
                documents: '', // Le LLM ne cherche pas les PDFs — laissé vide
                relevanceScore: 0, // Sera recalculé par AfrodiasporaRelevanceScorer
            );
        }

        return $opportunities;
    }

    /**
     * Normalise le type retourné par le LLM vers les valeurs attendues du projet.
     *
     * Le LLM peut retourner des variantes imprévues ("bourse" au lieu de "Bourse",
     * "opportunity" en anglais, etc.). On mappe vers les types standards.
     */
    private function normalizeType(string $rawType): string
    {
        // Liste des types valides et leurs variantes
        $typeMap = [
            'résidence'       => 'Résidence',
            'residence'       => 'Résidence',
            'bourse'          => 'Bourse',
            'aide'            => 'Bourse',
            'grant'           => 'Bourse',
            'appel'           => 'Appel à projets',
            'appel à projets' => 'Appel à projets',
            'call'            => 'Appel à projets',
            'prix'            => 'Prix',
            'award'           => 'Prix',
            'financement'     => 'Financement',
            'concours'        => 'Concours',
            'competition'     => 'Concours',
        ];

        $lower = mb_strtolower(trim($rawType));

        // Cherche une correspondance dans la map
        foreach ($typeMap as $pattern => $normalized) {
            if (str_contains($lower, $pattern)) {
                return $normalized;
            }
        }

        // Retourne le type tel quel s'il est déjà dans le format attendu
        $validTypes = ['Résidence', 'Bourse', 'Appel à projets', 'Prix', 'Financement', 'Concours'];
        if (in_array($rawType, $validTypes, true)) {
            return $rawType;
        }

        // Fallback : type générique
        return 'Autre';
    }
}

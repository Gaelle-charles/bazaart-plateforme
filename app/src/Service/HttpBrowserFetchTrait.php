<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HttpBrowserFetchTrait — Centralise les en-têtes navigateur réalistes et la logique de retry.
 *
 * PROBLÈME RÉSOLU :
 *   Plusieurs services du pipeline de scraping (OpportunityEnrichmentService,
 *   LogoFetcherService, ListingUrlDiscoverer, GenericScraper, AbstractScraper)
 *   effectuent des requêtes GET vers des sites tiers. Chacun définissait ses propres
 *   en-têtes de façon légèrement différente, et aucun ne gérait les retries.
 *
 *   Conséquence : les sites qui vérifient les en-têtes HTTP (Sec-Fetch-*, Cache-Control,
 *   Upgrade-Insecure-Requests) retournent 403/429 au lieu de 200.
 *
 * SOLUTION :
 *   Ce trait fournit :
 *   1. buildBrowserHeaders() : bloc d'en-têtes Chrome 124 complet et cohérent
 *      → Couvre les vérifications anti-bot les plus courantes
 *   2. requestWithRetry() : wrapper avec 3 tentatives et backoff exponentiel
 *      → Relance sur timeout (TransportException), 429 et 5xx
 *      → Ne relance jamais plus de 3 fois (borne stricte)
 *
 * UTILISATION :
 *   use App\Service\HttpBrowserFetchTrait;
 *
 *   class MonService {
 *       use HttpBrowserFetchTrait;
 *
 *       public function maMethode(): void {
 *           $response = $this->requestWithRetry(
 *               $this->httpClient,
 *               $this->logger, // peut être null si le service n'a pas de logger
 *               'GET',
 *               'https://example.com',
 *               ['timeout' => 20, 'max_redirects' => 5]
 *           );
 *       }
 *   }
 *
 * SECURITE :
 *   Ce trait ne gère PAS les gardes SSRF (isSafeHost) — chaque service conserve
 *   sa propre logique SSRF. Ce trait se contente de standardiser les en-têtes et retries.
 *
 * IMPORTANT — Accept-Encoding intentionnellement ABSENT :
 *   Quand Accept-Encoding est posé manuellement dans les headers Symfony HttpClient,
 *   le client bypass sa décompression automatique → getContent() retourne des octets
 *   gzip bruts (~25% de la taille réelle), ce qui casse DomCrawler et les LLM downstream.
 *   Symfony HttpClient négocie et décompresse automatiquement — on ne l'interfère pas.
 */
trait HttpBrowserFetchTrait
{
    /**
     * Construit un bloc d'en-têtes HTTP imitant Chrome 124 sur Windows 11.
     *
     * POURQUOI CES EN-TÊTES SPÉCIFIQUES ?
     *   Les sites anti-bot (Cloudflare, Akamai, protections custom) vérifient :
     *   - User-Agent : doit ressembler à un navigateur récent (Chrome 124+ en 2025)
     *   - Accept : doit inclure "image/avif,image/webp" (présent dans Chrome, pas dans curl)
     *   - Sec-Fetch-* : headers de sécurité envoyés automatiquement par les navigateurs
     *     depuis la navigation classique (Dest=document, Mode=navigate, Site=none = onglet direct)
     *   - Upgrade-Insecure-Requests : signal de navigateur modern (valeur "1")
     *   - Cache-Control : "max-age=0" = comportement d'un rechargement de page (F5)
     *
     * COHÉRENCE Chrome 124 :
     *   Les headers Sec-Fetch-* doivent être cohérents entre eux :
     *   - Sec-Fetch-Site: none  → visite directe (pas depuis un autre site)
     *   - Sec-Fetch-Mode: navigate → navigation de premier niveau (onglet/adresse bar)
     *   - Sec-Fetch-Dest: document → on attend un document HTML
     *   - Sec-Fetch-User: ?1  → requête initiée par l'utilisateur (click ou saisie)
     *   Cette combinaison est caractéristique d'une visite directe via la barre d'adresse.
     *
     * ABSENCE D'Accept-Encoding (voir doc du trait pour l'explication).
     *
     * @return array<string, string> Tableau d'en-têtes prêt à être passé à 'headers' dans HttpClient
     */
    private function buildBrowserHeaders(): array
    {
        return [
            // ── Identité navigateur ──────────────────────────────────────────────
            // Chrome 124 sur Windows 11 — agent courant en 2025, peu suspect
            'User-Agent'               => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/124.0.0.0 Safari/537.36',

            // ── Types de contenu acceptés ────────────────────────────────────────
            // Format exact de Chrome 124 — les sites vérifient l'ordre ET les valeurs
            'Accept'                   => 'text/html,application/xhtml+xml,application/xml;'
                . 'q=0.9,image/avif,image/webp,*/*;q=0.8',

            // ── Langue préférée ──────────────────────────────────────────────────
            // Français en priorité (pertinent pour les sites cibles de Bazaart),
            // anglais en fallback (couvre les sites internationaux)
            'Accept-Language'          => 'fr-FR,fr;q=0.9,en;q=0.8',

            // ── Comportement de cache ────────────────────────────────────────────
            // max-age=0 = "j'accepte le cache mais je vérifie sa fraîcheur"
            // Cohérent avec F5 (rechargement) ou navigation directe
            'Cache-Control'            => 'max-age=0',

            // ── Signal navigateur moderne ────────────────────────────────────────
            // "1" = le navigateur veut upgrader les ressources HTTP en HTTPS
            // Présent dans tous les navigateurs modernes — son absence est suspecte
            'Upgrade-Insecure-Requests'=> '1',

            // ── Headers Sec-Fetch (Fetch Metadata Request Headers, RFC 2965) ─────
            // Ces headers sont ajoutés automatiquement par les navigateurs depuis Chrome 76.
            // Leur absence dans une requête "navigateur" est un signal fort de bot.
            //
            // Sec-Fetch-Site: none → la requête vient directement (barre d'adresse),
            //   pas depuis un autre site (cross-site) ni depuis le même site (same-origin)
            'Sec-Fetch-Site'           => 'none',
            //
            // Sec-Fetch-Mode: navigate → navigation de premier niveau (ouverture de page)
            //   par opposition à "cors", "no-cors", "websocket" pour les sous-ressources
            'Sec-Fetch-Mode'           => 'navigate',
            //
            // Sec-Fetch-User: ?1 → la requête a été initiée par une action utilisateur
            //   (click, frappe dans la barre d'adresse) — par opposition à un script automatique
            'Sec-Fetch-User'           => '?1',
            //
            // Sec-Fetch-Dest: document → on attend un document HTML complet
            //   par opposition à "image", "script", "style", "fetch", etc.
            'Sec-Fetch-Dest'           => 'document',
        ];
    }

    /**
     * Effectue une requête HTTP avec retry automatique (3 tentatives max).
     *
     * STRATÉGIE DE RETRY :
     *   - Tentative 1 : immédiate
     *   - Tentative 2 : attente 1 seconde (backoff exponentiel base 2)
     *   - Tentative 3 : attente 2 secondes
     *   → Maximum 3 tentatives au total (pas d'attente après la 3e)
     *
     * QUAND RETENTER ?
     *   On retente uniquement sur les erreurs transitoires (pouvant se corriger seules) :
     *   - TransportExceptionInterface : timeout réseau, connexion refusée, DNS temporaire
     *   - HTTP 429 (Too Many Requests) : le site demande d'espacer les requêtes
     *   - HTTP 5xx (500-599) : erreur serveur temporaire (502 Bad Gateway, 503 Service Unavailable)
     *
     * QUAND NE PAS RETENTER ?
     *   - HTTP 4xx sauf 429 : 403 Forbidden, 404 Not Found, 410 Gone → définitif
     *   - HTTP 301/302 : les redirections sont gérées par max_redirects de HttpClient
     *
     * RETOUR :
     *   ResponseInterface si la réponse est disponible (même si code HTTP non-200)
     *   null si toutes les tentatives ont échoué (timeout, DNS, etc. sur les 3 tries)
     *
     * @param HttpClientInterface                $client     Client HTTP Symfony injecté
     * @param LoggerInterface|null               $logger     Logger PSR-3 pour tracer les retries (null = silencieux)
     * @param string                             $method     Méthode HTTP ('GET', 'POST', etc.)
     * @param string                             $url        URL cible
     * @param array<string, mixed>               $options    Options Symfony HttpClient (headers, timeout, etc.)
     * @param int                                $maxRetries Nombre max de tentatives (defaut: 3, inclut la 1re)
     * @return ResponseInterface|null Réponse HTTP ou null si toutes les tentatives ont echoue
     */
    private function requestWithRetry(
        HttpClientInterface $client,
        ?LoggerInterface $logger,
        string $method,
        string $url,
        array $options = [],
        int $maxRetries = 3,
    ): ?ResponseInterface {
        // On limite à 3 tentatives maximum quoi qu'il arrive
        $maxRetries = min($maxRetries, 3);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response   = $client->request($method, $url, $options);
                $statusCode = $response->getStatusCode();

                // ── Criteres de retry sur code HTTP ───────────────────────────────
                // HTTP 429 : le serveur demande de ralentir (rate limit)
                // HTTP 5xx : erreur serveur temporaire (502, 503, 504 tres courants)
                $shouldRetry = ($statusCode === 429 || ($statusCode >= 500 && $statusCode < 600));

                if (!$shouldRetry || $attempt === $maxRetries) {
                    // On retourne la reponse meme si le code n'est pas 200 —
                    // l'appelant decidera de la gestion (certains 4xx sont normaux).
                    return $response;
                }

                // Retry sur 429/5xx : on attend avant de relancer
                $waitSeconds = 2 ** ($attempt - 1); // 1s, 2s, 4s
                if ($logger !== null) {
                    $logger->info(
                        sprintf(
                            '[HttpBrowserFetch] Retry %d/%d apres HTTP %d (attente %ds) — %s',
                            $attempt,
                            $maxRetries,
                            $statusCode,
                            $waitSeconds,
                            $url
                        )
                    );
                }
                sleep($waitSeconds);

            } catch (TransportExceptionInterface $e) {
                // Erreur reseau (timeout, DNS, connexion refusee, SSL)
                // On retente sauf si c'est le dernier essai
                if ($attempt === $maxRetries) {
                    if ($logger !== null) {
                        $logger->warning(
                            sprintf(
                                '[HttpBrowserFetch] Echec definitif apres %d tentative(s) — %s : %s',
                                $maxRetries,
                                $url,
                                $e->getMessage()
                            )
                        );
                    }
                    return null;
                }

                $waitSeconds = 2 ** ($attempt - 1); // 1s, 2s
                if ($logger !== null) {
                    $logger->info(
                        sprintf(
                            '[HttpBrowserFetch] Retry %d/%d apres exception reseau (attente %ds) — %s : %s',
                            $attempt,
                            $maxRetries,
                            $waitSeconds,
                            $url,
                            $e->getMessage()
                        )
                    );
                }
                sleep($waitSeconds);
            }
        }

        // Ne devrait jamais etre atteint (la boucle retourne ou null dans le catch)
        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * SsrfGuard — Garde-fou centralisé de protection contre les attaques SSRF
 * (Server-Side Request Forgery).
 *
 * ── POURQUOI CE SERVICE EXISTE (correctif SSRF critique, relecture ADR-0034) ──
 * La logique isSafeHost() vivait uniquement dans ListingUrlDiscoverer, qui n'était
 * PAS injecté dans le chemin d'auto-validation ADR-0034 (SuggestedSourceAutoValidationService
 * → FeedDetectorService). Résultat : une URL découverte par le LLM (jamais revue par
 * un humain) pouvait déclencher un fetch HTTP vers localhost, 127.0.0.1, les métadonnées
 * cloud (169.254.169.254) ou un réseau privé RFC1918 (10.x, 172.16-31.x, 192.168.x),
 * SANS aucune vérification.
 *
 * On extrait donc la garde SSRF dans ce service PARTAGÉ (SRP + pas de duplication),
 * réutilisable par tous les services qui font des requêtes HTTP vers des URLs
 * fournies par un tiers (LLM, HTML scrapé, CSV externe, admin) :
 *   - ListingUrlDiscoverer (délègue maintenant à ce service)
 *   - FeedDetectorService  (durci dans ce même lot, cf. ADR-0034 correctif SSRF)
 *   - SuggestedSourceAutoValidationService (nouveau garde, cf. ADR-0034 correctif SSRF)
 *   - LogoFetcherService, GenericScraper, OpportunityEnrichmentService (via
 *     ListingUrlDiscoverer::isSafeHost(), qui délègue ici — comportement inchangé)
 *
 * ── CE QUE BLOQUE isSafeHost() ─────────────────────────────────────────────────
 *   - Schéma non http/https (file://, ftp://, data://, etc.)
 *   - Hôte vide ou absent
 *   - "localhost" (toutes formes)
 *   - 127.0.0.0/8 (loopback IPv4), ::1 (loopback IPv6), 0.0.0.0
 *   - 169.254.0.0/16 (link-local — métadonnées cloud AWS/DigitalOcean/GCP/Azure)
 *   - 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 (RFC1918 — réseaux privés)
 *
 * REMARQUE : pas de résolution DNS ici (pas de gethostbyname — trop lent en batch).
 * La protection contre le DNS-rebinding / redirection interne se fait en vérifiant
 * l'URL EFFECTIVE après redirection (via $response->getInfo('url')) côté appelant —
 * voir ListingUrlDiscoverer::doFetch()/scoreUrl(), LogoFetcherService::fetchPage(),
 * et désormais FeedDetectorService::fetchAndInspect().
 */
class SsrfGuard
{
    /**
     * Vérifie qu'une URL est sûre à requêter (protection SSRF).
     *
     * Méthode PURE (aucune dépendance, aucun effet de bord) — facilite les tests
     * unitaires et l'utilisation dans des contextes variés (services HTTP, commandes).
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
}

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
 * PIPELINE DE FILTRAGE (dans l'ordre) :
 *   1. extractLinks()           — tous les <a href> de la page (via DomCrawler)
 *   2. filterNoiseDomains()     — supprime les réseaux sociaux, Google, CDN, etc.
 *   3. filterInternalLinks()    — supprime les liens vers le même domaine que l'agrégateur
 *   4. deduplicateByDomain()    — un seul lien par domaine (le plus long en texte ancre)
 *   5. filterKnownDomains()     — supprime les domaines déjà connus en BDD
 *   6. Plafond MAX_CANDIDATES_PER_AGGREGATOR
 *
 * Résultat : le LLM reçoit une liste "Candidat N : "Texte ancre" → https://..."
 * au lieu de 30 000 chars de HTML brut → économie de ~95% des tokens.
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
        // ── Étape 1 : construire le Crawler DomCrawler ────────────────────────
        // DomCrawler parse le HTML avec l'extension PHP DOM (ext-dom, toujours présente).
        // On ne fait PAS de requête réseau ici — le HTML est déjà téléchargé par la commande.
        $crawler = new Crawler($html);

        // ── Étape 2 : extraire tous les liens <a href> ───────────────────────
        $candidates = $this->extractLinks($crawler);

        // ── Étape 3 : supprimer les domaines de bruit ────────────────────────
        $candidates = $this->filterNoiseDomains($candidates);

        // ── Étape 4 : supprimer les liens internes ───────────────────────────
        $candidates = $this->filterInternalLinks($candidates, $aggregatorUrl);

        // ── Étape 5 : dédupliquer par domaine ────────────────────────────────
        $candidates = $this->deduplicateByDomain($candidates);

        // ── Étape 6 : supprimer les domaines déjà connus en BDD ─────────────
        $candidates = $this->filterKnownDomains($candidates, $knownDomains);

        // ── Étape 7 : appliquer le plafond ───────────────────────────────────
        // On log l'info AVANT de tronquer pour que les statistiques soient exactes.
        if (count($candidates) > self::MAX_CANDIDATES_PER_AGGREGATOR) {
            $this->logger->info('[LinkExtractor] Plafond appliqué.', [
                'avant'  => count($candidates),
                'retenu' => self::MAX_CANDIDATES_PER_AGGREGATOR,
                'url'    => $aggregatorUrl,
            ]);
            $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES_PER_AGGREGATOR);
        }

        return $candidates;
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
     * Règles d'exclusion (un lien est ignoré si...) :
     *   - href manquant, vide, ou espace seul
     *   - href commence par '#'     → ancre interne à la page
     *   - href commence par 'mailto:' → lien email
     *   - href commence par 'tel:'   → lien téléphone
     *   - href commence par 'javascript:' → lien JS
     *   - href ne commence pas par 'http' → chemin relatif (ex: "/about") ignoré
     *     car on ne peut pas extraire un domaine fiable sans connaître la base URL
     *
     * On NE normalise PAS les URLs ici — les étapes suivantes ont besoin de l'URL brute.
     * Seul le texte ancre est nettoyé (trim + normalisation des espaces consécutifs).
     *
     * @param Crawler $crawler Instance DomCrawler pointant sur le HTML de la page
     * @return array<int, array{text: string, url: string}> Liste des liens valides
     */
    private function extractLinks(Crawler $crawler): array
    {
        $links = [];

        // DomCrawler::filter() retourne un nouveau Crawler sur les nœuds <a>
        // each() itère sur chaque nœud et collecte les résultats dans un tableau
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links): void {
            $href = trim($node->attr('href') ?? '');

            // Ignorer les liens vides, les ancres, les schemes non-HTTP
            if (empty($href)) {
                return;
            }
            if (str_starts_with($href, '#')) {
                return; // Ancre interne à la page
            }
            if (str_starts_with($href, 'mailto:')) {
                return; // Lien email
            }
            if (str_starts_with($href, 'tel:')) {
                return; // Lien téléphone
            }
            if (str_starts_with($href, 'javascript:')) {
                return; // Lien JavaScript
            }
            if (!str_starts_with($href, 'http')) {
                // Chemin relatif (ex: "/about", "../contact") — on ne peut pas
                // résoudre la base URL sans la passer en paramètre, et les liens
                // relatifs sont presque toujours des liens internes au site agrégateur.
                return;
            }

            // Nettoyage du texte ancre : trim + collapsing des espaces multiples
            // (les <a> peuvent contenir des retours à la ligne et des espaces en cascade)
            $text = preg_replace('/\s+/', ' ', trim($node->text())) ?? '';

            $links[] = [
                'text' => $text,
                'url'  => $href,
            ];
        });

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
        $filtered = array_filter($links, function (array $link): bool {
            $host = parse_url($link['url'], PHP_URL_HOST);

            // Si le host n'est pas parseable, on garde le lien (cas rare d'URL bizarre)
            if (!is_string($host)) {
                return true;
            }

            $host = strtolower($host);

            // Exclure si le host CONTIENT un des fragments de bruit
            foreach (self::NOISE_DOMAINS as $noiseDomain) {
                if (str_contains($host, $noiseDomain)) {
                    return false; // Lien de bruit → à exclure
                }
            }

            return true; // Pas de fragment de bruit → à conserver
        });

        // array_filter préserve les clés d'origine — on réindexe pour un tableau propre
        return array_values($filtered);
    }

    /**
     * Supprime les liens internes (même domaine que la page agrégateur).
     *
     * Exemple : si l'agrégateur est "on-the-move.org", on supprime tous les
     * liens vers "on-the-move.org/*" — ce sont des pages du site lui-même,
     * pas des sources tierces à explorer.
     *
     * Comparaison basée sur le host uniquement (pas le path) pour couvrir tous
     * les sous-chemins du même site (/, /about, /ressources, etc.).
     *
     * Si le host de l'agrégateur n'est pas parseable (rare), on ne filtre rien
     * par sécurité — vaut mieux conserver trop que trop peu.
     *
     * @param array<int, array{text: string, url: string}> $links
     * @param string $aggregatorUrl URL de l'agrégateur (pour extraire son host)
     * @return array<int, array{text: string, url: string}>
     */
    private function filterInternalLinks(array $links, string $aggregatorUrl): array
    {
        // Extraire le host de l'agrégateur pour la comparaison
        $aggregatorHost = parse_url($aggregatorUrl, PHP_URL_HOST);

        if (!is_string($aggregatorHost)) {
            // Host de l'agrégateur non parseable — impossible de filtrer les liens internes
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
            // en normalisant www. sur les deux côtés
            $linkHostNorm = str_starts_with(strtolower($linkHost), 'www.')
                ? substr(strtolower($linkHost), 4)
                : strtolower($linkHost);
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
}

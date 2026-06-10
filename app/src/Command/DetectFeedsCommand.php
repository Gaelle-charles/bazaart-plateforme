<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ScrapingSourceType;
use App\Repository\ScrapingSourceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * DetectFeedsCommand — Détection automatique des flux RSS/Atom pour les sources non-RSS.
 *
 * Nom de commande : app:detect-feeds
 *
 * ⚠️ COMMANDE EN LECTURE SEULE — AUCUNE ÉCRITURE EN BASE DE DONNÉES ⚠️
 * Cette commande ne modifie rien en BDD : pas de persist(), pas de flush(),
 * pas de modification d'entité ScrapingSource. Elle affiche uniquement un tableau
 * récapitulatif pour aider l'admin à prendre des décisions.
 *
 * ── CE QUE CETTE COMMANDE FAIT ──────────────────────────────────────────────
 *   1. Charge les sources de type ≠ RSS (HtmlLlm, HtmlCss) depuis la BDD
 *   2. Pour chaque source, télécharge la page HTML principale
 *   3. Cherche des balises <link rel="alternate" type="application/rss+xml|atom+xml">
 *      dans le <head> de la page (méthode fiable, standard W3C)
 *   4. Si rien trouvé, teste 6 chemins courants (/feed, /rss, /rss.xml, /atom.xml, /feed.xml, /index.xml)
 *   5. Affiche un tableau SymfonyStyle avec les candidats trouvés
 *
 * ── CE QUE CETTE COMMANDE NE FAIT PAS ───────────────────────────────────────
 *   - Elle ne bascule PAS la source en type RSS
 *   - Elle ne remplit PAS le champ feedUrl
 *   - Elle ne persiste RIEN en BDD
 *
 * ── WORKFLOW ATTENDU ─────────────────────────────────────────────────────────
 *   1. Lancer : docker compose exec app php bin/console app:detect-feeds
 *   2. Consulter le tableau affiché dans le terminal
 *   3. Pour chaque source avec un candidat intéressant :
 *      → Aller sur /admin/scraping-sources
 *      → Éditer la source
 *      → Changer le type en "Flux RSS"
 *      → Renseigner feedUrl avec l'URL du candidat
 *      → Sauvegarder
 *   4. La prochaine exécution de app:read-feeds utilisera ce nouveau flux RSS
 *
 * ── OPTIONS ─────────────────────────────────────────────────────────────────
 *   --all        : inclut aussi les sources déjà de type RSS (pour re-vérifier)
 *   --source=NOM : filtre par nom exact de source (utile pour déboguer une source spécifique)
 *
 * Lancement manuel :
 *   docker compose exec app php bin/console app:detect-feeds
 *   docker compose exec app php bin/console app:detect-feeds --all
 *   docker compose exec app php bin/console app:detect-feeds --source="CNAP"
 */
#[AsCommand(
    name: 'app:detect-feeds',
    description: 'Détecte les flux RSS/Atom disponibles pour les sources non-RSS (lecture seule — N\'écrit rien en BDD)',
)]
class DetectFeedsCommand extends Command
{
    /**
     * Timeout HTTP en secondes pour le téléchargement de la page HTML principale.
     * Cohérent avec FeedReaderService::FETCH_TIMEOUT = 15s.
     */
    private const FETCH_TIMEOUT = 15;

    /**
     * Timeout HTTP réduit pour les vérifications de chemins courants (HEAD ou GET léger).
     * Plus court que FETCH_TIMEOUT car on ne lit pas le contenu complet de la réponse.
     * 8s est suffisant pour savoir si le chemin répond ou non.
     */
    private const PROBE_TIMEOUT = 8;

    /**
     * Chemins courants à tester si aucune balise <link> n'a été trouvée.
     *
     * Ces chemins sont relatifs au domaine racine de la source (pas au path de la page).
     * Exemple : pour https://example.com/actualites, on teste https://example.com/feed
     * (pas https://example.com/actualites/feed).
     *
     * Ordre : du plus courant (WordPress /feed) au plus rare (/index.xml Atom).
     * Ces 6 chemins couvrent environ 80% des sites utilisant WordPress, Jekyll, Hugo,
     * Ghost, Drupal, SPIP — les CMS les plus répandus dans le milieu culturel FR.
     */
    private const COMMON_FEED_PATHS = [
        '/feed',
        '/rss',
        '/rss.xml',
        '/atom.xml',
        '/feed.xml',
        '/index.xml',
    ];

    public function __construct(
        // Repository pour charger les sources depuis la BDD
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // Client HTTP Symfony — même client que FeedReaderService (cohérence du User-Agent)
        private readonly HttpClientInterface $httpClient,
    ) {
        // ⚠️ parent::__construct() OBLIGATOIRE dans les commandes Symfony qui utilisent
        // constructor property promotion. Sans cet appel, la commande n'est pas
        // correctement initialisée par le conteneur de services.
        parent::__construct();
    }

    /**
     * Déclare les options de la commande.
     *
     * Conventions reprises de ReadFeedsCommand pour une expérience cohérente :
     *   --source  : filtre par nom exact (même syntaxe que app:read-feeds)
     *   --all     : option spécifique à cette commande pour inclure aussi les sources RSS
     */
    protected function configure(): void
    {
        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'Inclut aussi les sources de type RSS (pour vérifier si leur feedUrl est toujours valide)'
        );

        $this->addOption(
            'source',
            null,
            InputOption::VALUE_REQUIRED,
            'Lance uniquement la source dont le nom correspond exactement (ex: --source="CNAP")'
        );
    }

    /**
     * Point d'entrée principal de la commande.
     *
     * ⚠️ RAPPEL : cette méthode execute() ne fait AUCUNE écriture en BDD.
     * Toute la logique est en lecture seule — on affiche, on ne modifie pas.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ── Lecture des options ──────────────────────────────────────────────
        // Casts explicites car getOption() retourne mixed (requis par PHPStan niveau 6)
        $includeAll   = (bool) $input->getOption('all');
        $rawFilter    = $input->getOption('source');
        $sourceFilter = is_string($rawFilter) ? $rawFilter : null;

        $io->title('BazaArt — Détection de flux RSS/Atom');

        // Rappel visible que la commande ne touche pas la BDD
        $io->note(
            "LECTURE SEULE — Cette commande n'écrit rien en base de données.\n"
            . "Pour appliquer les changements, éditez la source depuis /admin/scraping-sources."
        );

        // ── Chargement des sources ────────────────────────────────────────────
        // findAllOrderedByNom() retourne TOUTES les sources (actives + inactives)
        // car on veut aussi pouvoir détecter des flux sur des sources temporairement désactivées.
        // C'est intentionnel : un admin qui désactive une source HtmlLlm en attendant de trouver
        // son flux RSS peut lancer cette commande pour trouver le flux avant de réactiver.
        $allSources = $this->scrapingSourceRepository->findAllOrderedByNom();

        // ── Filtrage par type ─────────────────────────────────────────────────
        // Par défaut : on cible les sources NON-RSS (HtmlLlm + HtmlCss).
        // Avec --all : on inclut aussi les RSS pour permettre de re-vérifier.
        if (!$includeAll) {
            $allSources = array_filter(
                $allSources,
                static fn ($s) => $s->getType() !== ScrapingSourceType::RSS
            );
        }

        // ── Filtrage --source si fourni ───────────────────────────────────────
        if ($sourceFilter !== null) {
            $allSources = array_filter(
                $allSources,
                static fn ($s) => $s->getNom() === $sourceFilter
            );
        }

        // Renumérotation du tableau (array_filter conserve les clés d'origine)
        $sources = array_values($allSources);

        if (empty($sources)) {
            $io->warning(
                $sourceFilter !== null
                    ? sprintf('Aucune source trouvée avec le nom "%s".', $sourceFilter)
                    : 'Aucune source à analyser. Toutes les sources sont déjà de type RSS (utilisez --all pour les inclure).'
            );
            return Command::SUCCESS;
        }

        $io->text(sprintf(
            '<info>%d source(s) à analyser%s.</info>',
            count($sources),
            $includeAll ? ' (toutes, incluant RSS)' : ' (type ≠ RSS)'
        ));

        // ── Boucle d'analyse ──────────────────────────────────────────────────
        // Chaque source passe par :
        //   1. Téléchargement de sa page HTML principale
        //   2. Détection par balises <link> dans le <head>
        //   3. Si rien trouvé : sondage des chemins courants
        //
        // Les résultats sont agrégés dans $tableRows pour l'affichage final.

        /** @var array<int, array<int, string>> $tableRows */
        $tableRows = [];

        // Compteurs pour le résumé final
        $sourcesWithCandidates = 0;

        foreach ($sources as $source) {
            $io->text(sprintf('  Analyse de : <comment>%s</comment>...', $source->getNom()));

            // ── Étape 1 : téléchargement de la page principale ────────────────
            $pageHtml = $this->fetchPageHtml($source->getUrl());

            if ($pageHtml === null) {
                // Échec de fetch → on note dans le tableau et on passe à la suivante.
                // Pas d'exception fatale : une source inaccessible ne doit pas bloquer
                // l'analyse des autres.
                $tableRows[] = $this->buildTableRow(
                    nom: $source->getNom(),
                    url: $source->getUrl(),
                    type: $source->getType()->label(),
                    hasFeedUrl: $source->getFeedUrl() !== null,
                    candidates: [],
                    error: 'page inaccessible'
                );
                continue;
            }

            // ── Étape 2 : détection par balises <link> dans le <head> ────────
            // C'est la méthode la plus fiable : les sites qui ont un flux RSS
            // annoncent généralement son URL via <link rel="alternate"> dans le <head>.
            // On utilise DomCrawler plutôt qu'une regex pour :
            //   - éviter les faux positifs sur du HTML malformé
            //   - gérer proprement l'attribut href (quotes simples, doubles, sans quotes)
            //   - bénéficier de la robustesse de la bibliothèque (déjà une dépendance du projet)
            $candidatesFromLinks = $this->detectFeedFromLinkTags($pageHtml, $source->getUrl());

            if (!empty($candidatesFromLinks)) {
                // Trouvé via <link> → on n'a pas besoin de tester les chemins courants.
                // Les <link rel="alternate"> sont fiables ; on évite les requêtes superflues.
                $tableRows[] = $this->buildTableRow(
                    nom: $source->getNom(),
                    url: $source->getUrl(),
                    type: $source->getType()->label(),
                    hasFeedUrl: $source->getFeedUrl() !== null,
                    candidates: $candidatesFromLinks
                );
                $sourcesWithCandidates++;
                continue;
            }

            // ── Étape 3 : sondage des chemins courants ────────────────────────
            // Seulement si l'étape 2 n'a rien trouvé.
            // On teste les 6 chemins définis dans COMMON_FEED_PATHS.
            // Cette phase génère jusqu'à 6 requêtes HTTP supplémentaires.
            // On est respectueux des serveurs distants : on préfère HEAD (sans corps),
            // et on ne teste ces chemins QUE si l'étape <link> a échoué.
            $candidatesFromPaths = $this->detectFeedFromCommonPaths($source->getUrl());

            $candidates = $candidatesFromPaths;

            $tableRows[] = $this->buildTableRow(
                nom: $source->getNom(),
                url: $source->getUrl(),
                type: $source->getType()->label(),
                hasFeedUrl: $source->getFeedUrl() !== null,
                candidates: $candidates
            );

            if (!empty($candidates)) {
                $sourcesWithCandidates++;
            }
        }

        // ── Affichage du tableau récapitulatif ────────────────────────────────
        $io->section('Résultats de la détection');

        $io->table(
            // En-têtes des colonnes
            ['Source', 'URL page', 'Type actuel', 'feedUrl ?', 'Candidat(s) trouvé(s)'],
            $tableRows
        );

        // ── Note finale pour l'admin ──────────────────────────────────────────
        // On insiste sur la marche à suivre pour ne pas laisser l'admin perplexe
        // devant un tableau sans suite évidente.
        $io->success(sprintf(
            '%d source(s) analysée(s) | %d avec au moins un candidat RSS/Atom.',
            count($sources),
            $sourcesWithCandidates
        ));

        // Rappel de l'action manuelle attendue après consultation du tableau
        $io->note(
            "Pour utiliser un candidat trouvé :\n"
            . "  1. Rendez-vous sur /admin/scraping-sources\n"
            . "  2. Éditez la source concernée\n"
            . "  3. Changez le type en 'Flux RSS'\n"
            . "  4. Renseignez le champ 'feedUrl' avec l'URL du candidat\n"
            . "  5. Sauvegardez\n"
            . "La prochaine exécution de app:read-feeds utilisera ce flux RSS."
        );

        return Command::SUCCESS;
    }

    // ── Méthodes privées d'analyse ────────────────────────────────────────────

    /**
     * Télécharge le HTML de la page principale de la source.
     *
     * Utilise le même User-Agent BazaartBot que FeedReaderService pour la cohérence
     * des requêtes sortantes. Accept text/html car on télécharge une page HTML,
     * pas un flux XML.
     *
     * Retourne null si :
     *   - Code HTTP ≠ 200
     *   - Exception réseau (timeout, DNS, SSL...)
     *
     * ⚠️ Pas d'exception levée — une source inaccessible n'est pas une erreur fatale
     * pour cette commande d'information (les autres sources doivent continuer).
     *
     * @param string $url URL de la page principale de la source
     *
     * @return string|null Contenu HTML brut, ou null en cas d'erreur
     */
    private function fetchPageHtml(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    // User-Agent identifié — cohérent avec FeedReaderService
                    // Les bots mal identifiés sont souvent bloqués par Cloudflare, etc.
                    'User-Agent' => 'BazaartBot (+https://bazaart.fr)',
                    // On demande du HTML (pas du XML comme FeedReaderService)
                    'Accept'     => 'text/html,application/xhtml+xml,*/*',
                ],
                'timeout' => self::FETCH_TIMEOUT,
                // max_redirects = 5 par défaut dans Symfony HttpClient.
                // On laisse la valeur par défaut : les sites culturels FR redirigent souvent
                // de http:// vers https:// ou www. vers non-www.
            ]);

            // On vérifie le code HTTP avant de lire le contenu (évite de télécharger
            // inutilement le corps d'une page d'erreur 404/403/503)
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $response->getContent();

        } catch (TransportExceptionInterface $e) {
            // Erreur réseau (timeout, DNS, SSL) → on retourne null silencieusement
            // La commande note "page inaccessible" dans le tableau
            return null;
        } catch (\Exception $e) {
            // Toute autre exception (ex: erreur de décodage du corps) → même traitement
            return null;
        }
    }

    /**
     * Détecte les flux RSS/Atom via les balises <link rel="alternate"> dans le <head>.
     *
     * Stratégie :
     *   On cherche toutes les balises <link> avec rel="alternate" ET un type
     *   correspondant à RSS ou Atom :
     *     - type="application/rss+xml"   → RSS 2.0
     *     - type="application/atom+xml"  → Atom 1.0
     *
     * C'est la méthode recommandée par le W3C et utilisée par tous les agrégateurs
     * de flux sérieux (Feedly, Inoreader, Firefox...). Elle est plus fiable que les
     * chemins courants car l'URL est explicitement fournie par le site lui-même.
     *
     * Utilisation de DomCrawler plutôt qu'une regex :
     *   - Robustesse face au HTML malformé (balises non fermées, attributs mal ordonnés)
     *   - Gestion automatique des URLs relatives → absolues via resolveUrl()
     *   - symfony/dom-crawler est déjà une dépendance du projet (WS précédents)
     *
     * @param string $html    Contenu HTML brut de la page
     * @param string $baseUrl URL de la page (pour résoudre les URLs relatives en absolues)
     *
     * @return string[] Liste d'URLs de flux candidates (dédupliquées)
     */
    private function detectFeedFromLinkTags(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html);
        $candidates = [];

        // Sélecteur CSS : toutes les balises <link> dans le document (le <head> en priorité
        // mais DomCrawler cherche aussi dans le <body> pour les HTML non standards).
        // On filtre ensuite sur l'attribut type en PHP pour plus de clarté.
        $linkNodes = $crawler->filter('link[rel="alternate"]');

        foreach ($linkNodes as $node) {
            /** @var \DOMElement $node */
            $type = strtolower(trim($node->getAttribute('type')));
            $href = trim($node->getAttribute('href'));

            // On accepte uniquement les types RSS et Atom explicitement déclarés.
            // On ignore les autres types alternate (ex: text/html pour la version canonique,
            // application/json pour JSON Feed, etc.)
            if ($type !== 'application/rss+xml' && $type !== 'application/atom+xml') {
                continue;
            }

            if ($href === '') {
                // Balise <link> sans href → on ignore (HTML invalide)
                continue;
            }

            // ── Résolution des URLs relatives en absolues ─────────────────────
            // Exemple : href="/feed" → https://example.com/feed
            // Exemple : href="rss.xml" → https://example.com/rss.xml
            // Les URLs déjà absolues (https://...) sont retournées telles quelles.
            $absoluteUrl = $this->resolveUrl($href, $baseUrl);

            if ($absoluteUrl !== null) {
                $candidates[] = $absoluteUrl;
            }
        }

        // Déduplication : certains sites déclarent le même flux deux fois (RSS + Atom
        // pointant vers la même URL, ou doublon par erreur de configuration)
        return array_unique($candidates);
    }

    /**
     * Sonde les chemins courants pour trouver un flux RSS/Atom.
     *
     * Cette méthode est appelée UNIQUEMENT si detectFeedFromLinkTags() n'a rien trouvé.
     * Elle génère jusqu'à 6 requêtes HTTP supplémentaires par source.
     *
     * Stratégie de détection :
     *   1. On tente une requête HEAD (économique : pas de corps téléchargé)
     *   2. Si HEAD non supporté (405 Method Not Allowed) → fallback sur GET
     *   3. On considère un chemin candidat si :
     *      - Code HTTP = 200 (pas de 404, 301, etc.)
     *      - Content-Type contient "xml", "rss" ou "atom"
     *
     * La vérification du Content-Type est importante pour éviter les faux positifs :
     * certains sites retournent une page HTML 404 personnalisée avec HTTP 200
     * (soft 404). En vérifiant que le Content-Type est XML, on évite de retenir
     * ces pages HTML comme des flux valides.
     *
     * @param string $sourceUrl URL principale de la source (ex: "https://cnap.fr/appels")
     *
     * @return string[] Liste d'URLs candidates (vide si aucun chemin ne répond)
     */
    private function detectFeedFromCommonPaths(string $sourceUrl): array
    {
        // Extraction du schéma + domaine racine (ex: "https://cnap.fr")
        // On cherche les flux depuis la racine du domaine, pas depuis le path de la page.
        // Exemples :
        //   https://cnap.fr/appels/a-projets → https://cnap.fr
        //   https://www.cnm.fr/actualites    → https://www.cnm.fr
        $parsedUrl = parse_url($sourceUrl);
        if ($parsedUrl === false || !isset($parsedUrl['host'])) {
            // URL malformée → on ne peut pas extraire le domaine
            return [];
        }

        $scheme   = $parsedUrl['scheme'] ?? 'https';
        $host     = $parsedUrl['host'];
        $port     = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $baseRoot = $scheme . '://' . $host . $port;

        $candidates = [];

        foreach (self::COMMON_FEED_PATHS as $path) {
            $candidateUrl = $baseRoot . $path;

            // On tente d'abord HEAD pour minimiser la bande passante utilisée.
            // La plupart des serveurs web supportent HEAD sur les ressources GET.
            $isCandidate = $this->probeUrl($candidateUrl);

            if ($isCandidate) {
                $candidates[] = $candidateUrl;
            }
        }

        return $candidates;
    }

    /**
     * Vérifie si une URL ressemble à un flux RSS/Atom via une requête HTTP légère.
     *
     * Deux critères doivent être réunis pour considérer l'URL comme candidate :
     *   1. Code HTTP = 200 (pas de 404, 301, 403...)
     *   2. Content-Type contient "xml", "rss" ou "atom"
     *
     * Le critère 2 évite les faux positifs des "soft 404" (page HTML 404
     * personnalisée retournée avec HTTP 200 — courant sur WordPress, Drupal...).
     *
     * Stratégie HEAD-first :
     *   On tente d'abord HEAD (sans corps → plus rapide, moins de bande passante).
     *   Si le serveur répond 405 (Method Not Allowed), on retombe sur GET.
     *   Pour GET on limite la consommation en lisant uniquement les en-têtes.
     *
     * @param string $url URL à sonder
     *
     * @return bool true si l'URL ressemble à un flux RSS/Atom valide
     */
    private function probeUrl(string $url): bool
    {
        // ── Tentative 1 : requête HEAD ────────────────────────────────────────
        $contentType = $this->headRequest($url);

        if ($contentType === null) {
            // HEAD a échoué (exception réseau, timeout...) → on abandonne
            // On ne tente pas GET pour ne pas sur-solliciter un serveur déjà lent
            return false;
        }

        if ($contentType === 'method_not_allowed') {
            // Le serveur ne supporte pas HEAD → on tente GET en lecture partielle
            $contentType = $this->getRequestContentType($url);
            if ($contentType === null) {
                return false;
            }
        }

        // ── Vérification du Content-Type ──────────────────────────────────────
        // On cherche "xml", "rss" ou "atom" dans le Content-Type (insensible à la casse).
        // Exemples de Content-Types valides :
        //   application/rss+xml, application/atom+xml, application/xml,
        //   text/xml, application/x-rss+xml, application/rdf+xml
        return $this->isXmlContentType($contentType);
    }

    /**
     * Effectue une requête HEAD et retourne le Content-Type reçu.
     *
     * Retourne :
     *   - string : valeur du Content-Type (peut être vide '')
     *   - 'method_not_allowed' : si HTTP 405 (le serveur ne supporte pas HEAD)
     *   - null : si erreur réseau, timeout ou code HTTP invalide (≠ 200, ≠ 405)
     *
     * On retourne 'method_not_allowed' comme sentinelle (plutôt qu'une exception
     * ou un booléen) pour que probeUrl() puisse distinguer "échec réseau" de
     * "HEAD non supporté → essayer GET".
     *
     * @param string $url URL à vérifier
     *
     * @return string|null Content-Type, sentinelle 'method_not_allowed', ou null sur erreur
     */
    private function headRequest(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('HEAD', $url, [
                'headers' => [
                    'User-Agent' => 'BazaartBot (+https://bazaart.fr)',
                ],
                'timeout'       => self::PROBE_TIMEOUT,
                'max_redirects' => 3, // Limité car on ne veut pas suivre les redirections indéfiniment
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 405) {
                // HEAD non supporté par ce serveur → signaler pour fallback GET
                return 'method_not_allowed';
            }

            if ($statusCode !== 200) {
                // 404, 403, 301, 500... → pas un flux valide à cette URL
                return null;
            }

            // Extraction du Content-Type depuis les en-têtes de réponse
            $headers     = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? '';

            return $contentType;

        } catch (TransportExceptionInterface $e) {
            // Timeout, DNS, SSL... → null (pas de fallback GET pour ne pas rallonger)
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Effectue une requête GET légère et retourne le Content-Type.
     *
     * Utilisé comme fallback quand le serveur répond 405 à HEAD.
     * On lit uniquement les en-têtes (buffer_size minimum) pour minimiser
     * la consommation de bande passante et le temps d'attente.
     *
     * @param string $url URL à vérifier
     *
     * @return string|null Content-Type, ou null sur erreur/code HTTP ≠ 200
     */
    private function getRequestContentType(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'BazaartBot (+https://bazaart.fr)',
                    // On accepte les types XML en priorité pour signaler notre intention
                    'Accept'     => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
                ],
                'timeout'       => self::PROBE_TIMEOUT,
                'max_redirects' => 3,
                // buffer_size réduit pour lire le minimum nécessaire
                // (on veut seulement les en-têtes, pas le corps du flux)
                'buffer' => false,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                return null;
            }

            $headers     = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? '';

            // On annule la lecture du corps pour libérer la connexion rapidement
            $response->cancel();

            return $contentType;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Vérifie si un Content-Type correspond à du XML/RSS/Atom.
     *
     * Heuristique délibérément tolérante mais prudente :
     *   - "xml"  couvre : application/xml, text/xml, application/rss+xml, application/atom+xml,
     *             application/rdf+xml, application/x-rss+xml...
     *   - "rss"  couvre les rares cas où le Content-Type est "rss" seul (non standard)
     *   - "atom" couvre les rares cas où le Content-Type est "atom" seul (non standard)
     *
     * Cette heuristique accepte les Content-Types non standards mais rejette
     * explicitement le HTML (text/html) — important pour éviter les soft 404.
     *
     * @param string $contentType Valeur du header Content-Type (peut contenir charset=...)
     *
     * @return bool true si le Content-Type ressemble à du XML/RSS/Atom
     */
    private function isXmlContentType(string $contentType): bool
    {
        $ct = strtolower($contentType);

        // Rejet explicite du HTML même si le serveur renvoie un 200
        // (soft 404 courant sur les CMS : "page non trouvée" rendue en HTML avec HTTP 200)
        if (str_contains($ct, 'text/html') || str_contains($ct, 'application/xhtml')) {
            return false;
        }

        // Acceptation des types XML/RSS/Atom
        return str_contains($ct, 'xml') || str_contains($ct, 'rss') || str_contains($ct, 'atom');
    }

    /**
     * Résout une URL potentiellement relative en URL absolue.
     *
     * Exemples :
     *   ("/feed", "https://cnap.fr/appels")          → "https://cnap.fr/feed"
     *   ("rss.xml", "https://cnap.fr/appels/")       → "https://cnap.fr/appels/rss.xml"
     *   ("https://cnap.fr/feed", "https://cnap.fr/") → "https://cnap.fr/feed" (inchangée)
     *   ("//cnap.fr/feed", "https://cnap.fr/")       → "https://cnap.fr/feed" (protocole ajouté)
     *
     * Algorithme conforme à RFC 3986 §5.2 (résolution d'URI de référence).
     * Utilisé pour les hrefs des balises <link> qui peuvent être relatifs.
     *
     * @param string $href    URL potentiellement relative (attribut href de la balise <link>)
     * @param string $baseUrl URL de base de la page (URL complète de la page scrapée)
     *
     * @return string|null URL absolue résolue, ou null si href invalide
     */
    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        if ($href === '') {
            return null;
        }

        // Cas 1 : URL déjà absolue (commence par http:// ou https://)
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        // Cas 2 : URL relative au protocole ("//example.com/feed")
        // On emprunte le schéma (http/https) de la baseUrl
        if (str_starts_with($href, '//')) {
            $parsedBase = parse_url($baseUrl);
            $scheme     = $parsedBase['scheme'] ?? 'https';
            return $scheme . ':' . $href;
        }

        // Cas 3 : URL relative à la racine du domaine ("/feed")
        if (str_starts_with($href, '/')) {
            $parsedBase = parse_url($baseUrl);
            if ($parsedBase === false || !isset($parsedBase['host'])) {
                return null;
            }
            $scheme = $parsedBase['scheme'] ?? 'https';
            $host   = $parsedBase['host'];
            $port   = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';
            return $scheme . '://' . $host . $port . $href;
        }

        // Cas 4 : URL relative au chemin courant ("rss.xml" sans /leading slash)
        // On construit l'URL en remplaçant la dernière partie du path de baseUrl
        $parsedBase = parse_url($baseUrl);
        if ($parsedBase === false || !isset($parsedBase['host'])) {
            return null;
        }
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host   = $parsedBase['host'];
        $port   = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';

        // On extrait le répertoire du path de la baseUrl
        // Ex: "/appels/a-projets" → "/appels/"
        $basePath = isset($parsedBase['path']) ? dirname($parsedBase['path']) . '/' : '/';

        return $scheme . '://' . $host . $port . $basePath . $href;
    }

    /**
     * Construit une ligne du tableau SymfonyStyle pour une source analysée.
     *
     * Formate les données en chaînes lisibles pour l'affichage en terminal.
     * Les balises <info>, <comment>, <error> sont interprétées par SymfonyStyle
     * comme des codes couleur (vert, jaune, rouge).
     *
     * @param string   $nom        Nom de la source
     * @param string   $url        URL principale de la source
     * @param string   $type       Label lisible du type (ex: "HTML → LLM")
     * @param bool     $hasFeedUrl true si feedUrl est déjà renseigné en BDD
     * @param string[] $candidates Liste d'URLs candidates trouvées (peut être vide)
     * @param string   $error      Message d'erreur (ex: "page inaccessible"), vide si OK
     *
     * @return array<int, string> Une ligne du tableau (5 colonnes)
     */
    private function buildTableRow(
        string $nom,
        string $url,
        string $type,
        bool $hasFeedUrl,
        array $candidates,
        string $error = '',
    ): array {
        // Colonne feedUrl : indication visuelle oui/non avec couleur
        $feedUrlCell = $hasFeedUrl
            ? '<info>Oui</info>'
            : '<comment>Non</comment>';

        // Colonne candidats : liste des URLs ou message "— aucun"
        if ($error !== '') {
            // La page n'a pas pu être téléchargée
            $candidatesCell = sprintf('<error>%s</error>', $error);
        } elseif (empty($candidates)) {
            // Aucun flux trouvé malgré l'analyse complète
            $candidatesCell = '— aucun';
        } else {
            // On joint les candidats par un saut de ligne pour la lisibilité
            // (un flux par ligne dans la cellule du tableau)
            $candidatesCell = implode("\n", $candidates);
        }

        // Troncature de l'URL de la source pour ne pas déborder le tableau en terminal
        // Les URLs longues (> 60 chars) sont tronquées avec "..." pour la lisibilité
        $urlDisplay = strlen($url) > 60 ? substr($url, 0, 57) . '...' : $url;

        return [$nom, $urlDisplay, $type, $feedUrlCell, $candidatesCell];
    }
}

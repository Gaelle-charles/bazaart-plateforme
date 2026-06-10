<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;
use App\Entity\ScrapingSource;
use Laminas\Feed\Reader\Reader as FeedReader;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
// DeadlineParserService : utilisé pour extraire la deadline depuis le texte libre
// (titre + description), en remplacement de l'ancien mapping pubDate → deadline.
use App\Service\DeadlineParserService;


/**
 * FeedReaderService — Lecture native des flux RSS 2.0 et Atom via laminas-feed.
 *
 * Ce service REMPLACE la méthode GenericScraper::scrapeRss() (SimpleXML)
 * pour les sources de type ScrapingSourceType::RSS.
 * Il est prévu d'être appelé par l'orchestrateur du pipeline (WS3).
 *
 * ── CE QUE CE SERVICE FAIT ──────────────────────────────────────────────────
 *   1. Télécharge le contenu du flux via Symfony HttpClient (timeout 15s)
 *   2. Parse le XML via laminas/laminas-feed (RSS 2.0 et Atom unifiés)
 *   3. Filtre les items par mots-clés pour ne garder que les opportunités pertinentes
 *   4. Détecte le type d'opportunité via heuristique (même logique que GenericScraper)
 *   5. Nettoie la description via HtmlSanitizerService (strip total + 2000 chars)
 *   6. Retourne une liste de ScrapedOpportunity[]
 *
 * ── CE QUE CE SERVICE NE FAIT PAS ───────────────────────────────────────────
 *   - Pas de persistance en BDD : c'est ScrapedResourcePersister qui persiste
 *   - Pas d'appel LLM : ce service ne touche aucun LLM (séparation de responsabilités)
 *   - Pas de déduplication : c'est ScrapedResourcePersister qui dédup
 *
 * ── POURQUOI laminas-feed plutôt que SimpleXML ? ─────────────────────────────
 * laminas-feed est une bibliothèque mature (ex-Zend Framework) qui :
 *   - Gère les variantes RSS 2.0, RSS 1.0 (RDF), Atom 1.0 de façon unifiée
 *   - Expose une interface commune : getTitle(), getLink(), getDateModified()...
 *   - Gère proprement les namespaces XML (Dublin Core, Media, Atom dans RSS...)
 *   - Évite le code conditionnel RSS/Atom dans GenericScraper::scrapeRss()
 *
 * ── SÉCURITÉ DESCRIPTION ────────────────────────────────────────────────────
 * TOUTE description issue d'un flux externe passe obligatoirement par
 * HtmlSanitizerService::sanitize() avant d'être stockée dans le DTO.
 * Raison : les descriptions RSS peuvent contenir du HTML arbitraire (souvent
 * un résumé HTML complet de l'article). Ce HTML ne doit jamais se retrouver
 * ni en BDD sans nettoyage, ni dans un prompt LLM (risque de prompt injection).
 */
class FeedReaderService
{
    /**
     * Mots-clés pour filtrer les items pertinents dans le flux RSS.
     *
     * ⚠️ DETTE TECHNIQUE ASSUMÉE : cette liste est identique à
     * GenericScraper::KEYWORDS. Elle est copiée ici délibérément
     * plutôt que mutualisée dans une classe partagée, car GenericScraper::KEYWORDS
     * est une constante private et WS2 ne doit pas casser GenericScraper.
     * Une factorisation dans une classe OpportunityKeywords ou similaire
     * est prévue en WS3 si le besoin se confirme.
     *
     * @var string[]
     */
    private const KEYWORDS = [
        'appel', 'candidature', 'bourse', 'résidence', 'résidences',
        'aide', 'soutien', 'prix', 'subvention', 'financement', 'grant',
        'fellowship', 'commission', 'call', 'award', 'mobility',
    ];

    /**
     * Timeout du téléchargement des flux RSS, en secondes.
     *
     * 15s est un compromis entre tolérance aux sites lents (CNAP, CNM...)
     * et refus de bloquer le processus trop longtemps sur un serveur mort.
     * GenericScraper utilisait 20s — on est légèrement plus strict ici
     * car laminas-feed parse uniquement le XML (pas de HTML volumineux).
     */
    private const FETCH_TIMEOUT = 15;

    public function __construct(
        // Client HTTP Symfony — injecté par autowiring (symfony/http-client requis)
        private readonly HttpClientInterface $httpClient,
        // Service de nettoyage HTML — strip total + troncature 2000 chars
        private readonly HtmlSanitizerService $htmlSanitizer,
        // Logger PSR-3 — logs des erreurs sans lever d'exception fatale
        private readonly LoggerInterface $logger,
        // Service de parsing des deadlines — pour extraire une vraie deadline
        // depuis le texte libre (titre + description) de chaque item RSS.
        // JAMAIS la pubDate ne doit aller dans deadline — c'est ce service qui décide.
        private readonly DeadlineParserService $deadlineParser,
    ) {
    }

    /**
     * Lit le flux RSS/Atom d'une source et retourne les opportunités pertinentes.
     *
     * ⚠️ MÉTHODE CONSERVÉE POUR RÉTRO-COMPATIBILITÉ — délègue désormais à readWithResult().
     * Les nouveaux appelants (ex: ReadFeedsCommand WS3) doivent utiliser readWithResult()
     * pour distinguer l'échec de fetch (HTTP/XML) du succès avec 0 item.
     *
     * Si le téléchargement échoue, si le XML est invalide, ou si aucun item
     * ne passe le filtre de mots-clés → retourne [] sans lever d'exception.
     *
     * @param ScrapingSource $source La source à lire (type RSS attendu)
     *
     * @return ScrapedOpportunity[] Liste d'opportunités filtrées et nettoyées
     */
    public function read(ScrapingSource $source): array
    {
        // Délégation à readWithResult() pour éviter la duplication de logique.
        // On ignore le champ success ici — comportement identique à l'ancienne implémentation.
        return $this->readWithResult($source)->items;
    }

    /**
     * Lit le flux RSS/Atom et retourne un FeedReadResult distinguant échec et succès vide.
     *
     * C'est la méthode RECOMMANDÉE pour les nouveaux appelants.
     * Elle retourne un objet qui distingue explicitement :
     *   - success = false : le flux n'a pas pu être chargé/parsé (HTTP non-200, XML invalide)
     *   - success = true + items = [] : flux valide mais aucun item ne matche les mots-clés
     *
     * Cette distinction permet à ReadFeedsCommand de mettre à jour correctement
     * le compteur consecutiveFailures des sources sans faux-positifs sur les flux vides.
     *
     * @param ScrapingSource $source La source à lire (type RSS attendu)
     *
     * @return FeedReadResult Résultat avec indicateur de succès/échec + items
     */
    public function readWithResult(ScrapingSource $source): FeedReadResult
    {
        // ── Choix de l'URL à télécharger ────────────────────────────────────
        // Priorité au champ feedUrl (URL RSS dédiée, ajoutée en WS1).
        // Fallback sur url si feedUrl est null — certaines sources ont le flux
        // directement à l'URL principale (ex: "https://cnm.fr/feed" = url ET feedUrl).
        // Ce fallback est commenté explicitement pour que WS3 puisse le supprimer
        // quand feedUrl sera obligatoire pour les sources RSS.
        $feedUrl = $source->getFeedUrl() ?? $source->getUrl();

        // ── Téléchargement du flux ───────────────────────────────────────────
        // fetchFeedContentWithError() retourne ['xml' => null, 'error' => '...'] en cas
        // d'erreur HTTP ou réseau. Dans ce cas, on retourne FeedReadResult::failure()
        // pour que l'orchestrateur sache que c'est un vrai échec (pas juste un flux vide).
        $fetchResult = $this->fetchFeedContentWithError($feedUrl, $source->getNom());
        if ($fetchResult['xml'] === null) {
            // Échec de fetch : HTTP non-200 ou exception réseau
            return FeedReadResult::failure($fetchResult['error'] ?? 'Erreur de téléchargement inconnue');
        }

        $xml = $fetchResult['xml'];

        // ── Parsing via laminas-feed ─────────────────────────────────────────
        try {
            // Reader::importString() détecte automatiquement le format du flux :
            //   - RSS 2.0 (<rss version="2.0">)
            //   - RSS 1.0 / RDF (<rdf:RDF>)
            //   - Atom 1.0 (<feed xmlns="http://www.w3.org/2005/Atom">)
            // Il expose ensuite une interface unifiée (getTitle(), getLink()...).
            // L'avantage sur SimpleXML : on n'a plus à gérer manuellement les
            // différences structurelles entre RSS et Atom (channel->item vs entry,
            // link tag vs link attribute href...).
            $feed = FeedReader::importString($xml);
        } catch (\Exception $e) {
            // XML invalide ou format non reconnu par laminas-feed → échec réel
            $errorMsg = sprintf('Erreur parsing XML (laminas-feed) : %s', $e->getMessage());
            $this->logger->warning('[FeedReaderService] Erreur parsing laminas-feed', [
                'source' => $source->getNom(),
                'url'    => $feedUrl,
                'error'  => $e->getMessage(),
            ]);
            return FeedReadResult::failure($errorMsg);
        }

        // ── Extraction du nom de la source (champ source du DTO) ─────────────
        // Utilise le domaine de l'URL pour un libellé court et reconnaissable
        // (ex: "cnm.fr" plutôt que "CNM - Centre National de la Musique RSS Feed").
        // Fallback sur le nom BDD si parse_url échoue (URL malformée).
        $sourceName = parse_url($source->getUrl(), PHP_URL_HOST) ?: $source->getNom();

        // ── Parcours des items du flux ────────────────────────────────────────
        // À ce stade, le fetch ET le parsing ont réussi.
        // Si aucun item ne passe les filtres, on retourne FeedReadResult::ok([])
        // (success = true) — la source fonctionne, elle est juste vide.
        $opportunities = [];

        foreach ($feed as $item) {
            try {
                $opportunity = $this->processItem($item, $source, $sourceName);
                if ($opportunity !== null) {
                    $opportunities[] = $opportunity;
                }
            } catch (\Exception $e) {
                // Un item mal formé ne doit pas stopper le parcours du flux entier.
                // On log en debug (pas warning — c'est fréquent sur certains flux)
                // et on continue avec l'item suivant.
                $this->logger->debug('[FeedReaderService] Item ignoré (exception)', [
                    'source' => $source->getNom(),
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        // Succès : que le flux soit vide ou non, le service a fonctionné.
        return FeedReadResult::ok($opportunities);
    }

    /**
     * Télécharge le contenu brut du flux RSS et retourne aussi le message d'erreur.
     *
     * Retourne un tableau associatif :
     *   ['xml' => string, 'error' => null]       en cas de succès
     *   ['xml' => null,   'error' => string]      en cas d'erreur
     *
     * Ce tableau permet à readWithResult() de propager le message d'erreur
     * jusqu'au FeedReadResult::failure().
     *
     * ── POURQUOI un tableau et pas un objet ? ──────────────────────────────
     * Cette méthode est privée et n'est appelée que par readWithResult().
     * Un objet dédié serait sur-engineering pour un usage aussi localisé.
     *
     * @param string $url        URL du flux RSS/Atom à télécharger
     * @param string $sourceName Nom de la source (pour les logs uniquement)
     *
     * @return array{xml: string|null, error: string|null}
     */
    private function fetchFeedContentWithError(string $url, string $sourceName): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    // User-Agent identifiable — transparent sur qui fait la requête.
                    // Les bots mal identifiés sont souvent bloqués par les sites culturels.
                    'User-Agent' => 'BazaartBot (+https://bazaart.fr)',
                    // Accept : on demande explicitement les formats feed XML.
                    // Certains serveurs retournent du HTML si Accept est générique.
                    'Accept'     => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
                ],
                'timeout' => self::FETCH_TIMEOUT,
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorMsg = sprintf('HTTP %d (attendu : 200)', $response->getStatusCode());
                $this->logger->warning('[FeedReaderService] HTTP non-200', [
                    'source' => $sourceName,
                    'url'    => $url,
                    'status' => $response->getStatusCode(),
                ]);
                // Retour null cohérent avec GenericScraper — pas d'exception fatale.
                // L'orchestrateur (WS3) gérera le compteur consecutiveFailures.
                return ['xml' => null, 'error' => $errorMsg];
            }

            return ['xml' => $response->getContent(), 'error' => null];

        } catch (\Exception $e) {
            $errorMsg = sprintf('Erreur HTTP : %s', $e->getMessage());
            $this->logger->warning('[FeedReaderService] Erreur HTTP', [
                'source' => $sourceName,
                'url'    => $url,
                'error'  => $e->getMessage(),
            ]);
            return ['xml' => null, 'error' => $errorMsg];
        }
    }

    /**
     * Traite un item de flux RSS/Atom et retourne une ScrapedOpportunity ou null.
     *
     * Retourne null si :
     *   - L'item n'a pas de titre ou de lien (données incomplètes)
     *   - L'item ne contient aucun mot-clé pertinent (filtrage thématique)
     *
     * @param \Laminas\Feed\Reader\Entry\EntryInterface $item     L'entrée du flux
     * @param ScrapingSource                           $source   La source (pour disciplines, etc.)
     * @param string                                   $sourceName Nom court de la source (domaine)
     *
     * @return ScrapedOpportunity|null L'opportunité si pertinente, null sinon
     */
    private function processItem(
        \Laminas\Feed\Reader\Entry\EntryInterface $item,
        ScrapingSource $source,
        string $sourceName,
    ): ?ScrapedOpportunity {
        // ── Extraction du titre ──────────────────────────────────────────────
        // getTitle() fonctionne pour RSS 2.0 et Atom.
        // EntryInterface::getTitle() retourne mixed (interface PHP 7 sans types déclarés).
        // Le cast (string) gère null → '' et tout scalaire → string, donc ?? '' est superflu.
        $title = trim((string) $item->getTitle());
        if ($title === '') {
            return null;
        }

        // ── Extraction du lien ───────────────────────────────────────────────
        // getLink() fonctionne pour les deux formats (laminas-feed abstrait
        // la différence RSS <link> vs Atom <link href="...">).
        // EntryInterface::getLink() retourne mixed — même logique que getTitle() ci-dessus.
        $link = trim((string) $item->getLink());
        if ($link === '') {
            return null;
        }

        // ── Extraction de la description / résumé ────────────────────────────
        // getDescription() retourne le champ <description> (RSS) ou <summary> (Atom).
        // getContent() retourne <content:encoded> (RSS) ou <content> (Atom) — plus riche.
        // On préfère getContent() si disponible, fallback sur getDescription().
        // Les deux méthodes retournent mixed — on caste en string (null → '').
        // L'opérateur ?: (null coalesce sur mixed) permet le fallback content → description.
        $rawDescription = (string) ($item->getContent() ?: $item->getDescription());

        // ── NETTOYAGE OBLIGATOIRE ─────────────────────────────────────────────
        // La description RSS peut contenir du HTML arbitraire (parfois un article entier).
        // HtmlSanitizerService garantit :
        //   - strip_tags() total (aucun tag conservé → pas d'injection XSS, pas de HTML dans LLM)
        //   - html_entity_decode() (pas d'entités résiduelles comme &amp; en BDD)
        //   - troncature à 2000 caractères (limite coût LLM et taille BDD)
        $description = $this->htmlSanitizer->sanitize($rawDescription);

        // ── Filtrage par mots-clés ───────────────────────────────────────────
        // On construit un texte combiné titre + description pour le test.
        // mb_strtolower : sensible à la casse → "Appel" et "appel" sont équivalents.
        $textLower = mb_strtolower($title . ' ' . $description);
        $relevant  = false;
        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($textLower, $keyword)) {
                $relevant = true;
                break;
            }
        }

        if (!$relevant) {
            // Item non pertinent → ignoré silencieusement (pas de log, trop verbeux)
            return null;
        }

        // ── Détection heuristique du type d'opportunité ──────────────────────
        // Même heuristique que GenericScraper::scrapeRss() — cohérence garantie.
        // Réutilise $textLower déjà calculé — pas de second mb_strtolower().
        $type = $this->detectType($textLower);

        // ── Extraction de la date de publication (pubDate) ───────────────────
        // On récupère la date de modification (plus fraîche) ou de création du flux.
        // laminas-feed retourne un objet \DateTime|null.
        //
        // RÈGLE MÉTIER FONDAMENTALE :
        //   La pubDate va dans le champ `publishedAt` du DTO — JAMAIS dans `deadline`.
        //   `deadline_date` en BDD ne doit contenir QUE de vraies dates limites de
        //   candidature, extraites du texte par DeadlineParserService::extractFromText().
        //   Mettre la pubDate dans deadline fausserait l'archivage automatique
        //   (les opportunités seraient archivées le lendemain de leur publication,
        //   avant même que les artistes puissent postuler).
        $rawPubDate  = $item->getDateModified() ?? $item->getDateCreated();
        // Conversion \DateTime → \DateTimeImmutable (le DTO et l'entité utilisent Immutable)
        $publishedAt = null;
        if ($rawPubDate !== null) {
            try {
                // \DateTimeImmutable::createFromMutable() est la conversion "officielle"
                // PHP — préférable à new \DateTimeImmutable($rawPubDate->format('c'))
                // qui repasse par le parsing de chaîne et peut décaler le fuseau horaire.
                $publishedAt = \DateTimeImmutable::createFromMutable($rawPubDate);
            } catch (\Exception) {
                // Cas improbable (objet DateTime invalide), on laisse null
                $publishedAt = null;
            }
        }

        // ── Extraction de la deadline depuis le texte libre ──────────────────
        // DeadlineParserService::extractFromText() scanne le titre + description
        // à la recherche d'une date précédée d'un indice de deadline (clôture,
        // date limite, avant le, jusqu'au…). Si rien n'est trouvé → null.
        //
        // Le résultat est un \DateTimeImmutable|null. On le formate en d/m/Y pour
        // le champ `deadline` (string) du DTO — le ScrapedResourceListener le
        // reparsera ensuite en deadlineDate lors de la persistance.
        // Si null → on passe '' → le listener produira deadlineDate = null.
        $deadlineDate = $this->deadlineParser->extractFromText($title . ' ' . $description);
        $deadlineStr  = $deadlineDate !== null ? $deadlineDate->format('d/m/Y') : '';

        return new ScrapedOpportunity(
            title:       $title,
            type:        $type,
            url:         $link,
            source:      $sourceName,
            description: $description,
            deadline:    $deadlineStr,  // '' si pas de deadline détectée → deadlineDate = null
            // disciplines : valeur de la ScrapingSource (renseignée par l'admin au seed)
            // Fallback 'Toutes disciplines' si non renseigné — cohérent avec GenericScraper
            disciplines:  $source->getDisciplinePrincipale() ?? 'Toutes disciplines',
            publishedAt:  $publishedAt,  // pubDate du flux — JAMAIS dans deadline
        );
    }

    /**
     * Détecte le type d'opportunité à partir du texte combiné titre + description.
     *
     * Heuristique identique à GenericScraper::scrapeRss() pour garantir la cohérence
     * des données entre les deux pipelines (RSS SimpleXML et RSS laminas-feed).
     *
     * @param string $textLower Texte en minuscules (titre + description) déjà calculé
     *
     * @return string Type détecté : "Résidence", "Bourse", "Prix", "Financement" ou "Appel à projets"
     */
    private function detectType(string $textLower): string
    {
        // Ordre important : du plus spécifique au plus général
        if (str_contains($textLower, 'résidence')) {
            return 'Résidence';
        }
        if (str_contains($textLower, 'bourse')) {
            return 'Bourse';
        }
        if (str_contains($textLower, 'prix')) {
            return 'Prix';
        }
        if (str_contains($textLower, 'financement') || str_contains($textLower, 'soutien')) {
            return 'Financement';
        }

        // Valeur par défaut : "Appel à projets" couvre les cas non détectés
        return 'Appel à projets';
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SuggestedSource;
use App\Enum\SuggestedSourceStatus;
use App\Repository\ScrapingSourceRepository;
use App\Repository\SuggestedSourceRepository;
use App\Service\LinkExtractorService;
use App\Service\LlmExtractorService;
use App\Service\SettingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DiscoverSourcesCommand — Découverte automatique de nouvelles sources culturelles.
 *
 * Cette commande analyse les pages HTML des sources de scraping marquées comme
 * agrégateurs (estAgregateur = true) pour détecter de nouveaux organismes culturels
 * qui pourraient avoir leurs propres opportunités (résidences, bourses, etc.).
 *
 * PRINCIPE D'ISOLATION ABSOLU :
 *   - Cette commande est SÉPARÉE de app:scrape-opportunities
 *   - Elle ne modifie JAMAIS ScrapedResource
 *   - Elle ne lance JAMAIS le scraping
 *   - Elle popule UNIQUEMENT la table suggested_sources
 *
 * Flux de traitement :
 *   1. Vérifier le setting discovery_enabled (si false → sortie propre)
 *   2. Lire discovery_max_suggestions (plafond de créations par run)
 *   3. Charger les agrégateurs actifs (ScrapingSource::estAgregateur = true)
 *   4. Pour chaque agrégateur :
 *      a. Télécharger le HTML via HttpClientInterface
 *      b. Extraire les liens via LinkExtractorService::extractAndFilter() (filtrage PHP)
 *      c. Transmettre les candidats filtrés au LlmExtractorService::discoverSources()
 *      d. Pour chaque organisme retourné :
 *         - URL vide → skip
 *         - URL déjà dans scraping_sources → skip (doublon)
 *         - URL déjà dans suggested_sources → skip (déjà suggéré)
 *         - Sinon → créer SuggestedSource (statut AValider)
 *      d. Si plafond atteint → log + arrêt
 *   5. Flush en fin de traitement (une seule transaction)
 *   6. Rapport de synthèse
 *
 * Options :
 *   --dry-run       : Simule le traitement sans créer de SuggestedSource en BDD
 *   --source=<slug> : Limite la découverte à l'agrégateur avec ce scraperSlug
 *
 * Lancement :
 *   docker compose exec app php bin/console app:discover-sources
 *   docker compose exec app php bin/console app:discover-sources --dry-run
 *   docker compose exec app php bin/console app:discover-sources --source=on-the-move
 */
#[AsCommand(
    name: 'app:discover-sources',
    description: 'Analyse les pages des agrégateurs pour découvrir de nouvelles sources culturelles à scraper.',
)]
class DiscoverSourcesCommand extends Command
{
    /**
     * User-Agent navigateur standard envoyé lors des requêtes HTTP.
     *
     * On se présente comme un navigateur Chrome pour éviter les blocages
     * des sites qui rejettent les User-Agents de bots/crawlers.
     * Même politique que les scrapers (AbstractScraper) pour cohérence.
     */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /**
     * Timeout en secondes pour le téléchargement d'une page agrégateur.
     *
     * Les pages agrégateurs peuvent être lentes (beaucoup d'images, JS, etc.).
     * 30 secondes est un bon compromis : assez long pour les sites lents,
     * assez court pour ne pas bloquer la commande en cas de site inaccessible.
     */
    private const HTTP_TIMEOUT = 30;

    public function __construct(
        // Client HTTP Symfony (symfony/http-client) — pour télécharger les pages agrégateurs
        private readonly HttpClientInterface $httpClient,
        // Repository des sources existantes — pour vérifier les doublons d'URL
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // Repository des suggestions — pour vérifier les doublons + persister les nouvelles
        private readonly SuggestedSourceRepository $suggestedSourceRepository,
        // Service LLM — pour analyser les candidats filtrés et identifier les organismes
        private readonly LlmExtractorService $llmExtractorService,
        // Service de paramètres — pour lire discovery_enabled et discovery_max_suggestions
        private readonly SettingService $settingService,
        // EntityManager — pour persister les SuggestedSource en BDD
        private readonly EntityManagerInterface $em,
        // Logger PSR-3 — pour tracer les erreurs sans interrompre la commande
        private readonly LoggerInterface $logger,
        // Service d'extraction de liens — pré-filtre le HTML avant d'appeler le LLM
        private readonly LinkExtractorService $linkExtractor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simule le traitement sans créer de SuggestedSource en BDD (mode test)'
            )
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Limite la découverte à l\'agrégateur avec ce scraperSlug (ex: on-the-move)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var string|null $sourceSlug */
        $sourceSlug = $input->getOption('source');

        $io->title('BazaArt — Découverte automatique de nouvelles sources');

        if ($dryRun) {
            $io->note('Mode --dry-run activé : aucune SuggestedSource ne sera créée en BDD.');
        }

        // ── Étape 1 : Vérifier si la découverte est activée ──────────────────
        // Le setting 'discovery_enabled' permet de suspendre la commande sans la supprimer.
        // On accepte 'true' ET '1' pour plus de souplesse (certains settings utilisent '0'/'1').
        $discoveryEnabled = $this->settingService->get('discovery_enabled', 'true');
        if ($discoveryEnabled !== 'true' && $discoveryEnabled !== '1') {
            $io->warning(
                'La découverte de sources est désactivée (setting discovery_enabled = "'
                . $discoveryEnabled . '"). '
                . 'Modifiez ce paramètre sur /admin/settings pour l\'activer.'
            );
            return Command::SUCCESS;
        }

        // ── Étape 2 : Lire le plafond de suggestions ─────────────────────────
        // Si le setting n'est pas configuré, on utilise 30 comme valeur par défaut sécurisée.
        $maxSuggestions = (int) ($this->settingService->get('discovery_max_suggestions', '30') ?? '30');
        if ($maxSuggestions <= 0) {
            $maxSuggestions = 30;
        }

        $io->text(sprintf('Plafond : %d nouvelle(s) suggestion(s) par run.', $maxSuggestions));

        // ── Étape 3 : Charger les agrégateurs actifs ─────────────────────────
        $aggregators = $this->scrapingSourceRepository->findActiveAggregators();

        // Si un slug est spécifié, on filtre pour ne traiter que cet agrégateur
        if ($sourceSlug !== null) {
            $aggregators = array_filter(
                $aggregators,
                fn ($agg) => $agg->getScraperSlug() === $sourceSlug
            );
            $aggregators = array_values($aggregators); // Réindexe après filter

            if (empty($aggregators)) {
                $io->warning(sprintf(
                    'Aucun agrégateur actif trouvé avec le slug "%s". '
                    . 'Vérifiez que la source existe et est marquée estAgregateur = true.',
                    $sourceSlug
                ));
                return Command::SUCCESS;
            }
        }

        if (empty($aggregators)) {
            $io->warning(
                'Aucun agrégateur actif trouvé en BDD. '
                . 'Lancez "app:seed-scraping-sources --force" pour marquer les agrégateurs, '
                . 'ou ajoutez manuellement des sources avec estAgregateur = true.'
            );
            return Command::SUCCESS;
        }

        $io->text(sprintf(
            '%d agrégateur(s) à analyser : %s',
            count($aggregators),
            implode(', ', array_map(fn ($a) => $a->getNom(), $aggregators))
        ));
        $io->newLine();

        // ── Construction de la liste des domaines connus ──────────────────────
        // Chargée UNE SEULE FOIS avant la boucle pour éviter N requêtes BDD (une par agrégateur).
        // Cette liste sert à LinkExtractorService pour exclure en PHP les liens vers des
        // domaines déjà dans scraping_sources ou suggested_sources.
        $knownDomains = $this->buildKnownDomains();
        $io->text(sprintf('  Domaines déjà connus : %d', count($knownDomains)));
        $io->newLine();

        // ── Compteurs pour le rapport final ───────────────────────────────────
        $totalCandidates   = 0; // Candidats retournés par le LLM (toutes sources confondues)
        $newSuggestions    = 0; // Suggestions effectivement créées en BDD
        $skippedNoUrl      = 0; // Ignorés : URL manquante ou invalide
        $skippedDuplicate  = 0; // Ignorés : déjà dans scraping_sources ou suggested_sources
        $errorsCount       = 0; // Agrégateurs en erreur (timeout, HTML vide, etc.)
        $ceilingReached    = false; // Indique si on a atteint le plafond

        // ── Étape 4 : Traitement de chaque agrégateur ─────────────────────────
        foreach ($aggregators as $aggregator) {
            $aggregatorUrl = $aggregator->getUrl();
            $aggregatorNom = $aggregator->getNom();

            $io->section(sprintf('Analyse : %s', $aggregatorNom));
            $io->text(sprintf('  URL : %s', $aggregatorUrl));

            // ── 4a : Téléchargement du HTML ───────────────────────────────────
            $html = $this->downloadHtml($aggregatorUrl, $io);

            if ($html === null) {
                // L'erreur a déjà été loguée dans downloadHtml()
                $errorsCount++;
                continue;
            }

            $io->text(sprintf('  HTML téléchargé : %d caractères', mb_strlen($html)));

            // ── 4b : Pré-filtrage PHP des liens ───────────────────────────────
            // On extrait et filtre les liens AVANT d'appeler le LLM pour réduire le coût.
            // PHP extrait les URLs proprement depuis le HTML via DomCrawler —
            // le LLM reçoit une liste compacte plutôt que 30 000 chars de HTML brut.
            $linkCandidates = $this->linkExtractor->extractAndFilter($html, $aggregatorUrl, $knownDomains);

            $io->text(sprintf(
                '  Liens pré-filtrés : %d candidat(s) transmis au LLM.',
                count($linkCandidates)
            ));

            if (empty($linkCandidates)) {
                $io->text('  Aucun candidat après filtrage PHP — page ignorée.');
                continue;
            }

            // ── 4c : Appel au LLM avec la liste compacte de candidats ─────────
            // discoverSources() reçoit maintenant array{text, url}[] au lieu du HTML brut.
            // Le LLM identifie parmi ces candidats les vrais organismes culturels.
            $candidates = $this->llmExtractorService->discoverSources($linkCandidates, $aggregatorUrl);

            if (empty($candidates)) {
                $io->text('  LLM : aucun organisme détecté parmi les candidats.');
                continue;
            }

            $io->text(sprintf('  LLM : %d organisme(s) candidat(s) détecté(s).', count($candidates)));
            $totalCandidates += count($candidates);

            // ── 4d : Traitement de chaque organisme retourné par le LLM ──────────
            foreach ($candidates as $candidate) {
                // Vérification 1 : URL présente ?
                $url = $candidate['url'] ?? null;
                if (empty($url)) {
                    if ($dryRun) {
                        $io->text(sprintf(
                            '  [DRY-RUN] SKIP (pas d\'URL) : %s',
                            $candidate['nom'] ?? '?'
                        ));
                    }
                    $skippedNoUrl++;
                    continue;
                }

                // Vérification 2 : déjà dans scraping_sources ?
                if ($this->scrapingSourceRepository->findByUrl($url) !== null) {
                    $io->text(sprintf(
                        '  DOUBLON scraping_sources : %s (%s)',
                        $candidate['nom'],
                        $url
                    ));
                    $skippedDuplicate++;
                    continue;
                }

                // Vérification 3 : déjà dans suggested_sources ?
                if ($this->suggestedSourceRepository->existsByUrl($url)) {
                    $io->text(sprintf(
                        '  DOUBLON suggested_sources : %s (%s)',
                        $candidate['nom'],
                        $url
                    ));
                    $skippedDuplicate++;
                    continue;
                }

                // Vérification 4 : plafond atteint ?
                if ($newSuggestions >= $maxSuggestions) {
                    $ceilingReached = true;
                    $io->warning(sprintf(
                        'Plafond de %d suggestion(s) atteint. Arrêt de la découverte.',
                        $maxSuggestions
                    ));
                    $this->logger->info(
                        '[DiscoverSources] Plafond de suggestions atteint.',
                        ['max' => $maxSuggestions, 'atteint_lors_de' => $aggregatorNom]
                    );
                    break 2; // Sort des deux boucles imbriquées (agrégateurs + candidats)
                }

                // ── Création de la SuggestedSource ────────────────────────────
                $suggestion = new SuggestedSource();
                $suggestion->setNomOrganisme($candidate['nom']);
                $suggestion->setUrl($url);
                $suggestion->setPaysZone($candidate['pays_zone'] ?? null);
                $suggestion->setDisciplinePressentie($candidate['discipline'] ?? null);
                $suggestion->setRaisonSuggestion($candidate['raison'] ?? null);
                $suggestion->setSourceOrigine($aggregatorUrl);
                $suggestion->setDateDecouverte(new \DateTime());
                // statut = AValider est déjà le défaut dans le constructeur de SuggestedSource

                if (!$dryRun) {
                    // En mode normal, on persist (le flush sera fait après la boucle)
                    $this->em->persist($suggestion);
                }

                $io->text(sprintf(
                    '  %s %s — %s%s',
                    $dryRun ? '[DRY-RUN] CRÉERAIT :' : 'CRÉÉ :',
                    $candidate['nom'],
                    $url,
                    $candidate['pays_zone'] ? ' (' . $candidate['pays_zone'] . ')' : ''
                ));

                $newSuggestions++;
            }

            // Si plafond atteint, la boucle break 2 nous a sortis — on check ici
            if ($ceilingReached) {
                break;
            }
        }

        // ── Étape 5 : Flush (si pas en dry-run) ──────────────────────────────
        // Un seul flush en fin de traitement — une seule transaction pour toutes les suggestions.
        if (!$dryRun && $newSuggestions > 0) {
            $this->em->flush();
        }

        // ── Étape 6 : Rapport de synthèse ─────────────────────────────────────
        $io->newLine();
        $io->title('Rapport de découverte');

        $io->definitionList(
            ['Agrégateurs analysés'    => count($aggregators)],
            ['Candidats LLM trouvés'   => $totalCandidates],
            ['Nouvelles suggestions'   => $dryRun ? $newSuggestions . ' (simulation)' : $newSuggestions],
            ['Doublons ignorés'        => $skippedDuplicate],
            ['Sans URL (ignorés)'      => $skippedNoUrl],
            ['Erreurs HTTP/réseau'     => $errorsCount],
            ['Plafond atteint'         => $ceilingReached ? 'Oui (' . $maxSuggestions . ')' : 'Non'],
        );

        if ($newSuggestions > 0 && !$dryRun) {
            $io->success(sprintf(
                '%d nouvelle(s) suggestion(s) créée(s). Rendez-vous sur /admin/suggested-sources pour valider.',
                $newSuggestions
            ));
        } elseif ($newSuggestions > 0 && $dryRun) {
            $io->note(sprintf(
                '[DRY-RUN] %d suggestion(s) auraient été créées. Relancez sans --dry-run pour persister.',
                $newSuggestions
            ));
        } else {
            $io->note(
                'Aucune nouvelle suggestion créée '
                . '(toutes les sources détectées étaient déjà connues, ou le LLM n\'a rien trouvé).'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Construit la liste des domaines déjà connus en BDD (scraping_sources + suggested_sources).
     *
     * POURQUOI CHARGER TOUTES LES SOURCES (pas seulement les actives) ?
     *   On veut éviter de re-suggérer des sources qui ont été :
     *     - Validées et intégrées à scraping_sources (actives ou désactivées)
     *     - Suggérées mais rejetées (statut Rejetée dans suggested_sources)
     *     - Suggérées et en attente de validation (statut AValider)
     *   Charger uniquement les actives provoquerait des doublons sur les inactives/rejetées.
     *
     * FORMAT DE RETOUR : tableau de hosts normalisés (minuscules, sans www.)
     *   Exemples : "example.com", "fondation-xyz.fr", "arts.gov"
     *   Cette normalisation est cohérente avec filterKnownDomains() dans LinkExtractorService.
     *
     * PERFORMANCE : Cette méthode est appelée UNE SEULE FOIS avant la boucle des agrégateurs.
     *   Avec ~200 sources en BDD, deux findAll() sont largement suffisants.
     *   Pas besoin de requête optimisée — ce n'est pas un hot path.
     *
     * @return string[] Tableau de domaines normalisés (peut contenir des doublons → array_unique)
     */
    private function buildKnownDomains(): array
    {
        $domains = [];

        // ── Sources de scraping existantes ────────────────────────────────────────
        // On charge TOUTES les sources (pas seulement les actives) pour éviter les doublons
        // avec les sources désactivées ou mises en pause.
        foreach ($this->scrapingSourceRepository->findAll() as $source) {
            $url = $source->getUrl();
            // getUrl() retourne string (jamais null) pour ScrapingSource
            if (!empty($url)) {
                // normalizeUrl() force https:// et supprime www. — on extrait ensuite le host
                $normalized = $this->linkExtractor->normalizeUrl($url);
                $host = parse_url($normalized, PHP_URL_HOST);
                if (is_string($host) && !empty($host)) {
                    $domains[] = strtolower($host);
                }
            }
        }

        // ── Sources suggérées (tous statuts) ─────────────────────────────────────
        // Inclut : AValider, Validée, Rejetée — on ne veut pas re-suggérer des sources
        // qui ont déjà été évaluées (même négativement) par l'admin.
        foreach ($this->suggestedSourceRepository->findAll() as $suggested) {
            $url = $suggested->getUrl(); // getUrl() retourne ?string pour SuggestedSource
            if (!empty($url)) {
                $normalized = $this->linkExtractor->normalizeUrl($url);
                $host = parse_url($normalized, PHP_URL_HOST);
                if (is_string($host) && !empty($host)) {
                    $domains[] = strtolower($host);
                }
            }
        }

        // array_unique : supprime les doublons (ex: même domaine dans les deux tables)
        return array_unique($domains);
    }

    /**
     * Télécharge le HTML d'une URL agrégateur via HttpClientInterface.
     *
     * Retourne le HTML en string, ou null en cas d'erreur (timeout, HTTP non-200, etc.).
     * L'erreur est loguée et affichée dans la console — la commande continue sur l'agrégateur suivant.
     *
     * Configuration :
     *   - User-Agent : navigateur Chrome (voir USER_AGENT)
     *   - Timeout    : 30 secondes (voir HTTP_TIMEOUT)
     *   - Redirections : suivies automatiquement par Symfony HttpClient
     *
     * @param string $url URL à télécharger
     * @param SymfonyStyle $io Interface console pour afficher les erreurs
     * @return string|null HTML téléchargé, ou null si erreur
     */
    private function downloadHtml(string $url, SymfonyStyle $io): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    // On se présente comme un navigateur pour éviter les blocages
                    'User-Agent'      => self::USER_AGENT,
                    // On accepte le HTML compressé pour économiser la bande passante
                    'Accept-Encoding' => 'gzip, deflate, br',
                    // On accepte le français en priorité (sites majoritairement francophones)
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                ],
                // Timeout : 30 secondes (les pages agrégateurs peuvent être lentes)
                'timeout' => self::HTTP_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $message = sprintf(
                    'HTTP %d pour %s — agrégateur ignoré pour ce run.',
                    $statusCode,
                    $url
                );
                $io->warning($message);
                $this->logger->warning('[DiscoverSources] ' . $message);
                return null;
            }

            // getContent() retourne le HTML décompressé (gzip/br géré automatiquement)
            $html = $response->getContent();

            if (empty(trim($html))) {
                $message = sprintf('HTML vide pour %s — agrégateur ignoré.', $url);
                $io->warning($message);
                $this->logger->warning('[DiscoverSources] ' . $message);
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            // Timeout, SSL, DNS — la commande doit continuer sur les autres agrégateurs
            $message = sprintf(
                'Erreur réseau pour %s : %s',
                $url,
                $e->getMessage()
            );
            $io->warning($message);
            $this->logger->error('[DiscoverSources] ' . $message);
            return null;
        }
    }
}

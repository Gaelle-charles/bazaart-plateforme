<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SuggestedSource;
use App\Repository\ScrapedResourceRepository;
use App\Repository\ScrapingSourceRepository;
use App\Repository\SuggestedSourceRepository;
use App\Service\LinkExtractorService;
use App\Service\LlmExtractorService;
use App\Service\SettingService;
use App\Service\SuggestedSourceAutoValidationService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
 * Cette commande analyse DEUX GISEMENTS distincts pour détecter de nouveaux
 * organismes culturels qui pourraient avoir leurs propres opportunités
 * (résidences, bourses, appels à projets, etc.) :
 *
 *   1. GISEMENT "AGRÉGATEURS" (historique) : les pages HTML/RSS des sources de
 *      scraping marquées comme agrégateurs (estAgregateur = true).
 *   2. GISEMENT "OPPORTUNITÉS DÉJÀ COLLECTÉES" (ADR-0036) : les ScrapedResource
 *      des 12 derniers mois, dont le champ applicationUrl (ou à défaut url)
 *      pointe souvent vers le site de l'organisme émetteur — un signal jamais
 *      exploité jusqu'ici par la découverte automatique.
 *
 * L'option --pool=aggregators|opportunities|all (défaut : all) permet de cibler
 * un seul gisement, par exemple pour du débogage ciblé.
 *
 * PRINCIPE D'ISOLATION ABSOLU :
 *   - Cette commande est SÉPARÉE de app:scrape-opportunities
 *   - Elle ne modifie JAMAIS ScrapedResource (le gisement "opportunités" est
 *     lu en SELECT seul — jamais écrit)
 *   - Elle ne lance JAMAIS le scraping
 *   - Elle popule UNIQUEMENT la table suggested_sources
 *
 * Flux de traitement (commun aux deux gisements) :
 *   1. Vérifier le setting discovery_enabled (si false → sortie propre)
 *   2. Lire discovery_max_suggestions (plafond de créations par run, PARTAGÉ
 *      entre les deux gisements — le gisement agrégateurs est traité en premier)
 *   3. Gisement "agrégateurs" (si --pool le sélectionne) :
 *      a. Télécharger le contenu (HTML ou flux RSS/Atom) via HttpClientInterface
 *      b. Extraire les liens via LinkExtractorService::extractAndFilter()
 *         (détecte automatiquement le RSS/Atom depuis ADR-0036)
 *      c. Transmettre les candidats filtrés au LLM
 *   4. Gisement "opportunités déjà collectées" (si --pool le sélectionne, ADR-0036) :
 *      a. Charger title/url/applicationUrl des ScrapedResource des 12 derniers mois
 *      b. Construire des candidats {text: titre, url: applicationUrl ?? url}
 *      c. Filtrer via LinkExtractorService::filterCandidates() (même pipeline)
 *      d. Transmettre par lots de 50 max au LLM
 *   5. Pour chaque organisme retourné par le LLM (les deux gisements partagent
 *      cette logique, cf. processCandidate()) :
 *      - URL vide → skip
 *      - URL déjà dans scraping_sources → skip (doublon)
 *      - URL déjà dans suggested_sources → skip (déjà suggéré)
 *      - Sinon → créer SuggestedSource (statut AValider, origine = AGREGATEUR ou
 *        OPPORTUNITE selon le gisement) puis tenter l'AUTO-VALIDATION RSS (ADR-0034)
 *   6. Flush en fin de traitement (une seule transaction, suggestions ET
 *      éventuelles ScrapingSource auto-validées)
 *   7. Rapport de synthèse console + résumé logger->info (ADR-0036, visibilité E)
 *
 * Options :
 *   --dry-run       : Simule le traitement sans créer de SuggestedSource en BDD
 *   --source=<slug> : Limite le gisement agrégateurs à ce scraperSlug (ignoré
 *                     si --pool=opportunities)
 *   --pool=<val>    : aggregators | opportunities | all (défaut : all)
 *
 * Code de sortie (ADR-0036, point E) :
 *   Command::FAILURE si TOUS les agrégateurs analysés ont échoué (HTTP/réseau)
 *   ET que le gisement opportunités n'a produit aucun candidat (ou n'a pas été
 *   lancé) — pour que le cron détecte un run totalement infructueux.
 *   Command::SUCCESS dans tous les autres cas (y compris "rien de nouveau
 *   trouvé" — ce n'est pas un échec, juste un run sans découverte).
 *
 * Lancement :
 *   docker compose exec app php bin/console app:discover-sources
 *   docker compose exec app php bin/console app:discover-sources --dry-run
 *   docker compose exec app php bin/console app:discover-sources --source=on-the-move
 *   docker compose exec app php bin/console app:discover-sources --pool=opportunities
 */
#[AsCommand(
    name: 'app:discover-sources',
    description: 'Analyse les agrégateurs et les opportunités déjà collectées pour découvrir de nouvelles sources.',
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

    /**
     * Taille maximale d'un lot de candidats envoyé au LLM pour le gisement
     * "opportunités déjà collectées" (ADR-0036).
     *
     * Même plafond que LinkExtractorService::MAX_CANDIDATES_PER_AGGREGATOR —
     * cohérence de coût/latence entre les deux gisements.
     */
    private const OPPORTUNITY_BATCH_SIZE = 50;

    /**
     * Valeurs acceptées pour l'option --pool.
     *
     * @var string[]
     */
    private const VALID_POOLS = ['aggregators', 'opportunities', 'all'];

    public function __construct(
        // Client HTTP Symfony (symfony/http-client) — pour télécharger les pages agrégateurs
        private readonly HttpClientInterface $httpClient,
        // Repository des sources existantes — pour vérifier les doublons d'URL
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // Repository des suggestions — pour vérifier les doublons + persister les nouvelles
        private readonly SuggestedSourceRepository $suggestedSourceRepository,
        // Repository des opportunités collectées — gisement ADR-0036 (lecture seule)
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
        // Service LLM — pour analyser les candidats filtrés et identifier les organismes
        private readonly LlmExtractorService $llmExtractorService,
        // Service de paramètres — pour lire discovery_enabled et discovery_max_suggestions
        private readonly SettingService $settingService,
        // EntityManager — pour persister les SuggestedSource en BDD
        private readonly EntityManagerInterface $em,
        // Logger PSR-3 — pour tracer les erreurs sans interrompre la commande
        private readonly LoggerInterface $logger,
        // Service d'extraction de liens — pré-filtre le HTML/RSS avant d'appeler le LLM
        private readonly LinkExtractorService $linkExtractor,
        // ADR-0034 — tente l'auto-validation RSS de chaque nouvelle suggestion
        // (garde-fou : FeedDetectorService confirme un flux réellement fonctionnel)
        private readonly SuggestedSourceAutoValidationService $autoValidationService,
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
                'Limite le gisement agrégateurs à ce scraperSlug (ex: on-the-move) — ignoré si --pool=opportunities'
            )
            ->addOption(
                'pool',
                null,
                InputOption::VALUE_REQUIRED,
                'Gisement(s) à analyser : aggregators, opportunities ou all',
                'all'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var string|null $sourceSlug */
        $sourceSlug = $input->getOption('source');
        $pool = strtolower((string) $input->getOption('pool'));

        $io->title('BazaArt — Découverte automatique de nouvelles sources');

        // ── Validation de --pool ──────────────────────────────────────────────
        if (!in_array($pool, self::VALID_POOLS, strict: true)) {
            $io->error(sprintf(
                'Option --pool invalide : "%s". Valeurs acceptées : %s.',
                $pool,
                implode(', ', self::VALID_POOLS)
            ));
            return Command::INVALID;
        }

        $runAggregators   = $pool === 'aggregators' || $pool === 'all';
        $runOpportunities = $pool === 'opportunities' || $pool === 'all';

        $io->text(sprintf('Gisement(s) analysé(s) : %s', $pool));

        if ($dryRun) {
            $io->note('Mode --dry-run activé : aucune SuggestedSource ne sera créée en BDD.');
        }

        if ($sourceSlug !== null && !$runAggregators) {
            $io->note('Option --source ignorée : --pool=opportunities ne concerne pas les agrégateurs.');
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
        // Ce plafond est PARTAGÉ entre les deux gisements (le gisement agrégateurs est
        // traité en premier ; si le plafond est atteint, le gisement opportunités n'est
        // pas lancé).
        $maxSuggestions = (int) ($this->settingService->get('discovery_max_suggestions', '30') ?? '30');
        if ($maxSuggestions <= 0) {
            $maxSuggestions = 30;
        }

        $io->text(sprintf('Plafond : %d nouvelle(s) suggestion(s) par run (partagé entre les gisements).', $maxSuggestions));

        // ── Étape 2bis : Auto-validation RSS activée ? (ADR-0034) ─────────────
        // Kill-switch admin indépendant de discovery_enabled : permet de suspendre
        // uniquement l'auto-promotion RSS (ex : pour investiguer un problème qualité)
        // sans couper la découverte de nouvelles suggestions elle-même.
        // Comportement en --dry-run : l'auto-validation n'est JAMAIS tentée (dry-run =
        // aucun effet de bord, y compris la création d'une ScrapingSource).
        $rssAutoValidationEnabled = $this->settingService->get('rss_auto_validation_enabled', 'true');
        $autoValidationActive     = !$dryRun
            && ($rssAutoValidationEnabled === 'true' || $rssAutoValidationEnabled === '1');

        if ($dryRun) {
            $io->text('Auto-validation RSS (ADR-0034) : désactivée en mode --dry-run.');
        } elseif (!$autoValidationActive) {
            $io->text(
                'Auto-validation RSS (ADR-0034) : désactivée (setting rss_auto_validation_enabled = "'
                . $rssAutoValidationEnabled . '"). Toutes les suggestions resteront en validation manuelle.'
            );
        } else {
            $io->text('Auto-validation RSS (ADR-0034) : activée — les flux RSS confirmés seront promus automatiquement.');
        }
        $io->newLine();

        // ── Construction de la liste des domaines connus ──────────────────────
        // Chargée UNE SEULE FOIS avant les deux gisements pour éviter des requêtes BDD
        // redondantes. Cette liste sert à LinkExtractorService pour exclure en PHP les
        // liens vers des domaines déjà dans scraping_sources ou suggested_sources.
        $knownDomains = $this->buildKnownDomains();
        $io->text(sprintf('Domaines déjà connus : %d', count($knownDomains)));
        $io->newLine();

        // ── Compteurs pour le rapport final ───────────────────────────────────
        $newSuggestions    = 0; // Suggestions effectivement créées en BDD (tous gisements confondus)
        $skippedNoUrl      = 0; // Ignorés : URL manquante ou invalide
        $skippedDuplicate  = 0; // Ignorés : déjà dans scraping_sources ou suggested_sources
        $ceilingReached    = false; // Indique si on a atteint le plafond
        $autoValidatedRss  = 0; // ADR-0034 — suggestions RSS auto-promues en ScrapingSource
        $autoValidatedDup  = 0; // ADR-0034 — RSS confirmé mais ScrapingSource déjà existante

        // Compteurs spécifiques au gisement agrégateurs
        $aggregatorsAnalyzed = 0;
        $aggregatorsOk       = 0;
        $aggregatorsKo       = 0;
        $aggregatorCandidates = 0; // Candidats LLM trouvés côté agrégateurs

        // Compteurs spécifiques au gisement opportunités (ADR-0036)
        $opportunityRawCandidates      = 0; // Candidats bruts construits depuis ScrapedResource
        $opportunityFilteredCandidates = 0; // Après filtrage PHP (LinkExtractorService::filterCandidates)
        $opportunityLlmCandidates      = 0; // Candidats retournés par le LLM

        // ── Closure partagée : traite un candidat LLM → SuggestedSource ───────
        // Utilisée par les deux gisements pour éviter de dupliquer la logique de
        // déduplication / création / auto-validation. Retourne true si le plafond
        // global est atteint (l'appelant doit alors arrêter sa boucle).
        //
        // @param array{nom: string, url: string|null, pays_zone: string|null, discipline: string|null, raison: string|null} $candidate
        $processCandidate = function (
            array $candidate,
            string $origine,
            string $sourceOrigine,
        ) use (
            $io,
            $dryRun,
            &$newSuggestions,
            $maxSuggestions,
            &$skippedNoUrl,
            &$skippedDuplicate,
            &$ceilingReached,
            $autoValidationActive,
            &$autoValidatedRss,
            &$autoValidatedDup,
        ): bool {
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
                return false;
            }

            // Vérification 2 : déjà dans scraping_sources ?
            if ($this->scrapingSourceRepository->findByUrl($url) !== null) {
                $io->text(sprintf('  DOUBLON scraping_sources : %s (%s)', $candidate['nom'], $url));
                $skippedDuplicate++;
                return false;
            }

            // Vérification 3 : déjà dans suggested_sources ?
            if ($this->suggestedSourceRepository->existsByUrl($url)) {
                $io->text(sprintf('  DOUBLON suggested_sources : %s (%s)', $candidate['nom'], $url));
                $skippedDuplicate++;
                return false;
            }

            // Vérification 4 : plafond atteint ? (partagé entre les deux gisements)
            if ($newSuggestions >= $maxSuggestions) {
                $ceilingReached = true;
                $io->warning(sprintf('Plafond de %d suggestion(s) atteint. Arrêt de la découverte.', $maxSuggestions));
                $this->logger->info('[DiscoverSources] Plafond de suggestions atteint.', ['max' => $maxSuggestions]);
                return true;
            }

            // ── Création de la SuggestedSource ────────────────────────────────
            $suggestion = new SuggestedSource();
            $suggestion->setNomOrganisme($candidate['nom']);
            $suggestion->setUrl($url);
            $suggestion->setPaysZone($candidate['pays_zone'] ?? null);
            $suggestion->setDisciplinePressentie($candidate['discipline'] ?? null);
            $suggestion->setRaisonSuggestion($candidate['raison'] ?? null);
            // Origine du gisement (ADR-0036) : AGREGATEUR (historique) ou OPPORTUNITE (nouveau)
            $suggestion->setOrigine($origine);
            $suggestion->setSourceOrigine($sourceOrigine);
            $suggestion->setDateDecouverte(new \DateTime());
            // statut = AValider est déjà le défaut dans le constructeur de SuggestedSource

            if (!$dryRun) {
                // En mode normal, on persist (le flush sera fait après les deux gisements)
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

            // ── ADR-0034 : tentative d'auto-validation RSS ────────────────────
            // Uniquement si activée (setting + pas de --dry-run — voir Étape 2bis).
            if ($autoValidationActive) {
                $autoResult = $this->autoValidationService->tryAutoValidate($suggestion);

                if ($autoResult === SuggestedSourceAutoValidationService::RESULT_AUTO_VALIDATED) {
                    $io->text(sprintf('    → AUTO-VALIDÉE (RSS confirmé) : %s promue en source active.', $candidate['nom']));
                    $autoValidatedRss++;
                } elseif ($autoResult === SuggestedSourceAutoValidationService::RESULT_DUPLICATE) {
                    $io->text(sprintf('    → RSS confirmé mais source déjà existante pour %s (aucune recréation).', $candidate['nom']));
                    $autoValidatedDup++;
                }
                // RESULT_MANUAL / RESULT_NO_URL : reste en validation manuelle, rien à logguer ici.
            }

            return false;
        };

        // ── GISEMENT 1 : Agrégateurs (HTML ou RSS/Atom, ADR-0036 point A) ─────
        if ($runAggregators) {
            [$aggregatorsAnalyzed, $aggregatorsOk, $aggregatorsKo, $aggregatorCandidates] = $this->discoverFromAggregators(
                $io,
                $sourceSlug,
                $knownDomains,
                $processCandidate,
                $ceilingReached,
            );
        }

        // ── GISEMENT 2 : Opportunités déjà collectées (ADR-0036 point B) ─────
        // On ne lance ce gisement que si le plafond n'a pas déjà été atteint par
        // le gisement agrégateurs (inutile d'analyser pour rien).
        if ($runOpportunities && !$ceilingReached) {
            [$opportunityRawCandidates, $opportunityFilteredCandidates, $opportunityLlmCandidates] = $this->discoverFromOpportunities(
                $io,
                $knownDomains,
                $processCandidate,
                $ceilingReached,
            );
        } elseif ($runOpportunities && $ceilingReached) {
            $io->text('Gisement "opportunités déjà collectées" ignoré : plafond déjà atteint par le gisement agrégateurs.');
        }

        $totalCandidates = $aggregatorCandidates + $opportunityLlmCandidates;

        // ── Étape 5 : Flush (si pas en dry-run) ──────────────────────────────
        // Un seul flush en fin de traitement — une seule transaction pour toutes les suggestions
        // des deux gisements.
        //
        // DURCISSEMENT (correctif SSRF, lot ADR-0034) : ce flush unique persiste TOUT le
        // batch (suggestions + éventuelles ScrapingSource auto-validées) en une seule
        // transaction. Une collision rare (ex : deux runs concurrents découvrent la même
        // URL, ou une contrainte unique existante en BDD que ce run ignorait) ferait
        // échouer toute la transaction et perdre l'intégralité du batch, alors qu'un seul
        // enregistrement est en cause. On isole donc ce cas avec un try/catch ciblé sur
        // UniqueConstraintViolationException (Doctrine) : on logue l'incident et on
        // continue — la commande se termine proprement plutôt que de planter,
        // et le prochain run pourra retenter les enregistrements non persistés.
        if (!$dryRun && $newSuggestions > 0) {
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException $e) {
                $io->warning(
                    'Collision de contrainte unique lors du flush final — le batch n\'a pas '
                    . 'pu être persisté intégralement. Voir les logs pour le détail.'
                );
                $this->logger->error(
                    '[DiscoverSources] UniqueConstraintViolationException lors du flush final.',
                    ['erreur' => $e->getMessage()]
                );
            }
        }

        // ── Étape 6 : Rapport de synthèse ─────────────────────────────────────
        $io->newLine();
        $io->title('Rapport de découverte');

        $io->definitionList(
            ['Gisement analysé'        => $pool],
            ['Agrégateurs analysés'    => $aggregatorsAnalyzed . ' (OK: ' . $aggregatorsOk . ', KO: ' . $aggregatorsKo . ')'],
            ['  candidats LLM (agrégateurs)' => $aggregatorCandidates],
            ['Opportunités (12 mois) — candidats bruts' => $opportunityRawCandidates],
            ['  après filtrage'        => $opportunityFilteredCandidates],
            ['  candidats LLM (opportunités)' => $opportunityLlmCandidates],
            ['Candidats LLM (total)'   => $totalCandidates],
            ['Nouvelles suggestions'   => $dryRun ? $newSuggestions . ' (simulation)' : $newSuggestions],
            ['  dont auto-validées RSS (ADR-0034)' => $autoValidatedRss],
            ['  dont RSS déjà existantes (doublon)' => $autoValidatedDup],
            ['Doublons ignorés'        => $skippedDuplicate],
            ['Sans URL (ignorés)'      => $skippedNoUrl],
            ['Plafond atteint'         => $ceilingReached ? 'Oui (' . $maxSuggestions . ')' : 'Non'],
        );

        if ($newSuggestions > 0 && !$dryRun) {
            $pendingForAdmin = $newSuggestions - $autoValidatedRss - $autoValidatedDup;
            $io->success(sprintf(
                '%d nouvelle(s) suggestion(s) créée(s), dont %d auto-validée(s) RSS (ADR-0034). '
                . '%d en attente de validation manuelle sur /admin/suggested-sources.',
                $newSuggestions,
                $autoValidatedRss,
                max(0, $pendingForAdmin)
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

        // ── Étape 7 : résumé logué (ADR-0036, point E — visibilité des échecs) ─
        // Un logger->info récapitulatif en fin de run, distinct des logs par étape,
        // pour qu'un humain (ou une alerte de supervision sur les logs) puisse voir
        // d'un coup d'œil si le run a été fructueux sans avoir à relire tout le détail.
        $this->logger->info('[DiscoverSources] Résumé du run.', [
            'pool'                             => $pool,
            'dry_run'                          => $dryRun,
            'agregateurs_analyses'             => $aggregatorsAnalyzed,
            'agregateurs_ok'                   => $aggregatorsOk,
            'agregateurs_ko'                   => $aggregatorsKo,
            'candidats_llm_agregateurs'        => $aggregatorCandidates,
            'opportunites_candidats_bruts'     => $opportunityRawCandidates,
            'opportunites_candidats_filtres'   => $opportunityFilteredCandidates,
            'candidats_llm_opportunites'       => $opportunityLlmCandidates,
            'nouvelles_suggestions'            => $newSuggestions,
            'auto_validees_rss'                => $autoValidatedRss,
            'auto_validees_doublon'            => $autoValidatedDup,
            'doublons_ignores'                 => $skippedDuplicate,
            'sans_url_ignores'                 => $skippedNoUrl,
            'plafond_atteint'                  => $ceilingReached,
        ]);

        // ── Détermination du code de sortie (ADR-0036, point E) ───────────────
        // FAILURE uniquement si TOUS les agrégateurs analysés ont échoué (aucun OK,
        // et au moins un a été tenté) ET que le gisement opportunités n'a produit
        // aucun candidat filtré (ou n'a pas été lancé). Dans tous les autres cas
        // (au moins un agrégateur OK, ou des candidats côté opportunités, ou un
        // gisement non concerné par --pool), on retourne SUCCESS — "rien trouvé"
        // n'est pas un échec technique.
        $aggregatorsAllFailed = $runAggregators && $aggregatorsAnalyzed > 0 && $aggregatorsOk === 0;
        $opportunitiesEmpty   = !$runOpportunities || $opportunityFilteredCandidates === 0;

        if ($runAggregators && $aggregatorsAllFailed && $opportunitiesEmpty) {
            $io->error(
                'Tous les agrégateurs analysés ont échoué et le gisement opportunités n\'a produit '
                . 'aucun candidat — run considéré en échec (voir les logs pour le détail par agrégateur).'
            );
            $this->logger->error('[DiscoverSources] Run en échec : tous les agrégateurs KO et gisement opportunités vide.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Gisement 1 (historique) — analyse les pages des agrégateurs (HTML ou RSS/Atom).
     *
     * @param callable(array{nom: string, url: string|null, pays_zone: string|null, discipline: string|null, raison: string|null}, string, string): bool $processCandidate
     *        Closure partagée qui traite un candidat LLM → SuggestedSource et retourne
     *        true si le plafond global est atteint.
     * @param string[] $knownDomains
     * @return array{0: int, 1: int, 2: int, 3: int} [analysés, OK, KO, candidats LLM]
     */
    private function discoverFromAggregators(
        SymfonyStyle $io,
        ?string $sourceSlug,
        array $knownDomains,
        callable $processCandidate,
        bool &$ceilingReached,
    ): array {
        $aggregators = $this->scrapingSourceRepository->findActiveAggregators();

        // Si un slug est spécifié, on filtre pour ne traiter que cet agrégateur
        if ($sourceSlug !== null) {
            $aggregators = array_values(array_filter(
                $aggregators,
                fn ($agg) => $agg->getScraperSlug() === $sourceSlug
            ));

            if (empty($aggregators)) {
                $io->warning(sprintf(
                    'Aucun agrégateur actif trouvé avec le slug "%s". '
                    . 'Vérifiez que la source existe et est marquée estAgregateur = true.',
                    $sourceSlug
                ));
                return [0, 0, 0, 0];
            }
        }

        if (empty($aggregators)) {
            $io->warning(
                'Aucun agrégateur actif trouvé en BDD. '
                . 'Lancez "app:seed-scraping-sources --force" pour marquer les agrégateurs, '
                . 'ou ajoutez manuellement des sources avec estAgregateur = true.'
            );
            return [0, 0, 0, 0];
        }

        $io->text(sprintf(
            '%d agrégateur(s) à analyser : %s',
            count($aggregators),
            implode(', ', array_map(fn ($a) => $a->getNom(), $aggregators))
        ));
        $io->newLine();

        $ok             = 0;
        $ko             = 0;
        $llmCandidates  = 0;

        foreach ($aggregators as $aggregator) {
            $aggregatorUrl = $aggregator->getUrl();
            $aggregatorNom = $aggregator->getNom();

            $io->section(sprintf('Analyse : %s', $aggregatorNom));
            $io->text(sprintf('  URL : %s', $aggregatorUrl));

            // ── Téléchargement du contenu (HTML ou flux RSS/Atom) ─────────────
            $html = $this->downloadHtml($aggregatorUrl, $io);

            if ($html === null) {
                // L'erreur a déjà été loguée en warning dans downloadHtml()/fetchHtml()
                $ko++;
                continue;
            }

            $io->text(sprintf('  Contenu téléchargé : %d caractères', mb_strlen($html)));

            // ── Pré-filtrage PHP des liens (HTML classique OU flux RSS/Atom) ──
            // extractAndFilter() détecte automatiquement le cas RSS/Atom (ADR-0036).
            $linkCandidates = $this->linkExtractor->extractAndFilter($html, $aggregatorUrl, $knownDomains);

            $io->text(sprintf('  Liens pré-filtrés : %d candidat(s) transmis au LLM.', count($linkCandidates)));

            if (empty($linkCandidates)) {
                // ADR-0036 point E : visibilité des échecs — un agrégateur qui répond
                // mais ne produit aucun candidat mérite un warning (pas juste un debug),
                // pour que l'équipe puisse investiguer (page vide, structure changée...).
                $io->text('  Aucun candidat après filtrage PHP — page ignorée.');
                $this->logger->warning('[DiscoverSources] Agrégateur sans candidat après filtrage.', [
                    'agregateur' => $aggregatorNom,
                    'url'        => $aggregatorUrl,
                ]);
                // Le téléchargement a réussi (HTTP 200) — on compte quand même l'agrégateur
                // comme "OK" côté disponibilité réseau, mais sans candidat exploitable.
                $ok++;
                continue;
            }

            $ok++;

            // ── Appel au LLM avec la liste compacte de candidats ──────────────
            $candidates = $this->llmExtractorService->discoverSources($linkCandidates, $aggregatorUrl);

            if (empty($candidates)) {
                $io->text('  LLM : aucun organisme détecté parmi les candidats.');
                continue;
            }

            $io->text(sprintf('  LLM : %d organisme(s) candidat(s) détecté(s).', count($candidates)));
            $llmCandidates += count($candidates);

            foreach ($candidates as $candidate) {
                $reachedCeiling = $processCandidate($candidate, 'AGREGATEUR', $aggregatorUrl);
                if ($reachedCeiling) {
                    $ceilingReached = true;
                    return [count($aggregators), $ok, $ko, $llmCandidates];
                }
            }
        }

        return [count($aggregators), $ok, $ko, $llmCandidates];
    }

    /**
     * Gisement 2 (ADR-0036) — analyse les ScrapedResource déjà collectées.
     *
     * Les champs applicationUrl (en priorité) ou url de chaque ScrapedResource des
     * 12 derniers mois pointent souvent vers le site de l'organisme émetteur de
     * l'opportunité — un signal jamais exploité jusqu'ici par app:discover-sources,
     * qui ne regardait que les pages agrégateurs.
     *
     * @param callable(array{nom: string, url: string|null, pays_zone: string|null, discipline: string|null, raison: string|null}, string, string): bool $processCandidate
     * @param string[] $knownDomains
     * @return array{0: int, 1: int, 2: int} [candidats bruts, candidats filtrés, candidats LLM]
     */
    private function discoverFromOpportunities(
        SymfonyStyle $io,
        array $knownDomains,
        callable $processCandidate,
        bool &$ceilingReached,
    ): array {
        $io->section('Analyse : gisement "opportunités déjà collectées" (ADR-0036)');

        $rows = $this->scrapedResourceRepository->findRecentForSourceDiscovery();
        $io->text(sprintf('  %d ScrapedResource(s) sur les 12 derniers mois.', count($rows)));

        if (empty($rows)) {
            $io->text('  Aucune opportunité récente — gisement ignoré.');
            $this->logger->warning('[DiscoverSources] Gisement opportunités vide (aucune ScrapedResource sur 12 mois).');
            return [0, 0, 0];
        }

        // ── Construction des candidats + carte de traçabilité de l'origine ────
        // Pour chaque candidat envoyé au LLM, on garde le moyen de retrouver l'URL
        // de la ScrapedResource dont il est issu (pour renseigner sourceOrigine),
        // par correspondance exacte d'URL PUIS par domaine (fallback plus robuste
        // si le LLM reformule légèrement l'URL retournée).
        $candidates      = [];
        $originByUrl      = [];
        $originByHost     = [];

        foreach ($rows as $row) {
            $candidateUrl = $row['applicationUrl'] ?? $row['url'] ?? null;
            if (empty($candidateUrl)) {
                continue; // Rien à proposer pour cette opportunité (ni applicationUrl ni url)
            }

            $candidates[] = ['text' => $row['title'], 'url' => $candidateUrl];

            // L'origine à tracer est TOUJOURS l'URL de la page scrapée elle-même
            // (row['url']) — même quand le candidat envoyé au LLM est basé sur
            // applicationUrl. À défaut (url absent), on retombe sur le candidat lui-même.
            $originUrl = $row['url'] ?? $candidateUrl;

            $normalizedUrl = $this->linkExtractor->normalizeUrl($candidateUrl);
            $originByUrl[$normalizedUrl] = $originUrl;

            $host = parse_url($normalizedUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $originByHost[strtolower($host)] = $originUrl;
            }
        }

        $rawCount = count($candidates);

        if ($rawCount === 0) {
            $io->text('  Aucun candidat exploitable (ni applicationUrl ni url) — gisement ignoré.');
            $this->logger->warning('[DiscoverSources] Gisement opportunités : aucun candidat exploitable.');
            return [0, 0, 0];
        }

        // ── Pipeline de filtrage commun (ADR-0036) ────────────────────────────
        // baseHost = '' : pas de host de référence unique (chaque candidat vient
        // d'un site source différent) — filterInternalLinks() devient un no-op.
        $filtered = $this->linkExtractor->filterCandidates(
            $candidates,
            '',
            $knownDomains,
            'gisement:opportunites'
        );

        $io->text(sprintf('  Candidats bruts : %d → après filtrage : %d.', $rawCount, count($filtered)));

        if (empty($filtered)) {
            $io->text('  Aucun candidat après filtrage — gisement ignoré.');
            $this->logger->warning('[DiscoverSources] Gisement opportunités : 0 candidat après filtrage PHP.', [
                'candidats_bruts' => $rawCount,
            ]);
            return [$rawCount, 0, 0];
        }

        // ── Envoi au LLM par lots de 50 max (cohérent avec le plafond agrégateur) ──
        $batches   = array_chunk($filtered, self::OPPORTUNITY_BATCH_SIZE);
        $llmTotal  = 0;

        foreach ($batches as $i => $batch) {
            $io->text(sprintf('  Lot %d/%d : %d candidat(s) envoyés au LLM.', $i + 1, count($batches), count($batch)));

            $llmCandidates = $this->llmExtractorService->discoverSources(
                $batch,
                sprintf('Gisement "opportunités déjà collectées" (lot %d/%d)', $i + 1, count($batches))
            );

            if (empty($llmCandidates)) {
                $io->text('    LLM : aucun organisme détecté dans ce lot.');
                continue;
            }

            $io->text(sprintf('    LLM : %d organisme(s) candidat(s) détecté(s).', count($llmCandidates)));
            $llmTotal += count($llmCandidates);

            foreach ($llmCandidates as $candidate) {
                $sourceOrigine = $this->resolveOpportunityOrigin($candidate['url'] ?? null, $originByUrl, $originByHost);

                $reachedCeiling = $processCandidate($candidate, 'OPPORTUNITE', $sourceOrigine);
                if ($reachedCeiling) {
                    $ceilingReached = true;
                    return [$rawCount, count($filtered), $llmTotal];
                }
            }
        }

        return [$rawCount, count($filtered), $llmTotal];
    }

    /**
     * Retrouve l'URL de la ScrapedResource d'origine pour un candidat retourné par le LLM.
     *
     * Stratégie en cascade :
     *   1. Correspondance exacte d'URL normalisée (cas le plus courant : le LLM
     *      reprend l'URL du candidat telle quelle, comme demandé dans le prompt)
     *   2. Correspondance par domaine (fallback si le LLM a légèrement reformulé
     *      l'URL, ex: ajout/suppression d'un chemin)
     *   3. Libellé générique si aucune correspondance (ne devrait arriver que si
     *      le LLM invente une URL totalement différente des candidats fournis)
     *
     * @param array<string, string> $originByUrl  Clé = URL normalisée, valeur = URL d'origine
     * @param array<string, string> $originByHost Clé = host normalisé, valeur = URL d'origine
     */
    private function resolveOpportunityOrigin(?string $candidateUrl, array $originByUrl, array $originByHost): string
    {
        if (empty($candidateUrl)) {
            return 'Gisement "opportunités déjà collectées" (URL d\'origine non résolue)';
        }

        $normalized = $this->linkExtractor->normalizeUrl($candidateUrl);
        if (isset($originByUrl[$normalized])) {
            return $originByUrl[$normalized];
        }

        $host = parse_url($normalized, PHP_URL_HOST);
        if (is_string($host) && isset($originByHost[strtolower($host)])) {
            return $originByHost[strtolower($host)];
        }

        // Aucune correspondance — on retombe sur l'URL du candidat lui-même : c'est
        // la meilleure trace disponible, même si ce n'est pas l'URL de la page scrapée.
        return $candidateUrl;
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
     * PERFORMANCE : Cette méthode est appelée UNE SEULE FOIS avant les deux gisements.
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
     * Télécharge le HTML (ou flux RSS/Atom) d'une URL agrégateur avec retry automatique sans SSL.
     *
     * Stratégie en deux temps :
     *   1. Première tentative en SSL standard (verify_peer = true).
     *   2. Si l'erreur contient 'ssl' ou 'certificate' (ex : certificat intermédiaire
     *      manquant comme sur resartis.org avec DigiCert RapidSSL), on relance
     *      la requête avec verify_peer = false et on affiche un avertissement.
     *      Ce comportement est identique à AbstractScraper::fetchHtmlInsecure().
     *
     * Toute autre erreur (timeout, DNS, HTTP non-200) est loguée et retourne null
     * sans retry — la commande continue sur l'agrégateur suivant.
     *
     * @param string       $url URL à télécharger
     * @param SymfonyStyle $io  Interface console pour afficher les avertissements
     * @return string|null Contenu téléchargé (HTML ou XML), ou null si erreur non récupérable
     */
    private function downloadHtml(string $url, SymfonyStyle $io): ?string
    {
        // --- Première tentative : SSL activé (comportement normal) ---
        $lastSslError = null;
        $html = $this->fetchHtml($url, verifyPeer: true, lastError: $lastSslError);

        if ($html !== null) {
            // Succès dès la première tentative — cas nominal
            return $html;
        }

        // --- Vérification : l'échec est-il dû à un problème SSL ? ---
        // On inspecte le message d'erreur stocké par fetchHtml() via le paramètre
        // $lastError passé par référence. Si le message mentionne SSL ou un
        // certificat, on tente un second appel sans vérification.
        if ($lastSslError !== null && $this->isSslError($lastSslError)) {
            $warningMessage = sprintf(
                'SSL invalide pour %s (%s) — nouvelle tentative sans vérification SSL (verify_peer=false).',
                $url,
                $lastSslError
            );
            $io->warning($warningMessage);
            $this->logger->warning('[DiscoverSources] ' . $warningMessage);

            // --- Deuxième tentative : SSL désactivé (fallback insecure) ---
            // Même comportement qu'AbstractScraper::fetchHtmlInsecure()
            $ignoredError = null;
            $html = $this->fetchHtml($url, verifyPeer: false, lastError: $ignoredError);

            if ($html !== null) {
                $this->logger->info('[DiscoverSources] Succès en mode insecure.', ['url' => $url]);
                return $html;
            }

            // Le retry a aussi échoué (ex : erreur réseau distincte)
            $finalMessage = sprintf(
                'Échec aussi en mode insecure pour %s — agrégateur ignoré.',
                $url
            );
            $io->warning($finalMessage);
            $this->logger->error('[DiscoverSources] ' . $finalMessage);
            return null;
        }

        // L'erreur n'est pas SSL (timeout, DNS, HTTP non-200...) — pas de retry
        // Le warning a déjà été affiché dans fetchHtml(), on retourne null directement.
        return null;
    }

    /**
     * Vérifie si un message d'erreur correspond à un problème SSL/certificat.
     *
     * Utilisé pour décider si un retry sans vérification SSL est justifié.
     * On détecte les mots-clés 'ssl' et 'certificate' (insensible à la casse)
     * qui couvrent les messages OpenSSL typiques :
     *   - "SSL certificate OpenSSL verify result: unable to get local issuer certificate"
     *   - "SSL: no alternative certificate subject name matches target host"
     *   - etc.
     *
     * @param string $errorMessage Message d'erreur à analyser
     * @return bool true si l'erreur est de nature SSL
     */
    private function isSslError(string $errorMessage): bool
    {
        $lower = strtolower($errorMessage);

        return str_contains($lower, 'ssl') || str_contains($lower, 'certificate');
    }

    /**
     * Effectue la requête HTTP réelle et retourne le contenu (HTML ou XML) ou null.
     *
     * C'est la méthode de bas niveau — downloadHtml() orchestre la logique de retry,
     * fetchHtml() ne fait qu'une seule tentative.
     *
     * Le paramètre $lastError est passé par référence : si une exception est levée,
     * fetchHtml() y stocke le message pour que l'appelant puisse décider d'un retry
     * SSL sans avoir à réexposer les détails internes de l'exception.
     *
     * Configuration de la requête :
     *   - User-Agent : navigateur Chrome (voir USER_AGENT)
     *   - Timeout    : 30 secondes (voir HTTP_TIMEOUT)
     *   - verify_peer : contrôlé par le paramètre $verifyPeer
     *   - Redirections : suivies automatiquement par Symfony HttpClient
     *
     * @param string      $url        URL à télécharger
     * @param bool        $verifyPeer true = SSL standard, false = mode insecure
     * @param string|null $lastError  Référence modifiée avec le message d'erreur si exception
     * @return string|null Contenu décompressé (HTML ou XML), ou null si erreur
     */
    private function fetchHtml(string $url, bool $verifyPeer = true, ?string &$lastError = null): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    // On se présente comme un navigateur pour éviter les blocages
                    'User-Agent'      => self::USER_AGENT,
                    // On accepte le français en priorité (sites majoritairement francophones)
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                    // NOTE : on NE pose PAS Accept-Encoding manuellement.
                    // Quand ce header est posé à la main, Symfony HTTP Client bypass
                    // sa décompression automatique → getContent() retourne les octets
                    // compressés bruts (gzip ~24 % de la taille réelle), ce qui provoque
                    // une troncature apparente (ex : 23 853 chars au lieu de 99 000).
                    // Sans ce header, Symfony négocie la compression lui-même ET
                    // décompresse automatiquement avant de retourner le contenu.
                ],
                // Timeout : 30 secondes (les pages agrégateurs peuvent être lentes)
                'timeout'     => self::HTTP_TIMEOUT,
                // Contrôle de la vérification SSL :
                //   true  = comportement par défaut (certificat validé)
                //   false = mode insecure pour les sites avec chaîne SSL incomplète
                //           (ex : resartis.org, DigiCert RapidSSL TLS RSA CA G1 manquant)
                'verify_peer' => $verifyPeer,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                // Erreur HTTP — pas une erreur SSL, pas de retry pertinent
                // ADR-0036 point E : logger->warning (pas debug) pour la visibilité des échecs
                $message = sprintf(
                    'HTTP %d pour %s — agrégateur ignoré pour ce run.',
                    $statusCode,
                    $url
                );
                $this->logger->warning('[DiscoverSources] ' . $message);
                // On stocke le message pour l'appelant, mais sans le préfixe "SSL" :
                // isSslError() retournera false et aucun retry SSL ne sera tenté.
                $lastError = $message;
                return null;
            }

            // getContent() retourne le contenu décompressé (Symfony gère la décompression
            // automatiquement tant qu'on ne pose pas Accept-Encoding à la main).
            $html = $response->getContent();

            // Log debug : taille réelle reçue — visible avec -vvv pour diagnostiquer
            // les troncatures (ex : 23 853 chars au lieu de 99 000 = gzip non décompressé)
            $this->logger->debug('[DiscoverSources] Contenu reçu.', [
                'url'               => $url,
                'verify_peer'       => $verifyPeer,
                'octets_getContent' => strlen($html),
                'chars_mb'          => mb_strlen($html),
            ]);

            if (empty(trim($html))) {
                // ADR-0036 point E : logger->warning (pas debug)
                $message = sprintf('Contenu vide pour %s — agrégateur ignoré.', $url);
                $this->logger->warning('[DiscoverSources] ' . $message);
                $lastError = $message;
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            // Timeout, SSL, DNS — on stocke le message pour que downloadHtml()
            // puisse décider si un retry sans SSL est justifié.
            $lastError = $e->getMessage();
            $this->logger->error('[DiscoverSources] Erreur réseau.', [
                'url'         => $url,
                'verify_peer' => $verifyPeer,
                'erreur'      => $lastError,
            ]);
            return null;
        }
    }
}

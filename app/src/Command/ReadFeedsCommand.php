<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScrapingSource;
use App\Enum\ScrapingSourceType;
use App\Repository\ScrapingSourceRepository;
use App\Service\FeedReadResult;
use App\Service\FeedReaderService;
use App\Service\ScrapedResourcePersister;
use App\Service\SettingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ReadFeedsCommand — Orchestrateur du pipeline RSS (app:read-feeds).
 *
 * Cette commande gère l'intégralité du cycle de vie des sources RSS :
 *   1. Vérifie le switch admin scraping_enabled (cohérent avec app:scrape-opportunities)
 *   2. Charge les sources actives de type RSS uniquement
 *   3. Pour chaque source : fetch + parse via FeedReaderService, puis persist via ScrapedResourcePersister
 *   4. Gère le suivi de santé : lastSuccessfulFetch, consecutiveFailures, auto-désactivation à 5 échecs
 *   5. Affiche un tableau récapitulatif dans le terminal (SymfonyStyle)
 *
 * ── SÉPARATION DES PIPELINES ────────────────────────────────────────────────
 * Cette commande traite UNIQUEMENT les sources RSS.
 * app:scrape-opportunities traite uniquement les sources HtmlLlm et HtmlCss.
 * Cette séparation permet des cadences différentes :
 *   - RSS (léger, pas de LLM) → toutes les 6h
 *   - Scrape HTML+LLM (coûteux, clé API) → 3x/semaine
 *
 * ── DÉCISION INFRA (D2) ─────────────────────────────────────────────────────
 * Cette commande est déclenchée par cron (pas par Symfony Scheduler/Messenger).
 * Supervisor n'est pas opérationnel sur la droplet au moment du développement V1.
 * Migration vers Scheduler+worker prévue post-lancement.
 *
 * ── PUBLICATION ─────────────────────────────────────────────────────────────
 * TOUTES les ressources partent en file de modération (status = pending).
 * Le champ autoPublish de ScrapingSource n'est PAS utilisé — il est préparatoire V2.
 * Un admin valide depuis /admin/scraped-opportunities.
 *
 * Lancement manuel :
 *   docker compose exec app php bin/console app:read-feeds
 *   docker compose exec app php bin/console app:read-feeds --dry-run
 *   docker compose exec app php bin/console app:read-feeds --source="CNM - Centre National de la Musique"
 *
 * Cron (toutes les 6h) — expression : "0 *-slash-6 * * *"
 *   (l'astérisque-slash dans une expression cron termine un bloc de commentaire PHP,
 *    voir docs/scraping-cron.md pour la ligne crontab complète)
 */
#[AsCommand(
    name: 'app:read-feeds',
    description: 'Lit les flux RSS actifs, détecte les opportunités culturelles et les stocke pour modération',
)]
class ReadFeedsCommand extends Command
{
    // La constante AUTO_DISABLE_THRESHOLD est désormais portée par l'entité ScrapingSource.
    // On l'utilise via ScrapingSource::AUTO_DISABLE_THRESHOLD pour éviter la divergence
    // entre les deux pipelines (RSS et scrape HTML). Plus de constante locale ici.

    public function __construct(
        // Repository pour charger les sources actives depuis la BDD
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // Service de lecture des flux RSS (fetch + parse laminas-feed + filtre mots-clés)
        private readonly FeedReaderService $feedReaderService,
        // Service de persistance avec déduplication (les 5 cas de dédup)
        private readonly ScrapedResourcePersister $persister,
        // EntityManager pour flusher les mises à jour de santé des sources (consecutiveFailures, etc.)
        private readonly EntityManagerInterface $em,
        // SettingService pour vérifier le switch admin scraping_enabled
        private readonly SettingService $settingService,
        // Logger PSR-3 pour les logs de run (info par source, warning auto-désactivation)
        private readonly LoggerInterface $logger,
    ) {
        // ⚠️ parent::__construct() OBLIGATOIRE dans les commandes Symfony qui utilisent
        // constructor property promotion. Sans cet appel, la commande n'est pas correctement
        // initialisée par le conteneur de services.
        parent::__construct();
    }

    /**
     * Déclare les options de la commande.
     *
     * Les options sont cohérentes avec app:scrape-opportunities pour une expérience
     * homogène pour l'admin qui lance les deux commandes :
     *   --dry-run : affiche sans écrire en BDD (pratique pour tester le pipeline)
     *   --source  : filtre par nom exact de source (pratique pour déboguer une source)
     */
    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Affiche les opportunités trouvées sans écrire en base de données (ni persist, ni suivi de santé)'
        );
        $this->addOption(
            'source',
            null,
            InputOption::VALUE_REQUIRED,
            'Lance uniquement la source dont le nom correspond exactement (ex: --source="CNM - Centre National de la Musique")'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Lecture des options — on caste explicitement car getOption() retourne mixed
        $isDryRun = (bool) $input->getOption('dry-run');

        // PHPStan niveau 6 exige que les casts depuis mixed soient explicites
        $rawFilter    = $input->getOption('source');
        $sourceFilter = is_string($rawFilter) ? $rawFilter : null;

        // ── Vérification du switch admin "scraping_enabled" ──────────────────
        // L'admin peut couper le scraping depuis /admin/settings sans toucher au cron.
        // Ce switch bloque à la fois app:scrape-opportunities ET app:read-feeds
        // pour permettre une mise en pause totale du pipeline (ex: quota API épuisé).
        // Valeur par défaut '1' : si le setting n'existe pas en BDD, le scraping est actif.
        if ($this->settingService->get('scraping_enabled', '1') === '0') {
            $io->warning('Scraping désactivé via le dashboard admin (paramètre scraping_enabled = 0).');
            $io->note('Pour forcer malgré tout, activez le paramètre dans /admin/settings.');
            return Command::SUCCESS;
        }

        $io->title('BazaArt — Lecture des flux RSS');

        if ($isDryRun) {
            $io->note('Mode DRY-RUN actif : les données ne seront PAS écrites en base de données');
        }

        // ── Chargement et filtrage des sources RSS actives ───────────────────
        // findAllActive() retourne toutes les sources actives (RSS + HtmlLlm + HtmlCss).
        // On filtre en PHP sur getType() === ScrapingSourceType::RSS car :
        //   1. ScrapingSourceRepository::findAllActive() est partagé avec app:scrape-opportunities
        //   2. Ajouter une méthode findActiveRss() serait correct mais non strictement nécessaire
        //      pour la V1 — on garde la modif minimale demandée (filtre PHP, pas SQL)
        $allActiveSources = $this->scrapingSourceRepository->findAllActive();
        $rssSources       = array_filter(
            $allActiveSources,
            static fn ($source) => $source->getType() === ScrapingSourceType::RSS
        );

        // Filtrage --source si fourni : ne traiter qu'une source par nom exact
        if ($sourceFilter !== null) {
            $rssSources = array_filter(
                $rssSources,
                static fn ($source) => $source->getNom() === $sourceFilter
            );
        }

        // Renumérotation du tableau (array_filter conserve les clés d'origine)
        $rssSources = array_values($rssSources);

        if (empty($rssSources)) {
            $io->warning(
                $sourceFilter !== null
                    ? sprintf('Aucune source RSS active trouvée avec le nom "%s".', $sourceFilter)
                    : 'Aucune source RSS active en BDD. Ajoutez des sources depuis /admin/scraping-sources.'
            );
            return Command::SUCCESS;
        }

        $io->text(sprintf('<info>%d source(s) RSS active(s) à traiter.</info>', count($rssSources)));

        // ── Horodatage du run complet ─────────────────────────────────────────
        // On mesure la durée totale + la durée par source pour les logs de performance.
        $runStartTime = microtime(true);

        // Tableau de résumé pour l'affichage final (une ligne par source)
        /** @var array<int, array{string, string, string, string, string}> $summaryRows */
        $summaryRows = [];

        // Compteurs globaux du run (agrégation de toutes les sources)
        $totalItemsFound = 0;
        $totalInserted   = 0;

        // ── Boucle sur chaque source RSS ──────────────────────────────────────
        foreach ($rssSources as $source) {
            $io->section(sprintf('Flux RSS : %s', $source->getNom()));

            // Mesure de la durée de traitement de cette source
            $sourceStartTime = microtime(true);

            // ── Filet de sécurité global par source ───────────────────────────
            // Ce try/catch est un filet de DERNIER RECOURS pour les exceptions
            // inattendues (BDD coupée, contrainte UNIQUE non anticipée, bug interne…).
            // Il N'EST PAS destiné à remplacer la gestion fine success/failure déjà
            // assurée par FeedReadResult (qui gère les erreurs "normales" : HTTP non-200,
            // timeout, XML invalide). Sans ce filet, une exception sur une source
            // stopperait brutalement le run entier et priverait les sources suivantes
            // de tout traitement.
            //
            // En cas d'exception capturée ici :
            //   - log WARNING (visible dans Sentry/grep) avec le message d'erreur
            //   - ligne "ERREUR INTERNE" dans le tableau récapitulatif
            //   - continue vers la source suivante
            try {
                // ── Lecture du flux via FeedReaderService ─────────────────────
                // On utilise readWithResult() (pas read()) pour distinguer :
                //   - FeedReadResult::success = false → echec HTTP/XML → incrementConsecutiveFailures
                //   - FeedReadResult::success = true + items = [] → flux vide → resetConsecutiveFailures
                //
                // Cette distinction est critique : un flux RSS peut légitimement être vide
                // (pas de nouveaux appels depuis le dernier run). Sans la distinction,
                // un flux vide déclencherait faussement l'auto-désactivation.
                $feedResult = $this->feedReaderService->readWithResult($source);

                // Durée de traitement de cette source, arrondie au ms
                $durationMs = (int) round((microtime(true) - $sourceStartTime) * 1000);

                if (!$feedResult->success) {
                    // ── CAS ÉCHEC : HTTP non-200, timeout, XML invalide ───────
                    $errorMessage = $feedResult->errorMessage ?? 'Erreur inconnue';
                    $io->error(sprintf('Erreur : %s', $errorMessage));

                    if (!$isDryRun) {
                        // markRunError() enregistre l'erreur (badge admin) ET incrémente
                        // consecutiveFailures — plus besoin d'appeler incrementConsecutiveFailures()
                        // séparément. La logique de santé est centralisée dans l'entité.
                        $source->markRunError($errorMessage);

                        // ── Auto-désactivation à 5 échecs consécutifs ─────────
                        // hasReachedFailureThreshold() retourne true quand consecutiveFailures
                        // >= ScrapingSource::AUTO_DISABLE_THRESHOLD (5).
                        // La constante est désormais sur l'entité pour éviter la divergence
                        // entre les deux pipelines (RSS ici, scrape dans ScrapeOpportunitiesCommand).
                        //
                        // NIVEAU WARNING (pas error/critical) : c'est un comportement normal
                        // du cycle de vie — l'admin peut réactiver depuis /admin/scraping-sources.
                        if ($source->hasReachedFailureThreshold()) {
                            $source->setActif(false);
                            $this->logger->warning(
                                sprintf(
                                    '[read-feeds] Source désactivée après %d échecs consécutifs : %s',
                                    $source->getConsecutiveFailures(),
                                    $source->getNom()
                                ),
                                [
                                    'source'              => $source->getNom(),
                                    'consecutiveFailures' => $source->getConsecutiveFailures(),
                                    'lastError'           => $errorMessage,
                                ]
                            );
                            $io->warning(sprintf(
                                'Source désactivée automatiquement après %d échecs consécutifs. '
                                . 'Réactivez-la depuis /admin/scraping-sources après correction.',
                                ScrapingSource::AUTO_DISABLE_THRESHOLD
                            ));
                        }

                        $this->em->flush();
                    }

                    // Correction réserve 2 : niveau WARNING (pas info).
                    // Un flux mort doit être détectable par le monitoring dès le 1er
                    // échec, pas seulement à la désactivation au 5e. Le niveau info
                    // serait filtré par la plupart des alertes de monitoring.
                    // À distinguer du log de désactivation (déjà en warning, correct).
                    $this->logger->warning('[read-feeds] Échec source RSS', [
                        'source'     => $source->getNom(),
                        'success'    => false,
                        'error'      => $errorMessage,
                        'durationMs' => $durationMs,
                    ]);

                    // Ligne du tableau récapitulatif
                    $summaryRows[] = [
                        $source->getNom(),
                        '0',
                        '0',
                        sprintf('%d ms', $durationMs),
                        '<error>ECHEC</error>',
                    ];

                    continue;
                }

                // ── CAS SUCCÈS : flux lu et parsé sans erreur ─────────────────
                $items     = $feedResult->items;
                $itemCount = count($items);
                $totalItemsFound += $itemCount;

                if ($itemCount === 0) {
                    $io->text('<comment>Flux valide, aucune opportunité ne correspond aux mots-clés.</comment>');
                } else {
                    $io->success(sprintf('%d opportunité(s) trouvée(s)', $itemCount));
                }

                // Compteurs de persistance (0 si dry-run)
                $inserted = 0;

                if (!$isDryRun) {
                    // ── Persistance via ScrapedResourcePersister ──────────────
                    // Le persister gère les 5 cas de déduplication (guard intra-lot,
                    // findByUrl, INSERT/réactivation/update/skip) et fait un flush unique.
                    // TOUTES les ressources partent en status = pending (file de modération).
                    // N'utilise PAS autoPublish — champ préparatoire V2.
                    if (!empty($items)) {
                        $persistResult = $this->persister->persistBatch($items);
                        $inserted      = $persistResult->inserted;
                        $totalInserted += $inserted;

                        $io->text(sprintf(
                            'Persistance : %d nouvelle(s) | %d réactivée(s) | %d mise(s) à jour | %d ignorée(s)',
                            $persistResult->inserted,
                            $persistResult->reactivated,
                            $persistResult->updated,
                            $persistResult->skipped,
                        ));
                    }

                    // ── Mise à jour de la santé de la source ──────────────────
                    // markRunSuccess() gère TOUT en un seul appel depuis la factorisation :
                    //   - derniereExecution, nbItemsDernierRun, statutDernierRun, messageErreur = null
                    //   - lastSuccessfulFetch = now  (plus besoin de setLastSuccessfulFetch())
                    //   - consecutiveFailures = 0    (plus besoin de resetConsecutiveFailures())
                    // Un seul point de vérité dans l'entité — pas de risque d'oubli.
                    $source->markRunSuccess($itemCount);

                    // Second flush — DISTINCT du flush interne à persistBatch().
                    // persistBatch() flushe UNIQUEMENT les entités ScrapedResource (nouvelles/mises à jour).
                    // Ce flush-ci porte EXCLUSIVEMENT sur l'entité ScrapingSource (champs de santé :
                    // lastSuccessfulFetch, consecutiveFailures, derniereExecution…).
                    // Ne pas supprimer ce flush en croyant qu'il est redondant avec persistBatch() !
                    $this->em->flush();
                }

                // Log structuré par source (info — pas de warning sur un succès)
                $this->logger->info('[read-feeds] Source RSS traitée', [
                    'source'     => $source->getNom(),
                    'success'    => true,
                    'itemsFound' => $itemCount,
                    'inserted'   => $inserted,
                    'durationMs' => $durationMs,
                ]);

                // Ligne du tableau récapitulatif
                $summaryRows[] = [
                    $source->getNom(),
                    (string) $itemCount,
                    (string) $inserted,
                    sprintf('%d ms', $durationMs),
                    '<info>OK</info>',
                ];

            } catch (\Exception $e) {
                // ── Filet de sécurité : exception inattendue ──────────────────
                // On arrive ici uniquement pour des erreurs non anticipées par FeedReadResult
                // (ex: BDD coupée pendant le flush, contrainte UNIQUE non gérée, bug PHP…).
                // On log en WARNING (pas ERROR/CRITICAL) car le run continue sur les autres sources.
                $durationMs = (int) round((microtime(true) - $sourceStartTime) * 1000);

                $this->logger->warning('[read-feeds] Erreur inattendue sur la source', [
                    'source'     => $source->getNom(),
                    'error'      => $e->getMessage(),
                    'durationMs' => $durationMs,
                ]);

                $io->error(sprintf(
                    'Erreur inattendue sur "%s" : %s — source ignorée, traitement des sources suivantes.',
                    $source->getNom(),
                    $e->getMessage()
                ));

                // Ligne du tableau récapitulatif signalant l'erreur interne
                $summaryRows[] = [
                    $source->getNom(),
                    '0',
                    '0',
                    sprintf('%d ms', $durationMs),
                    '<error>ERREUR INTERNE</error>',
                ];

                // On continue vers la source suivante — le run ne doit pas s'arrêter
                continue;
            }
        }

        // ── Tableau récapitulatif du run ──────────────────────────────────────
        // Affiché à la fin du run, cohérent avec le style de app:scrape-opportunities.
        // Une ligne par source avec : nom, items trouvés, items nouveaux, durée, statut.
        $totalDurationMs = (int) round((microtime(true) - $runStartTime) * 1000);

        $io->section('Récapitulatif du run');
        $io->table(
            ['Source', 'Items trouvés', 'Nouveaux insérés', 'Durée', 'Statut'],
            $summaryRows
        );

        // Résumé global de fin de run (log INFO + affichage terminal)
        $summaryMsg = sprintf(
            'Run terminé : %d source(s) traitée(s), %d item(s) trouvé(s), %d nouveau(x) inséré(s) en %d ms',
            count($summaryRows),
            $totalItemsFound,
            $totalInserted,
            $totalDurationMs
        );

        $this->logger->info('[read-feeds] Run terminé', [
            'sourcesCount'    => count($summaryRows),
            'totalItemsFound' => $totalItemsFound,
            'totalInserted'   => $totalInserted,
            'totalDurationMs' => $totalDurationMs,
            'isDryRun'        => $isDryRun,
        ]);

        if ($isDryRun) {
            $io->success($summaryMsg . ' (DRY-RUN — rien écrit en BDD)');
        } else {
            $io->success($summaryMsg);
        }

        return Command::SUCCESS;
    }
}

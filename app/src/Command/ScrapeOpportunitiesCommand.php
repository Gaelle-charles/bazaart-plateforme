<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ScrapingSourceType;
use App\Repository\ScrapedResourceRepository;
use App\Repository\ScrapingSourceRepository;
use App\Service\GenericScraper;
use App\Service\ScrapedResourcePersister;
use App\Service\ScraperRegistry;
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
 * ScrapeOpportunitiesCommand — Collecte les opportunités culturelles depuis les sources BDD.
 *
 * Flux de traitement :
 *   1. Lecture des sources actives depuis scraping_sources (BDD)
 *   2. Pour chaque source :
 *      a. Si scraperSlug renseigné → ScraperRegistry::getBySlug() → scraper PHP custom
 *      b. Si scraperSlug null → GenericScraper (RSS ou HTML_LLM selon le type)
 *      c. Slug inconnu → markRunError() + message visible dans l'admin
 *   3. Sauvegarde des opportunités en BDD (scraped_resources) avec déduplication par URL
 *   4. Archivage automatique des opportunités expirées (deadline passée)
 *   5. Mise à jour des stats de chaque source (markRunSuccess / markRunError)
 *
 * Les sources sont gérées depuis /admin/scraping-sources (plus aucune liste hardcodée ici).
 * Pour ajouter une source : utiliser le formulaire admin ou app:seed-scraping-sources.
 *
 * Lancement manuel :
 *   docker compose exec app php bin/console app:scrape-opportunities
 *
 * Options :
 *   --dry-run : affiche sans écrire en BDD
 *   --debug   : affiche les détails techniques de chaque fetch
 *   --source=NomDeLaSource : lance uniquement la source dont le nom correspond
 *
 * Automatisation (cron 3x/semaine : lundi, mercredi, vendredi à 7h UTC) :
 *   0 7 * * 1,3,5 cd /home/bazaart && docker compose exec -T app php bin/console app:scrape-opportunities --env=prod
 *
 * Prérequis LLM :
 *   Configurer la clé API Mistral (recommandé) ou Anthropic dans /admin/settings.
 */
#[AsCommand(
    name: 'app:scrape-opportunities',
    description: 'Scrape les opportunités culturelles depuis les sources BDD et les stocke dans scraped_resources',
)]
class ScrapeOpportunitiesCommand extends Command
{
    public function __construct(
        // ── Accès aux sources BDD ────────────────────────────────────────────
        // ScrapingSourceRepository lit les sources actives depuis scraping_sources.
        // Plus aucune liste hardcodée ici — tout est géré depuis /admin/scraping-sources.
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // ── Scrapers ─────────────────────────────────────────────────────────
        // ScraperRegistry retourne le scraper PHP custom pour un scraperSlug donné.
        private readonly ScraperRegistry $scraperRegistry,
        // GenericScraper gère les sources sans classe dédiée (scraperSlug = null).
        private readonly GenericScraper $genericScraper,
        // ── Persistence BDD ──────────────────────────────────────────────────
        // EntityManager est conservé pour les opérations sur ScrapingSource (markRunSuccess/Error + flush).
        // La persistance des opportunités est déléguée à ScrapedResourcePersister.
        private readonly EntityManagerInterface $em,
        // ScrapedResourcePersister — factorise la déduplication et la persistance des opportunités.
        // Remplace la boucle inline qui était dans execute() (lignes ~248-350 de l'ancienne version).
        private readonly ScrapedResourcePersister $persister,
        // Repository conservé pour archiveExpired() / archiveExpiredLegacy() (archivage automatique).
        // La déduplication (findByUrl) est désormais gérée par ScrapedResourcePersister.
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
        // SettingService pour lire 'scraping_enabled' (switch admin on/off).
        private readonly SettingService $settingService,
        // Logger PSR-3 pour les avertissements d'auto-désactivation des sources.
        // Le même pattern que ReadFeedsCommand : warning (pas critical) car c'est un
        // comportement normal du cycle de vie d'une source (URL morte, quota épuisé…).
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Affiche les opportunités trouvées sans écrire en base de données'
        );
        $this->addOption(
            'debug',
            null,
            InputOption::VALUE_NONE,
            'Affiche les détails techniques de chaque fetch (status HTTP, taille HTML...)'
        );
        $this->addOption(
            'source',
            null,
            InputOption::VALUE_REQUIRED,
            'Lance uniquement la source dont le nom correspond (ex: --source="CNM - Centre National de la Musique")'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');
        $isDebug  = (bool) $input->getOption('debug');

        // getOption() retourne mixed — on caste explicitement en string|null
        // pour satisfaire PHPStan niveau 6 et éviter toute ambiguïté de type.
        $rawFilter    = $input->getOption('source');
        $sourceFilter = is_string($rawFilter) ? $rawFilter : null;

        // ── Vérification du switch admin "scraping_enabled" ──────────────────
        // L'admin peut couper le scraping depuis /admin/settings sans toucher au cron.
        // Valeur par défaut '1' : si le setting n'existe pas encore, le scraping est actif.
        if ($this->settingService->get('scraping_enabled', '1') === '0') {
            $io->warning('Scraping désactivé via le dashboard admin (paramètre scraping_enabled = 0).');
            $io->note('Pour forcer malgré tout, modifiez le paramètre dans /admin/settings.');
            return Command::SUCCESS;
        }

        $io->title('BazaArt — Scraping des opportunités culturelles');

        if ($isDryRun) {
            $io->note('Mode DRY-RUN actif : les données ne seront PAS écrites en base de données');
        }

        // ── Chargement des sources actives depuis la BDD ─────────────────────
        // La liste vient de la table scraping_sources (gérable depuis /admin/scraping-sources).
        // Cela permet d'ajouter/désactiver des sources sans toucher au code PHP.
        $sources = $this->scrapingSourceRepository->findAllActive();

        if (empty($sources)) {
            $io->warning('Aucune source active en BDD. Lancez app:seed-scraping-sources d\'abord.');
            return Command::SUCCESS;
        }

        /** @var \App\DTO\ScrapedOpportunity[] $allOpportunities */
        $allOpportunities = [];

        // ── Boucle sur chaque source ──────────────────────────────────────────
        foreach ($sources as $source) {

            // ── Exclusion des sources RSS ─────────────────────────────────────
            // Depuis WS3 (chantier scraping), les flux RSS sont traités par une commande
            // dédiée : app:read-feeds (ReadFeedsCommand) via FeedReaderService/laminas-feed.
            //
            // Cette séparation est intentionnelle :
            //   - Les sources RSS ont leur propre suivi de santé (consecutiveFailures,
            //     lastSuccessfulFetch, auto-désactivation à 5 échecs).
            //   - La cadence est différente : RSS toutes les 6h, scrape LLM 3x/semaine.
            //
            // Si on laissait passer les sources RSS ici, elles seraient traitées par
            // GenericScraper::scrapeRss() (SimpleXML, deprecated) ET par FeedReaderService
            // (laminas-feed) → double-traitement = doublons en BDD.
            if ($source->getType() === ScrapingSourceType::RSS) {
                continue;
            }

            // Filtre --source si fourni (filtre par nom exact de la source)
            if ($sourceFilter !== null && $source->getNom() !== $sourceFilter) {
                continue;
            }

            $io->section(sprintf('Scraping : %s (%s)', $source->getNom(), $source->getType()->label()));

            $sourceOpportunities = [];

            try {
                if ($source->hasCustomScraper()) {
                    // ── Source avec classe PHP custom → ScraperRegistry ───────
                    $scraper = $this->scraperRegistry->getBySlug((string) $source->getScraperSlug());

                    if ($scraper === null) {
                        // Slug renseigné mais classe introuvable.
                        // DÉCISION Q1 : erreur visible en admin, pas d'exception silencieuse.
                        $msg = sprintf(
                            'Slug "%s" inconnu dans ScraperRegistry. Slugs connus : %s',
                            $source->getScraperSlug(),
                            implode(', ', $this->scraperRegistry->getKnownSlugs())
                        );
                        $io->error($msg);

                        // On marque l'erreur en BDD (visible dans la liste admin).
                        // markRunError() incrémente aussi consecutiveFailures (factorisation santé).
                        if (!$isDryRun) {
                            $source->markRunError($msg);

                            // ── Auto-désactivation à 5 échecs consécutifs ────────
                            // Si la source a atteint le seuil (5 échecs d'affilée), on la
                            // désactive automatiquement pour ne pas polluer les logs et ne
                            // pas relancer en boucle un slug qui n'existe pas.
                            // NIVEAU WARNING (pas error/critical) : comportement normal du
                            // cycle de vie — l'admin peut corriger le slug et réactiver.
                            if ($source->hasReachedFailureThreshold()) {
                                $source->setActif(false);
                                $this->logger->warning(
                                    sprintf(
                                        '[scrape] Source désactivée après %d échecs consécutifs : %s',
                                        $source->getConsecutiveFailures(),
                                        $source->getNom()
                                    ),
                                    [
                                        'source'              => $source->getNom(),
                                        'consecutiveFailures' => $source->getConsecutiveFailures(),
                                        'lastError'           => $msg,
                                    ]
                                );
                                $io->warning(sprintf(
                                    'Source désactivée automatiquement après %d échecs consécutifs. '
                                    . 'Réactivez-la depuis /admin/scraping-sources après correction.',
                                    $source->getConsecutiveFailures()
                                ));
                            }

                            $this->em->flush();
                        }
                        continue;
                    }

                    // Mode debug : affiche les infos de fetch avant scraping
                    if ($isDebug) {
                        $info = $scraper->getDebugInfo($scraper->getTestUrl());
                        $io->definitionList(
                            ['URL testée'  => $info['url']],
                            ['Status HTTP' => $info['status_code'] ?: ('ERREUR: ' . $info['error'])],
                            ['Taille HTML' => $info['html_length'] . ' octets'],
                        );
                    }

                    $sourceOpportunities = $scraper->scrape();

                } else {
                    // ── Source sans slug → GenericScraper ────────────────────
                    // GenericScraper dispatche selon le type : RSS ou HTML_LLM.
                    // HTML_CSS sans slug est impossible → retourne [].
                    $sourceOpportunities = $this->genericScraper->scrapeSource($source);
                }

                $count = count($sourceOpportunities);

                if ($count === 0) {
                    $io->warning('Aucune opportunité trouvée (site inaccessible ou aucun appel en cours).');
                } else {
                    $io->success(sprintf('%d opportunité(s) trouvée(s)', $count));
                }

                // Mise à jour des stats de la source dans la BDD
                // markRunSuccess() enregistre la date, le nombre d'items, et vide le message d'erreur
                if (!$isDryRun) {
                    $source->markRunSuccess($count);
                    $this->em->flush();
                }

                $allOpportunities = array_merge($allOpportunities, $sourceOpportunities);

            } catch (\Exception $e) {
                $io->error('Erreur : ' . $e->getMessage());

                // Enregistrement de l'erreur en BDD — visible dans la liste admin.
                // markRunError() incrémente aussi consecutiveFailures (factorisation santé).
                // PHPStan narrow incorrectement $isDryRun à false dans ce catch (faux positif
                // dû au "continue" conditionnel dans le try). La vérification est nécessaire.
                if (!$isDryRun) { // @phpstan-ignore booleanNot.alwaysTrue
                    $source->markRunError($e->getMessage());

                    // ── Auto-désactivation à 5 échecs consécutifs ────────────────
                    // Même logique que le chemin "slug inconnu" ci-dessus.
                    // Après 5 exceptions consécutives (ex: timeout réseau répété, bug
                    // dans le scraper custom), la source est mise hors service automatiquement.
                    if ($source->hasReachedFailureThreshold()) {
                        $source->setActif(false);
                        $this->logger->warning(
                            sprintf(
                                '[scrape] Source désactivée après %d échecs consécutifs : %s',
                                $source->getConsecutiveFailures(),
                                $source->getNom()
                            ),
                            [
                                'source'              => $source->getNom(),
                                'consecutiveFailures' => $source->getConsecutiveFailures(),
                                'lastError'           => $e->getMessage(),
                            ]
                        );
                        $io->warning(sprintf(
                            'Source désactivée automatiquement après %d échecs consécutifs. '
                            . 'Réactivez-la depuis /admin/scraping-sources après correction.',
                            $source->getConsecutiveFailures()
                        ));
                    }

                    $this->em->flush();
                }
            }
        }

        // ── Résumé terminal ──────────────────────────────────────────────────
        $io->section('Résumé');
        $io->text(sprintf('<info>Total collecté : %d opportunité(s)</info>', count($allOpportunities)));

        if (!empty($allOpportunities)) {
            // Aperçu des 10 premières dans le terminal
            $previewRows = [];
            foreach (array_slice($allOpportunities, 0, 10) as $opp) {
                $score        = $opp->relevanceScore;
                $previewRows[] = [
                    mb_substr($opp->title, 0, 50) . (mb_strlen($opp->title) > 50 ? '...' : ''),
                    $opp->type,
                    $opp->source,
                    $opp->deadline ?: '-',
                    str_repeat('★', $score) . str_repeat('☆', 5 - $score),
                ];
            }

            $io->table(['Titre', 'Type', 'Source', 'Deadline', 'Score Afro'], $previewRows);

            if (count($allOpportunities) > 10) {
                $io->note(sprintf('... et %d autre(s). Voir /admin/scraped-opportunities.', count($allOpportunities) - 10));
            }
        }

        // ── Sauvegarde en base de données ────────────────────────────────────
        // La logique de déduplication (5 cas) et de persistance est déléguée à
        // ScrapedResourcePersister::persistBatch(). Le comportement est strictement
        // identique à l'ancienne boucle inline — seul l'emplacement du code change.
        //
        // Pourquoi déléguer ici et pas inline ?
        //   FeedReaderService (WS2) produit des ScrapedOpportunity[] exactement comme
        //   ce pipeline. Un seul point de vérité évite de dupliquer 80 lignes de logique
        //   délicate (guard intra-lot, 5 cas de dédup, flush unique).
        if (!$isDryRun && !empty($allOpportunities)) {
            $io->section('Sauvegarde en base de données');

            // persistBatch() gère : guard intra-lot, findByUrl(), les 5 cas, flush()
            $result = $this->persister->persistBatch($allOpportunities);

            $io->success(sprintf(
                '%d nouvelle(s) | %d réactivée(s) (archives) | %d mise(s) à jour | %d ignorée(s) (déjà validée par admin)',
                $result->inserted,
                $result->reactivated,
                $result->updated,
                $result->skipped,
            ));
        }

        // ── Archivage automatique des opportunités expirées ──────────────────
        // Tente de parser le champ deadline (texte libre) de chaque opportunité pending.
        // Si la date est clairement passée, le statut passe à 'archived'.
        // Non exécuté en mode --dry-run.
        if (!$isDryRun) {
            // Feature flag archive_use_legacy : permet de rebrancher l'ancienne méthode
            // string-parsing en cas de comportement inattendu de la nouvelle version DQL.
            // Activer depuis /admin/settings (archive_use_legacy = 1).
            // La nouvelle archiveExpired() DQL requiert que deadlineDate soit rempli
            // (via app:backfill-deadline-date après migration).
            if ((bool) $this->settingService->get('archive_use_legacy', '0')) {
                $archived = $this->scrapedResourceRepository->archiveExpiredLegacy();
            } else {
                $archived = $this->scrapedResourceRepository->archiveExpired();
            }

            if ($archived > 0) {
                $io->note(sprintf('%d opportunité(s) archivée(s) automatiquement (deadline passée).', $archived));
            }
        }

        $io->success('Scraping terminé !');
        return Command::SUCCESS;
    }
}

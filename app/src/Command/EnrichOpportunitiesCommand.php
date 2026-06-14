<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Resource;
use App\Entity\ScrapedResource;
use App\Repository\ResourceRepository;
use App\Repository\ScrapedResourceRepository;
use App\Service\OpportunityEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * EnrichOpportunitiesCommand — Enrichit les opportunités sans description via Mistral.
 *
 * PROBLÈME RÉSOLU :
 *   Beaucoup d'opportunités scrapées (notamment depuis On The Move) n'ont pas de
 *   description car le scraper ne lit que la page-liste (titres seulement).
 *   Cette commande va chercher la PAGE INDIVIDUELLE de chaque opportunité,
 *   demande à Mistral de produire un titre clair + une description fidèle en français.
 *
 * FLUX DE TRAITEMENT :
 *   1. Récupère les ScrapedResource sans description (ou les Resource publiées avec --published)
 *   2. Pour chacune : appelle OpportunityEnrichmentService::enrich($url)
 *   3. Si la description est non vide → met à jour la BDD
 *   4. Si le titre est non vide → remplace le titre existant (sauf si pas de description)
 *   5. Flush par lots de BATCH_SIZE pour optimiser les transactions
 *   6. Affiche un tableau récapitulatif à la fin
 *
 * OPTIONS :
 *   --dry-run   : affiche ce qui serait modifié, n'écrit RIEN en BDD
 *   --limit=N   : nombre max d'opportunités traitées (défaut 20 — maîtrise du coût LLM)
 *   --source=X  : ne traiter que les opportunités d'une source donnée (ex: on-the-move.org)
 *   --force     : retraite même celles qui ont déjà une description
 *   --published : cible les Resource PUBLIÉES sans description (backlog post-validation)
 *                 au lieu des ScrapedResource
 *
 * UTILISATION :
 *   # Enrichir 20 ScrapedResource sans description (défaut)
 *   docker compose exec app php bin/console app:enrich-opportunities
 *
 *   # Enrichir uniquement On The Move (source problématique), 50 à la fois
 *   docker compose exec app php bin/console app:enrich-opportunities --source=on-the-move.org --limit=50
 *
 *   # Voir ce qui serait fait sans écrire (dry run)
 *   docker compose exec app php bin/console app:enrich-opportunities --dry-run --limit=10
 *
 *   # Rattraper le backlog des Resource publiées sans description
 *   docker compose exec app php bin/console app:enrich-opportunities --published --limit=50
 *
 *   # Re-traiter même celles qui ont déjà une description (amélioration)
 *   docker compose exec app php bin/console app:enrich-opportunities --force --limit=10
 *
 * IDEMPOTENCE :
 *   La commande est idempotente. Sans --force, elle ne traite que les entrées sans
 *   description — si elle est re-lancée après succès, elle ne fait rien.
 *
 * COÛT LLM :
 *   Chaque enrichissement = 1 appel Mistral (~0,001 € sur mistral-small-latest).
 *   Le --limit=20 par défaut coûte donc ~0,02 € par run.
 *   Adapter --limit selon le budget disponible.
 */
#[AsCommand(
    name: 'app:enrich-opportunities',
    description: 'Enrichit les opportunités sans description via Mistral (fetch page + résumé IA en français)',
)]
class EnrichOpportunitiesCommand extends Command
{
    /**
     * Taille du lot pour les flush Doctrine.
     * On écrit en BDD tous les BATCH_SIZE éléments traités pour éviter d'accumuler
     * trop d'entités en mémoire tout en limitant le nombre de transactions.
     */
    private const BATCH_SIZE = 10;

    public function __construct(
        // Service d'enrichissement IA — cœur de la commande
        private readonly OpportunityEnrichmentService $enrichmentService,

        // Repositories — pour récupérer les entités à enrichir
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
        private readonly ResourceRepository $resourceRepository,

        // EntityManager — pour persister les modifications (flush par lots)
        private readonly EntityManagerInterface $em,
    ) {
        // Symfony requiert l'appel au constructeur parent pour les commandes
        parent::__construct();
    }

    /**
     * Configure les options de la commande.
     *
     * Convention Symfony : configure() définit les options AVANT execute().
     * Les options sont déclarées avec addOption() : nom, alias, mode, description, défaut.
     */
    protected function configure(): void
    {
        $this
            ->setHelp(
                'Enrichit les opportunités scrapées sans description en allant lire leur page '
                . 'd\'origine et en demandant à Mistral de produire titre + description en français.' . "\n\n"
                . 'Options utiles :' . "\n"
                . '  --dry-run   : simule sans écrire' . "\n"
                . '  --limit=N   : max N opportunités (défaut 20)' . "\n"
                . '  --source=X  : source spécifique (ex: on-the-move.org)' . "\n"
                . '  --force     : retraite même celles avec une description' . "\n"
                . '  --published : cible les Resource publiées (backlog post-validation)'
            )
            // ── Option --dry-run ──────────────────────────────────────────────
            // Affiche ce qui serait fait sans écrire en BDD.
            // Utile pour vérifier que la commande cible les bonnes entrées avant de s'engager.
            ->addOption(
                name: 'dry-run',
                mode: InputOption::VALUE_NONE,
                description: 'Simule l\'enrichissement sans écrire en BDD (mode lecture seule)',
            )
            // ── Option --limit ────────────────────────────────────────────────
            // Nombre max d'opportunités traitées. Défaut 20 pour maîtriser le coût LLM.
            // En production avec budget, utiliser --limit=100 ou plus.
            ->addOption(
                name: 'limit',
                shortcut: 'l',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Nombre maximum d\'opportunités à traiter (défaut : 20)',
                default: '20',
            )
            // ── Option --source ───────────────────────────────────────────────
            // Filtre sur le nom du site source. Utile pour rattraper une source spécifique
            // (ex: On The Move qui n'a jamais de description dans ses listes).
            ->addOption(
                name: 'source',
                shortcut: 's',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Filtrer par source (ex: on-the-move.org) — s\'applique aux ScrapedResource seulement',
            )
            // ── Option --force ────────────────────────────────────────────────
            // Par défaut, la commande ignore les entrées qui ont déjà une description.
            // Avec --force, on les retraite pour améliorer des descriptions existantes.
            ->addOption(
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Retraiter même les opportunités qui ont déjà une description',
            )
            // ── Option --published ────────────────────────────────────────────
            // Mode alternatif : cible les Resource publiées plutôt que les ScrapedResource.
            // Permet de rattraper le backlog des opportunités validées avant l'enrichissement.
            ->addOption(
                name: 'published',
                shortcut: 'p',
                mode: InputOption::VALUE_NONE,
                description: 'Cibler les Resource PUBLIÉES sans description (backlog post-validation)',
            );
    }

    /**
     * Point d'entrée de la commande.
     *
     * STRUCTURE :
     *   1. Lecture et validation des options
     *   2. Chargement des entités à enrichir
     *   3. Boucle d'enrichissement (avec gestion par lots)
     *   4. Affichage du tableau récapitulatif
     *
     * GESTION DES ERREURS :
     *   Chaque échec par item est capturé et compté dans $stats['failed'].
     *   La commande continue toujours sur l'item suivant.
     *   OpportunityEnrichmentService ne lève jamais d'exception — les erreurs
     *   sont déjà loggées et retournent un DTO vide.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle : helpers pour l'affichage console (titre, table, success, error…)
        $io = new SymfonyStyle($input, $output);

        // ── Lecture des options ────────────────────────────────────────────────
        $isDryRun    = (bool)  $input->getOption('dry-run');
        $isForce     = (bool)  $input->getOption('force');
        $isPublished = (bool)  $input->getOption('published');
        $limit       = (int)   ($input->getOption('limit') ?? 20);
        $source      = (string)($input->getOption('source') ?? '');
        // On normalise la source vide en null pour les repositories
        $sourceFilter = $source !== '' ? $source : null;

        // ── Titre de la commande ───────────────────────────────────────────────
        $io->title('Enrichissement IA des opportunités (Mistral)');

        if ($isDryRun) {
            $io->note('Mode DRY-RUN activé — aucune écriture en BDD.');
        }

        // ── Chargement et traitement selon le mode ────────────────────────────
        // Les deux modes sont traités dans des méthodes séparées pour avoir des types
        // stricts (PHPStan niveau 6 ne tolère pas le mélange Resource|ScrapedResource
        // dans une même boucle sans interface commune).
        if ($isPublished) {
            $io->section('Mode : Resource publiées sans description (backlog post-validation)');
            $items = $this->resourceRepository->findPublishedWithoutDescription($limit, $isForce);
            $stats = $this->processResources($items, $io, $isDryRun);
        } else {
            $mode = $sourceFilter !== null
                ? sprintf('ScrapedResource — source : %s', $sourceFilter)
                : 'ScrapedResource — toutes les sources';
            $io->section(sprintf('Mode : %s', $mode));
            $items = $this->scrapedResourceRepository->findForEnrichment(
                limit: $limit,
                sourceSite: $sourceFilter,
                includeWithDescription: $isForce,
            );
            $stats = $this->processScrapedResources($items, $io, $isDryRun);
        }

        $total = count($items);

        // ── Flush final des éléments restants (dernier lot incomplet) ─────────
        // Ex: 25 éléments avec BATCH_SIZE=10 → le 3ème lot (5 items) n'a pas encore
        // été flushé à la sortie de la boucle.
        if (!$isDryRun && $stats['enriched'] > 0) {
            $this->em->flush();
        }

        // ── Tableau récapitulatif ─────────────────────────────────────────────
        $io->newLine();
        $io->table(
            headers: ['Métrique', 'Valeur'],
            rows: [
                ['Opportunités chargées',  $total],
                ['Enrichies',              $stats['enriched']],
                ['Ignorées (DTO vide)',    $stats['skipped']],
                ['Échouées (exception)',   $stats['failed']],
                ['Mode',                   $isDryRun ? 'DRY-RUN (rien écrit)' : 'ÉCRITURE'],
            ]
        );

        if ($stats['enriched'] > 0) {
            $message = $isDryRun
                ? sprintf('%d opportunité(s) seraient enrichies (dry-run).', $stats['enriched'])
                : sprintf('%d opportunité(s) enrichies avec succès.', $stats['enriched']);
            $io->success($message);
        } else {
            $io->warning('Aucun enrichissement effectué. Vérifiez les logs pour le détail des erreurs.');
        }

        return Command::SUCCESS;
    }

    /**
     * Traite un lot de ScrapedResource (mode par défaut, sans --published).
     *
     * Méthode séparée pour avoir des types stricts (ScrapedResource[]) et
     * satisfaire PHPStan niveau 6 sans mélanger Resource|ScrapedResource dans
     * un foreach unique.
     *
     * @param ScrapedResource[]  $items    Entités à enrichir
     * @param SymfonyStyle       $io       Interface console
     * @param bool               $isDryRun Ne pas écrire en BDD si true
     * @return array{processed: int, enriched: int, skipped: int, failed: int}
     */
    private function processScrapedResources(array $items, SymfonyStyle $io, bool $isDryRun): array
    {
        $io->text(sprintf('→ %d ScrapedResource(s) chargée(s) depuis la BDD.', count($items)));

        // ── Compteurs pour le tableau récapitulatif ────────────────────────────
        $stats = ['processed' => 0, 'enriched' => 0, 'skipped' => 0, 'failed' => 0];
        $batchCount = 0;

        foreach ($items as $item) {
            $stats['processed']++;

            $itemUrl    = $item->getUrl();
            $itemTitle  = $item->getTitle();
            $itemSource = $item->getSourceSite() ?? 'inconnu';

            $io->write(sprintf(
                '  [%d/%d] %s : %s… ',
                $stats['processed'],
                count($items),
                $itemSource,
                mb_substr($itemTitle, 0, 60)
            ));

            // Validation : URL requise pour fetcher la page
            if (empty($itemUrl)) {
                $io->writeln('<comment>IGNORÉ</comment> (pas d\'URL)');
                $stats['skipped']++;
                continue;
            }

            try {
                $enrichment = $this->enrichmentService->enrich($itemUrl);

                if ($enrichment->isEmpty()) {
                    $io->writeln('<comment>IGNORÉ</comment> (enrichissement vide)');
                    $stats['skipped']++;
                    continue;
                }

                if ($isDryRun) {
                    $this->displayDryRunResult($io, $itemTitle, $enrichment->title, $enrichment->description);
                    $stats['enriched']++;
                    continue;
                }

                // ── Mise à jour de la description ──────────────────────────────
                if ($enrichment->description !== null && $enrichment->description !== '') {
                    $item->setDescription($enrichment->description);
                }

                // ── Mise à jour du titre (seulement si description disponible) ─
                // On ne remplace le titre que si l'enrichissement est complet (titre + description).
                if ($enrichment->title !== null && $enrichment->title !== '' && $enrichment->description !== null) {
                    $item->setTitle($enrichment->title);
                }

                $io->writeln('<info>ENRICHI</info>');
                $stats['enriched']++;

                // Flush par lots (économise les transactions BDD)
                $batchCount++;
                if ($batchCount % self::BATCH_SIZE === 0) {
                    $this->em->flush();
                    gc_collect_cycles();
                }

            } catch (\Exception $e) {
                $io->writeln(sprintf('<error>ERREUR : %s</error>', $e->getMessage()));
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Traite un lot de Resource publiées (mode --published).
     *
     * Méthode séparée pour avoir des types stricts (Resource[]) et
     * satisfaire PHPStan niveau 6.
     *
     * @param Resource[]   $items    Entités à enrichir
     * @param SymfonyStyle $io       Interface console
     * @param bool         $isDryRun Ne pas écrire en BDD si true
     * @return array{processed: int, enriched: int, skipped: int, failed: int}
     */
    private function processResources(array $items, SymfonyStyle $io, bool $isDryRun): array
    {
        $io->text(sprintf('→ %d Resource(s) publiée(s) chargée(s) depuis la BDD.', count($items)));

        $stats = ['processed' => 0, 'enriched' => 0, 'skipped' => 0, 'failed' => 0];
        $batchCount = 0;

        foreach ($items as $item) {
            $stats['processed']++;

            $itemUrl   = $item->getExternalUrl();
            $itemTitle = $item->getTitle();

            $io->write(sprintf(
                '  [%d/%d] resource#%d : %s… ',
                $stats['processed'],
                count($items),
                (int) $item->getId(),
                mb_substr($itemTitle, 0, 60)
            ));

            if (empty($itemUrl)) {
                $io->writeln('<comment>IGNORÉ</comment> (pas d\'URL externe)');
                $stats['skipped']++;
                continue;
            }

            try {
                $enrichment = $this->enrichmentService->enrich($itemUrl);

                if ($enrichment->isEmpty()) {
                    $io->writeln('<comment>IGNORÉ</comment> (enrichissement vide)');
                    $stats['skipped']++;
                    continue;
                }

                if ($isDryRun) {
                    $this->displayDryRunResult($io, $itemTitle, $enrichment->title, $enrichment->description);
                    $stats['enriched']++;
                    continue;
                }

                // Resource::description est non nullable (string obligatoire)
                if ($enrichment->description !== null && $enrichment->description !== '') {
                    $item->setDescription($enrichment->description);
                }

                if ($enrichment->title !== null && $enrichment->title !== '' && $enrichment->description !== null) {
                    $item->setTitle($enrichment->title);
                }

                $io->writeln('<info>ENRICHI</info>');
                $stats['enriched']++;

                $batchCount++;
                if ($batchCount % self::BATCH_SIZE === 0) {
                    $this->em->flush();
                    gc_collect_cycles();
                }

            } catch (\Exception $e) {
                $io->writeln(sprintf('<error>ERREUR : %s</error>', $e->getMessage()));
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Affiche le résultat simulé en mode --dry-run.
     *
     * Factorisée ici pour éviter la duplication entre processScrapedResources() et processResources().
     *
     * @param SymfonyStyle $io             Interface console
     * @param string       $originalTitle  Titre actuel en BDD (avant enrichissement)
     * @param string|null  $newTitle       Titre proposé par Mistral (peut être null)
     * @param string|null  $newDescription Description produite par Mistral (peut être null)
     */
    private function displayDryRunResult(
        SymfonyStyle $io,
        string $originalTitle,
        ?string $newTitle,
        ?string $newDescription,
    ): void {
        $io->writeln('<info>OK (dry-run)</info>');

        if ($newTitle !== null) {
            $io->writeln(sprintf(
                '    Titre : <fg=gray>%s</> → <info>%s</>',
                mb_substr($originalTitle, 0, 50),
                mb_substr($newTitle, 0, 50)
            ));
        }

        if ($newDescription !== null) {
            $io->writeln(sprintf(
                '    Description : <info>%s</>',
                mb_substr($newDescription, 0, 100) . '…'
            ));
        }
    }

}


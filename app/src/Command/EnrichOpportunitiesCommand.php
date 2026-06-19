<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Resource;
use App\Entity\ScrapedResource;
use App\Enum\ExperienceLevel;
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
 * EnrichOpportunitiesCommand — Enrichit les opportunités jamais enrichies via Mistral.
 *
 * PROBLÈME RÉSOLU :
 *   Beaucoup d'opportunités scrapées (notamment depuis On The Move) n'ont pas de
 *   description car le scraper ne lit que la page-liste (titres seulement).
 *   Cette commande va chercher la PAGE INDIVIDUELLE de chaque opportunité,
 *   demande à Mistral de produire un titre clair + une description fidèle en français.
 *
 * MARQUEUR enrichedAt (NOUVEAU) :
 *   Après un enrichissement RÉUSSI, la commande positionne enrichedAt = now() sur l'entité.
 *   Ce marqueur permet de n'enrichir chaque item QU'UNE SEULE FOIS automatiquement :
 *     - enrichedAt IS NULL  → jamais enrichi → sélectionné par le cron
 *     - enrichedAt non NULL → déjà enrichi → EXCLU de la sélection automatique
 *   Avantages :
 *     1. Pas de ré-enrichissement coûteux chaque nuit (coût LLM maîtrisé)
 *     2. Pas de drift LLM (les bonnes descriptions ne sont pas écrasées aléatoirement)
 *     3. Les éditions admin sont préservées (enrichedAt déjà non null → non sélectionné)
 *     4. Items en échec LLM (DTO vide) : enrichedAt reste NULL → re-tentés au prochain run
 *        (acceptable : mieux que d'enregistrer un enrichissement "vide")
 *
 * FLUX DE TRAITEMENT :
 *   1. Récupère les ScrapedResource avec enrichedAt IS NULL (ou les Resource publiées avec --published)
 *   2. Pour chacune : appelle OpportunityEnrichmentService::enrich($url)
 *   3. Si le DTO est non vide → met à jour les champs ET positionne enrichedAt = now()
 *   4. Si le DTO est vide (LLM a échoué) → on ne positionne PAS enrichedAt (re-tenté plus tard)
 *   5. Flush par lots de BATCH_SIZE pour optimiser les transactions
 *   6. Affiche un tableau récapitulatif à la fin
 *
 * OPTIONS :
 *   --dry-run   : affiche ce qui serait modifié, n'écrit RIEN en BDD
 *   --limit=N   : nombre max d'opportunités traitées (défaut 20 — maîtrise du coût LLM)
 *   --source=X  : ne traiter que les opportunités d'une source donnée (ex: on-the-move.org)
 *   --force     : retraite même celles qui ont enrichedAt non null (usage manuel uniquement)
 *   --published : cible les Resource PUBLIÉES avec enrichedAt IS NULL
 *                 au lieu des ScrapedResource
 *
 * UTILISATION :
 *   # Enrichir les nouvelles ScrapedResource jamais enrichies (usage cron)
 *   docker compose exec app php bin/console app:enrich-opportunities
 *
 *   # Enrichir uniquement On The Move (source problématique), 50 à la fois
 *   docker compose exec app php bin/console app:enrich-opportunities --source=on-the-move.org --limit=50
 *
 *   # Voir ce qui serait fait sans écrire (dry run)
 *   docker compose exec app php bin/console app:enrich-opportunities --dry-run --limit=10
 *
 *   # Rattraper le backlog des Resource publiées jamais enrichies
 *   docker compose exec app php bin/console app:enrich-opportunities --published --limit=50
 *
 *   # Re-traiter même celles déjà enrichies (amélioration globale, coûteux)
 *   docker compose exec app php bin/console app:enrich-opportunities --force --limit=10
 *
 * IDEMPOTENCE :
 *   La commande est idempotente. Sans --force, elle ne traite que les entrées avec
 *   enrichedAt IS NULL. Si relancée après un run complet, elle ne fait rien (0 items).
 *
 * COÛT LLM :
 *   Chaque enrichissement = 1 appel Mistral (~0,001 € sur mistral-small-latest).
 *   Le --limit=20 par défaut coûte donc ~0,02 € par run.
 *   Adapter --limit selon le budget disponible.
 */
#[AsCommand(
    name: 'app:enrich-opportunities',
    description: 'Enrichit via Mistral les opportunités avec enrichedAt IS NULL (une seule fois par item, sauf --force)',
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
                'Enrichit les opportunités dont enrichedAt IS NULL (jamais enrichies) en allant lire leur page '
                . 'd\'origine et en demandant à Mistral de produire titre + description en français.' . "\n\n"
                . 'Après un enrichissement réussi, enrichedAt est positionné à NOW() → l\'item ne sera'
                . ' plus sélectionné automatiquement (sauf --force). Les items pour lesquels le LLM'
                . ' retourne un DTO vide conservent enrichedAt = NULL et seront re-tentés au prochain run.' . "\n\n"
                . 'Options utiles :' . "\n"
                . '  --dry-run   : simule sans écrire' . "\n"
                . '  --limit=N   : max N opportunités (défaut 20)' . "\n"
                . '  --source=X  : source spécifique (ex: on-the-move.org)' . "\n"
                . '  --force     : retraite même celles avec enrichedAt non null (coûteux)' . "\n"
                . '  --published : cible les Resource publiées avec enrichedAt IS NULL'
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
            // Par défaut, la commande ignore les entrées dont enrichedAt est non null.
            // Avec --force, on ignore ce filtre et on retraite tous les items actifs.
            // Usage : amélioration globale après évolution du prompt, reset forcé.
            // ⚠️  Coûteux : peut re-traiter des centaines d'items. Toujours combiner avec --limit.
            ->addOption(
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Retraiter même les opportunités déjà enrichies (enrichedAt non null) — coûteux, combiner avec --limit',
            )
            // ── Option --published ────────────────────────────────────────────
            // Mode alternatif : cible les Resource publiées (enrichedAt IS NULL) plutôt que
            // les ScrapedResource. Permet de rattraper le backlog des opportunités validées
            // avant l'implémentation de l'enrichissement.
            ->addOption(
                name: 'published',
                shortcut: 'p',
                mode: InputOption::VALUE_NONE,
                description: 'Cibler les Resource PUBLIÉES avec enrichedAt IS NULL (backlog post-validation)',
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
            $stats = $this->processScrapedResources($items, $io, $isDryRun, $isForce);
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
     * Le parametre $isForce permet de ne pas ecraser une description existante
     * sauf si l'utilisateur a explicitement demande --force. Les disciplines,
     * elles, sont toujours mises a jour quand disponibles (raison d'etre principale
     * de cette passe d'enrichissement pour les records déjà décrits).
     *
     * @param ScrapedResource[]  $items    Entités à enrichir
     * @param SymfonyStyle       $io       Interface console
     * @param bool               $isDryRun Ne pas écrire en BDD si true
     * @param bool               $isForce  Ecraser description+titre existants si true
     * @return array{processed: int, enriched: int, skipped: int, failed: int}
     */
    private function processScrapedResources(array $items, SymfonyStyle $io, bool $isDryRun, bool $isForce = false): array
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
                    $this->displayDryRunResult(
                        $io,
                        $itemTitle,
                        $enrichment->title,
                        $enrichment->description,
                        $enrichment->disciplines,
                    );
                    $stats['enriched']++;
                    continue;
                }

                // Capture de l'état AVANT enrichissement pour decider quoi mettre a jour.
                // Une description de placeholder ("Description non disponible.") est traitee
                // comme absente : on l'ecrase sans avoir besoin de --force.
                $hadDescription = !empty($item->getDescription())
                    && $item->getDescription() !== 'Description non disponible.';

                // ── Mise à jour de la description ──────────────────────────────
                // On n'ecrase PAS une description existante sauf si --force.
                // Un scraper a peut-etre fourni une description acceptable ; l'enrichissement
                // l'ameliorerait, mais le risque de perte d'info n'en vaut pas la peine
                // sans --force explicite.
                if ($enrichment->description !== null && $enrichment->description !== '') {
                    if (!$hadDescription || $isForce) {
                        $item->setDescription($enrichment->description);
                    }
                }

                // ── ADR-0020 : mise à jour du titre optimisé ───────────────────
                // Le titre optimisé est appliqué SYSTÉMATIQUEMENT si le LLM en fournit un.
                // Garde-fou : on n'écrase JAMAIS par une valeur vide.
                //
                // Différence par rapport à l'ancien comportement :
                //   AVANT (ADR-0018) : le titre n'était remplacé que si !$hadDescription || $isForce
                //     (on évitait de toucher au titre si la description existait déjà).
                //   MAINTENANT (ADR-0020) : le titre est TOUJOURS remplacé par la version
                //     optimisée (française, concise, factuelle) — indépendamment de la description.
                //   RAISON : les titres bruts sont souvent en anglais ou très longs. L'objectif
                //     de l'ADR-0020 est précisément de corriger le stock existant.
                //   SÉCURITÉ : le garde-fou "valeur non vide" protège contre un écrasement
                //     accidentel par une chaîne vide ou null du LLM.
                if ($enrichment->title !== null && $enrichment->title !== '') {
                    $item->setTitle($enrichment->title);
                }

                // ── Mise à jour des disciplines ─────────────────────────────────
                // Les disciplines sont TOUJOURS mises a jour quand disponibles, meme si
                // le record avait déjà une description. C'est la raison principale de cette
                // passe d'enrichissement pour les records deja partiellement enrichis.
                if ($enrichment->disciplines !== null && $enrichment->disciplines !== '') {
                    $item->setDisciplines($enrichment->disciplines);
                }

                // ── ADR-0018 : mise à jour des champs candidature + financement ──
                // Ces champs sont TOUJOURS mis à jour si le LLM les fournit.
                // Même logique que les disciplines : on les enrich même sur un record
                // qui avait déjà une description (ils sont indépendants).
                // On n'écrase pas une valeur existante par null — on ne met à jour
                // que si la nouvelle valeur est renseignée.
                if ($enrichment->howToApply !== null && $enrichment->howToApply !== '') {
                    $item->setHowToApply($enrichment->howToApply);
                }
                if ($enrichment->fundingAmount !== null && $enrichment->fundingAmount !== '') {
                    $item->setFundingAmount($enrichment->fundingAmount);
                }
                if ($enrichment->fundingType !== null && $enrichment->fundingType !== '') {
                    $item->setFundingType($enrichment->fundingType);
                }

                // ── ADR-0019 : mise à jour du lien candidature + logo ──────────
                // applicationUrl : extrait par le LLM (garde anti-hallucination dans EnrichmentService).
                //   On ne met à jour que si non vide (pas d'écrasement d'une valeur existante).
                //   Troncature défensive à 500 chars (limite colonne BDD).
                if ($enrichment->applicationUrl !== null && $enrichment->applicationUrl !== '') {
                    $item->setApplicationUrl(mb_substr($enrichment->applicationUrl, 0, 500));
                }

                // logoUrl : récupéré par LogoFetcherService dans OpportunityEnrichmentService::enrich().
                //   Même règle : on met à jour seulement si non null/vide.
                //   Si null → pas de changement, le badge "B" sera affiché en front.
                if ($enrichment->logoUrl !== null && $enrichment->logoUrl !== '') {
                    $item->setLogoUrl(mb_substr($enrichment->logoUrl, 0, 500));
                }

                // ── Marqueur enrichedAt : CŒUR DU SYSTÈME "UN SEUL ENRICHISSEMENT" ──
                //
                // On positionne enrichedAt = maintenant UNIQUEMENT si on arrive ici,
                // c'est-à-dire si l'enrichissement a RÉUSSI (DTO non vide, pas d'exception).
                //
                // Conséquences de ce choix :
                //   - Item enrichi avec succès → enrichedAt non null → EXCLU des prochains runs
                //   - Item dont le LLM retourne un DTO vide → on a fait "continue" plus haut
                //     → enrichedAt reste NULL → re-tenté au prochain run (comportement souhaité)
                //   - Item en exception → on va dans le catch → enrichedAt reste NULL → re-tenté
                //
                // On n'utilise pas new \DateTime() mais new \DateTimeImmutable() pour la cohérence
                // avec Doctrine. La colonne est de type 'datetime' (pas 'datetime_immutable'),
                // mais Doctrine accepte les deux types PHP pour cette colonne.
                // On utilise \DateTime (mutable) et non \DateTimeImmutable car la colonne
                // enriched_at est mappée en type 'datetime' Doctrine, qui attend un \DateTime.
                // Doctrine lèverait une exception de conversion si on passait \DateTimeImmutable.
                $item->setEnrichedAt(new \DateTime());

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
     * NOTE IMPORTANTE -- DISCIPLINES NON MISES A JOUR EN MODE --published :
     *   Ce mode cible les Resource publiées (entité Resource, pas ScrapedResource).
     *   Or Resource::disciplines est une relation ManyToMany vers une entité Discipline
     *   (une Collection<Discipline>), pas un simple champ string comme sur ScrapedResource.
     *   Mettre a jour cette relation necessite de resoudre les entites Discipline par leur nom,
     *   ce qui est hors scope de ce service.
     *   La detection et la persistance des disciplines via Mistral est donc reservee
     *   au mode ScrapedResource (mode par defaut, sans --published).
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

                // ADR-0020 : titre optimisé appliqué SYSTÉMATIQUEMENT si non vide.
                // Même logique que processScrapedResources() : on ne restreint plus à
                // "seulement si description aussi enrichie". Le titre et la description
                // sont maintenant mis à jour indépendamment l'un de l'autre.
                // Garde-fou : on ne touche pas au titre si le LLM retourne null ou "".
                if ($enrichment->title !== null && $enrichment->title !== '') {
                    $item->setTitle($enrichment->title);
                }

                // ── ADR-0016 : propagation géographie + niveau d'expérience → Resource ──
                // Ces champs sont présents sur Resource (ADR-0016 Lot 1) mais n'étaient
                // pas mis à jour dans ce mode --published. Correction A4 : on les aligne
                // sur le comportement de processScrapedResources() (cohérence entre les deux modes).
                // Troncatures défensives identiques à celles de ScrapedResource :
                //   city          → 150 chars (limite colonne BDD)
                //   country       → 100 chars (limite colonne BDD)
                //   experienceLevel → valeur enum (beginner|intermediate|experienced)
                if ($enrichment->city !== null && $enrichment->city !== '') {
                    $item->setCity(mb_substr($enrichment->city, 0, 150));
                }
                if ($enrichment->country !== null && $enrichment->country !== '') {
                    $item->setCountry(mb_substr($enrichment->country, 0, 100));
                }
                // experienceLevel : le DTO stocke une string ("beginner"|"intermediate"|"experienced")
                // mais Resource::setExperienceLevel() attend un ExperienceLevel (enum PHP 8.1).
                // On convertit via ExperienceLevel::tryFrom() — tryFrom retourne null si la valeur
                // n'est pas un cas valide de l'enum (filet de sécurité supplémentaire).
                // On ne propage que si la conversion a réussi.
                if ($enrichment->experienceLevel !== null && $enrichment->experienceLevel !== '') {
                    $experienceLevelEnum = ExperienceLevel::tryFrom($enrichment->experienceLevel);
                    if ($experienceLevelEnum !== null) {
                        $item->setExperienceLevel($experienceLevelEnum);
                    }
                }

                // ── ADR-0018 : propagation candidature + financement → Resource ──
                // Pour les Resource publiées, on met aussi à jour les nouveaux champs.
                // Même règle : on n'écrase pas une valeur existante par null.
                if ($enrichment->howToApply !== null && $enrichment->howToApply !== '') {
                    $item->setHowToApply($enrichment->howToApply);
                }
                if ($enrichment->fundingAmount !== null && $enrichment->fundingAmount !== '') {
                    $item->setFundingAmount($enrichment->fundingAmount);
                }
                if ($enrichment->fundingType !== null && $enrichment->fundingType !== '') {
                    $item->setFundingType($enrichment->fundingType);
                }

                // ── ADR-0019 : propagation lien candidature + logo → Resource ──
                // Mêmes règles que pour ScrapedResource (première boucle ci-dessus).
                // On met à jour uniquement si non null/vide, avec troncature défensive.
                if ($enrichment->applicationUrl !== null && $enrichment->applicationUrl !== '') {
                    $item->setApplicationUrl(mb_substr($enrichment->applicationUrl, 0, 500));
                }
                if ($enrichment->logoUrl !== null && $enrichment->logoUrl !== '') {
                    $item->setLogoUrl(mb_substr($enrichment->logoUrl, 0, 500));
                }

                // ── Marqueur enrichedAt (mode --published) ────────────────────
                //
                // Même logique que dans processScrapedResources() :
                //   - On positionne enrichedAt = maintenant uniquement si on arrive ici
                //     (enrichissement réussi, DTO non vide, pas d'exception).
                //   - Les Resource dont le LLM retourne un DTO vide restent à NULL → re-tentées.
                //   - Les exceptions laissent aussi enrichedAt à NULL → re-tentées.
                //
                // En mode --published, la Resource n'a généralement jamais été enrichie
                // directement (elle a été créée via la vérification admin depuis une ScrapedResource).
                // C'est la première fois que le LLM traite cette Resource en tant que Resource.
                // On utilise \DateTime (mutable) et non \DateTimeImmutable car la colonne
                // enriched_at est mappée en type 'datetime' Doctrine, qui attend un \DateTime.
                // Doctrine lèverait une exception de conversion si on passait \DateTimeImmutable.
                $item->setEnrichedAt(new \DateTime());

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
     * @param SymfonyStyle $io              Interface console
     * @param string       $originalTitle   Titre actuel en BDD (avant enrichissement)
     * @param string|null  $newTitle        Titre proposé par Mistral (peut être null)
     * @param string|null  $newDescription  Description produite par Mistral (peut être null)
     * @param string|null  $newDisciplines  Disciplines détectées par Mistral (peut être null)
     */
    private function displayDryRunResult(
        SymfonyStyle $io,
        string $originalTitle,
        ?string $newTitle,
        ?string $newDescription,
        ?string $newDisciplines = null,
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
                mb_substr($newDescription, 0, 100) . '...'
            ));
        }

        if ($newDisciplines !== null) {
            $io->writeln(sprintf(
                '    Disciplines : <info>%s</>',
                $newDisciplines
            ));
        }
    }

}


<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ExperienceLevel;
use App\Repository\ScrapedResourceRepository;
use App\Service\GrantCsvImporter;
use App\Service\OpportunityEnrichmentService;
use App\Service\ScrapedResourcePersister;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ImportGrantCsvCommand — Importe un CSV d'opportunités (grants) en ScrapedResource.
 *
 * CONTEXTE :
 *   Gaëlle maintient un fichier CSV "Mise à jour fichier grant" avec des opportunités
 *   curatées manuellement (bourses, résidences, prix, appels à projets internationaux).
 *   Cette commande importe ce fichier en BDD comme ScrapedResource (status=pending),
 *   prêtes à être validées depuis l'interface admin /admin/scraped-opportunities.
 *
 * COLONNES DU CSV :
 *   - OPEN CALLS : titre de l'opportunité (obligatoire)
 *   - DEADLINE   : date limite au format MM/DD (ex: "02/28") — peut être vide
 *   - CATEGORY   : catégorie (GRANT, RESIDENCY, AWARD...) → mappée en libellé ResourceType
 *   - $          : (ignorée — indique si payant ou gratuit)
 *   - WHERE      : code pays ISO 2 lettres (ex: "FR") → converti en nom en clair
 *   - NOTES      : notes diverses → utilisées comme description courte
 *   - LINK       : lien vers l'opportunité (formats variés) → URL extraite
 *
 * USAGE :
 *   # Import complet
 *   php bin/console app:import-grant-csv /chemin/vers/fichier.csv
 *
 *   # Dry-run : voir ce qui serait importé sans rien écrire
 *   php bin/console app:import-grant-csv /chemin/vers/fichier.csv --dry-run
 *
 *   # Limiter à 5 lignes (test)
 *   php bin/console app:import-grant-csv /chemin/vers/fichier.csv --limit=5
 *
 *   # Import + enrichissement LLM des pages (fetch + Mistral)
 *   php bin/console app:import-grant-csv /chemin/vers/fichier.csv --enrich
 *
 *   # Rapport des ignorées seulement (dry-run complet)
 *   php bin/console app:import-grant-csv /chemin/vers/fichier.csv --dry-run --no-interaction
 *
 * IDEMPOTENCE :
 *   La commande est idempotente. Les URLs déjà en BDD sont déduplicées par
 *   ScrapedResourcePersister (même logique que le scraper). Relancer la commande
 *   sur le même CSV ne crée pas de doublons.
 *
 * ENRICHISSEMENT LLM (--enrich) :
 *   Si l'option --enrich est activée, la commande va fetcher la page de chaque
 *   opportunité importée et appeler OpportunityEnrichmentService (Mistral) pour
 *   compléter : description, city, country (si vide), disciplines.
 *   → On ne JAMAIS écrase title, deadline ou type (données CSV fiables).
 *   → Si une page est inaccessible ou le LLM répond vide → on garde l'import sec (try/catch).
 *   → Coût estimé : ~0,001 € par opportunité enrichie (mistral-small-latest).
 */
#[AsCommand(
    name: 'app:import-grant-csv',
    description: 'Importe un CSV de grants/opportunités en ScrapedResource (pending) avec déduplication par URL',
)]
class ImportGrantCsvCommand extends Command
{
    /**
     * Taille des lots pour les flush Doctrine pendant l'enrichissement.
     * On flush tous les BATCH_SIZE éléments pour éviter d'accumuler en mémoire.
     */
    private const ENRICH_BATCH_SIZE = 5;

    public function __construct(
        // Service de parsing CSV et de logique métier d'import
        private readonly GrantCsvImporter $csvImporter,

        // Service de persistance avec déduplication (réutilise la logique du scraper)
        private readonly ScrapedResourcePersister $persister,

        // Repository pour récupérer les ScrapedResource juste créées (mode --enrich)
        private readonly ScrapedResourceRepository $scrapedResourceRepository,

        // Service d'enrichissement LLM (Mistral) — utilisé si --enrich
        // Retourne maintenant city, country, experienceLevel, disciplinesLabels (ADR-0016 Lot 2 correctif)
        private readonly OpportunityEnrichmentService $enrichmentService,

        // EntityManager pour les flush lors de l'enrichissement par lots
        private readonly EntityManagerInterface $em,

        // Logger pour tracer les erreurs d'enrichissement
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /**
     * Définit l'argument et les options de la commande.
     *
     * Symfony convention : configure() déclare l'interface CLI avant execute().
     */
    protected function configure(): void
    {
        $this
            ->setHelp(
                'Importe les opportunités d\'un CSV "grants" en ScrapedResource (status=pending).' . "\n\n"
                . 'Colonnes attendues : OPEN CALLS, DEADLINE, CATEGORY, $, WHERE, NOTES, LINK' . "\n\n"
                // SG-3 : note sur le séparateur CSV attendu
                // Google Sheets exporte parfois avec ";" selon la locale système (FR par défaut).
                // Dans ce cas l'import échoue car TOUTES les colonnes se retrouvent dans la 1re.
                // Solution : dans Google Sheets → Fichier → Télécharger → CSV (.csv)
                // puis vérifier que le fichier utilise bien la virgule (ouvrir avec un éditeur texte).
                . '⚠️  SÉPARATEUR CSV : la commande attend la VIRGULE (,) comme séparateur.' . "\n"
                . '    Google Sheets exporte parfois avec le point-virgule (;) selon la locale.' . "\n"
                . '    Si l\'import ne trouve aucune colonne, vérifier le séparateur du fichier.' . "\n\n"
                . 'Options :' . "\n"
                . '  --dry-run   : affiche sans écrire en BDD' . "\n"
                . '  --limit=N   : traite uniquement les N premières lignes valides' . "\n"
                . '  --enrich    : enrichit via Mistral UNIQUEMENT les nouvelles entrées (pas les déjà connues)'
            )
            // ── Argument : chemin du CSV ──────────────────────────────────────
            // REQUIRED car sans fichier la commande ne peut rien faire.
            // On passe le chemin ABSOLU pour éviter les problèmes de répertoire courant.
            ->addArgument(
                name: 'csv-path',
                mode: InputArgument::REQUIRED,
                description: 'Chemin absolu vers le fichier CSV à importer',
            )
            // ── Option --dry-run ───────────────────────────────────────────────
            // Simule l'import sans écrire en BDD. Affiche le rapport complet.
            // Indispensable avant un vrai import pour vérifier le parsing.
            ->addOption(
                name: 'dry-run',
                mode: InputOption::VALUE_NONE,
                description: 'Simule l\'import sans écrire en BDD',
            )
            // ── Option --limit ─────────────────────────────────────────────────
            // Limite le nombre de lignes VALIDES traitées (les ignorées ne comptent pas).
            // Utile pour tester sur un petit échantillon avant l'import complet.
            ->addOption(
                name: 'limit',
                shortcut: 'l',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Nombre maximum de lignes valides à importer (ex: --limit=5)',
            )
            // ── Option --enrich ────────────────────────────────────────────────
            // Active l'enrichissement LLM post-import :
            //   - Fetch HTTP de chaque page importée
            //   - Appel Mistral pour extraire description, city, country, disciplines
            //   - On ne jamais écrase title/deadline/type (données CSV prioritaires)
            //   - Coût : ~0,001 € par opportunité (mistral-small-latest)
            ->addOption(
                name: 'enrich',
                mode: InputOption::VALUE_NONE,
                description: 'Enrichit via Mistral chaque opportunité importée (fetch page + résumé IA)',
            );
    }

    /**
     * Point d'entrée de la commande.
     *
     * Flux de traitement :
     *   1. Lecture et validation des options/arguments
     *   2. Parsing du CSV via GrantCsvImporter::parseFile()
     *   3. Affichage du rapport des lignes ignorées
     *   4. Si --dry-run : affichage seulement, pas de persistance
     *   5. Sinon : persistance via ScrapedResourcePersister::persistBatch()
     *   6. Si --enrich : enrichissement LLM des opportunités nouvellement importées
     *   7. Affichage du résumé final
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ── Lecture des options ────────────────────────────────────────────────
        $csvPath   = (string) $input->getArgument('csv-path');
        $isDryRun  = (bool)   $input->getOption('dry-run');
        $limitRaw  = $input->getOption('limit');
        $limit     = $limitRaw !== null ? (int) $limitRaw : null;
        $doEnrich  = (bool) $input->getOption('enrich');

        // ── Titre de la commande ───────────────────────────────────────────────
        $io->title('Import CSV de grants → ScrapedResource');
        $io->text(sprintf('<fg=cyan>Fichier : %s</>', $csvPath));

        if ($isDryRun) {
            $io->note('Mode DRY-RUN activé — aucune écriture en BDD.');
        }
        if ($limit !== null) {
            $io->note(sprintf('Limite : %d lignes valides maximum.', $limit));
        }
        if ($doEnrich && !$isDryRun) {
            $io->note('Enrichissement LLM activé (--enrich) — appels Mistral en cours après l\'import...');
        }
        if ($doEnrich && $isDryRun) {
            $io->note('--enrich ignoré en mode --dry-run (aucune ScrapedResource créée).');
        }

        // ── Parsing du CSV ─────────────────────────────────────────────────────
        $io->section('Étape 1 : Parsing du CSV');

        try {
            // parseFile() lit le CSV, extrait les URLs, mappe les catégories,
            // parse les deadlines et retourne les opportunités + le rapport des ignorées.
            $importResult = $this->csvImporter->parseFile(
                csvPath: $csvPath,
                limit: $limit,
                isDryRun: $isDryRun,
            );
        } catch (\InvalidArgumentException $e) {
            // Fichier introuvable ou non lisible — erreur fatale
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            // Erreur de lecture CSV (en-tête manquant, fichier vide...)
            $io->error('Erreur de lecture du CSV : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $countImportable = count($importResult->opportunities);
        $countIgnored    = count($importResult->ignoredLines);
        $countTotal      = $importResult->totalLines();

        $io->text(sprintf(
            'Résultat du parsing : <info>%d importables</info> / %d ignorées / %d lignes totales.',
            $countImportable,
            $countIgnored,
            $countTotal,
        ));

        // ── Rapport des lignes ignorées ────────────────────────────────────────
        if ($countIgnored > 0) {
            $io->section(sprintf('Étape 2 : Lignes ignorées (%d)', $countIgnored));

            // Affichage en tableau pour une lecture claire
            $tableRows = [];
            foreach ($importResult->ignoredLines as $ignored) {
                $tableRows[] = [
                    mb_substr($ignored['title'], 0, 50),
                    $ignored['reason'],
                ];
            }

            $io->table(
                headers: ['Titre (tronqué à 50 chars)', 'Raison de l\'ignorance'],
                rows: $tableRows,
            );
        } else {
            $io->text('Aucune ligne ignorée — parfait !');
        }

        // ── Mode DRY-RUN : s'arrêter ici ──────────────────────────────────────
        if ($isDryRun) {
            $io->section('Résumé (DRY-RUN — aucune écriture)');
            $io->table(
                headers: ['Métrique', 'Valeur'],
                rows: [
                    // SG-1 : "Lignes totales" était trompeur avec --limit (affichait le nb
                    // après limite, pas le total réel du fichier). Renommé en "Lignes traitées"
                    // pour être honnête sur ce qui a été parcouru.
                    ['Lignes traitées (parsées)', $countTotal],
                    ['Importables (avec URL valide)', $countImportable],
                    ['Ignorées (sans URL ou titre)', $countIgnored],
                    ['Mode', 'DRY-RUN (rien écrit)'],
                ]
            );

            // Aperçu des 5 premières opportunités importables
            if ($countImportable > 0) {
                $io->text('<comment>Aperçu des 5 premières opportunités importables :</comment>');
                $previewRows = [];
                foreach (array_slice($importResult->opportunities, 0, 5) as $opp) {
                    $previewRows[] = [
                        mb_substr($opp->title, 0, 40),
                        $opp->type,
                        mb_substr($opp->url, 0, 50),
                        $opp->deadline,
                        $opp->country,
                    ];
                }
                $io->table(
                    headers: ['Titre', 'Type', 'URL', 'Deadline', 'Pays'],
                    rows: $previewRows,
                );
            }

            $io->success(sprintf(
                'DRY-RUN terminé : %d opportunités seraient importées, %d ignorées.',
                $countImportable,
                $countIgnored
            ));
            return Command::SUCCESS;
        }

        // ── Persistance en BDD ─────────────────────────────────────────────────
        $io->section(sprintf('Étape 3 : Persistance (%d opportunités)', $countImportable));

        if ($countImportable === 0) {
            $io->warning('Aucune opportunité importable — vérifiez le CSV et les URLs.');
            return Command::SUCCESS;
        }

        // ScrapedResourcePersister gère la déduplication par URL
        // (même logique que pour les scrapers automatiques).
        $persistResult = $this->persister->persistBatch($importResult->opportunities);

        $io->text(sprintf(
            '  → <info>%d</info> créées  |  <comment>%d</comment> mises à jour  |  <comment>%d</comment> réactivées  |  %d ignorées (déjà en BDD)',
            $persistResult->inserted,
            $persistResult->updated,
            $persistResult->reactivated,
            $persistResult->skipped,
        ));

        // ── Enrichissement LLM (--enrich) ─────────────────────────────────────
        $enrichedCount = 0;
        $enrichErrors  = 0;

        if ($doEnrich && $persistResult->inserted > 0) {
            $io->section(sprintf(
                'Étape 4 : Enrichissement LLM (%d nouvelle(s) opportunité(s) uniquement)',
                $persistResult->inserted
            ));
            $io->text('Appel Mistral pour chaque NOUVELLE opportunité — les URL déjà en BDD sont ignorées.');

            // ── AV-4 : n'enrichir QUE les URLs réellement insérées ───────────────
            // Avant ce correctif, la boucle itérait sur $importResult->opportunities
            // (toutes les lignes parsées), y compris les URL déjà connues en BDD
            // (updated / skipped). Résultat : à chaque ré-import du même CSV,
            // Mistral était rappelé sur des entrées déjà enrichies → gaspillage de quota.
            //
            // Solution : PersistResult expose maintenant $insertedUrls (liste des URLs
            // Cas 1 — réellement nouvelles en BDD). On indexe les opportunités parsées
            // par URL pour retrouver rapidement le titre, puis on ne boucle que sur
            // les URLs insérées.
            //
            // Complexité : O(n) pour construire l'index + O(m) pour boucler sur les
            // insertées, avec n = nb lignes CSV et m = nb réellement insérées (m ≤ n).

            // Index URL → ScrapedOpportunity pour retrouver le titre dans la boucle
            /** @var array<string, \App\DTO\ScrapedOpportunity> $oppByUrl */
            $oppByUrl = [];
            foreach ($importResult->opportunities as $opp) {
                if ($opp->url !== '') {
                    $oppByUrl[$opp->url] = $opp;
                }
            }

            $batchCount       = 0;
            $insertedUrlsList = $persistResult->insertedUrls;
            $totalToEnrich    = count($insertedUrlsList);

            foreach ($insertedUrlsList as $urlIndex => $insertedUrl) {
                // Récupération de la ScrapedResource fraîchement insérée en BDD
                $scraped = $this->scrapedResourceRepository->findByUrl($insertedUrl);
                if ($scraped === null) {
                    // Ne devrait pas arriver (on vient de l'insérer), mais on sécurise
                    $this->logger->warning('[ImportGrantCsv] URL introuvable après insertion.', [
                        'url' => $insertedUrl,
                    ]);
                    continue;
                }

                // Titre pour l'affichage console (depuis l'index URL → DTO)
                $displayTitle = isset($oppByUrl[$insertedUrl])
                    ? mb_substr($oppByUrl[$insertedUrl]->title, 0, 50)
                    : mb_substr($insertedUrl, 0, 50);

                $io->write(sprintf(
                    '  [%d/%d] %s… ',
                    $urlIndex + 1,
                    $totalToEnrich,
                    $displayTitle
                ));

                try {
                    // ── Enrichissement LLM complet via OpportunityEnrichmentService ────────
                    // Depuis ADR-0016 Lot 2 correctif, enrich() retourne aussi :
                    //   - city             : ville de l'opportunité
                    //   - country          : pays (uniquement si vide dans le CSV)
                    //   - experienceLevel  : "beginner"|"intermediate"|"experienced"|null
                    //   - disciplinesLabels: tableau contraint à la liste BDD (filtré AV-2)
                    // On passe $insertedUrl (l'URL de la nouvelle entrée), pas $opp->url
                    // qui n'existe plus dans cette boucle (AV-4 : refacto vers insertedUrls).
                    $enrichment = $this->enrichmentService->enrich($insertedUrl);

                    if ($enrichment->isEmpty()) {
                        $io->writeln('<comment>IGNORÉ (enrichissement vide)</comment>');
                        continue;
                    }

                    // ── Règles d'enrichissement (priorité des données CSV) ─────────────────
                    // On NE JAMAIS écraser les données fiables du CSV :
                    //   - title    : fourni par Gaëlle → on garde le CSV
                    //   - deadline : parsé depuis le CSV → on garde le CSV
                    //   - type     : mappé depuis CATEGORY → on garde le CSV
                    //   - country  : issu du champ WHERE → on garde le CSV SAUF si vide
                    //
                    // On complète uniquement les champs vides ou absents du CSV :
                    //   - description      : NOTES du CSV est courte → LLM enrichit
                    //   - city             : absent du CSV → LLM peut la détecter
                    //   - country          : si WHERE était vide/inconnu → LLM peut détecter
                    //   - experienceLevel  : absent du CSV → LLM peut le détecter
                    //   - disciplines      : absent du CSV → LLM produit des labels contraints BDD

                    // ── Description : on enrichit si la description actuelle est vide ──────
                    // Les NOTES du CSV sont tronquées à 200 chars et souvent très courtes.
                    // La description LLM (HTML structuré) est plus complète — on la préfère.
                    if ($enrichment->description !== null && $enrichment->description !== '') {
                        $currentDesc = $scraped->getDescription();
                        if (empty($currentDesc)) {
                            // La description LLM peut faire jusqu'à 3 000 chars HTML.
                            // On la stocke telle quelle dans ScrapedResource::description
                            // (champ TEXT, pas de limite de longueur côté Doctrine).
                            $scraped->setDescription($enrichment->description);
                        }
                    }

                    // ── Ville : toujours remplir si le LLM en a détecté une ───────────────
                    // La ville n'est jamais présente dans le CSV (pas de colonne CITY).
                    if ($enrichment->city !== null && $enrichment->city !== '') {
                        // Troncature de sécurité : MAX 150 chars (limite colonne BDD)
                        $scraped->setCity(mb_substr($enrichment->city, 0, 150));
                    }

                    // ── Pays : on ne complète QUE si le CSV n'en avait pas ────────────────
                    // La colonne WHERE du CSV donne le pays → on le respecte.
                    // Si WHERE était vide (resolveCountry retourne ''), le LLM peut compléter.
                    $currentCountry = $scraped->getCountry();
                    if (
                        ($currentCountry === null || $currentCountry === '')
                        && $enrichment->country !== null
                        && $enrichment->country !== ''
                    ) {
                        // Troncature de sécurité : MAX 100 chars (limite colonne BDD)
                        $scraped->setCountry(mb_substr($enrichment->country, 0, 100));
                    }

                    // ── Niveau d'expérience : toujours remplir si le LLM en a détecté un ──
                    // Absent du CSV — uniquement détectable via la page de l'opportunité.
                    if ($enrichment->experienceLevel !== null) {
                        // ExperienceLevel::from() lève une ValueError si la valeur est invalide.
                        // validateAndBuildDto() garantit que seules les valeurs valides sont passées.
                        $scraped->setExperienceLevel(ExperienceLevel::from($enrichment->experienceLevel));
                    }

                    // ── Disciplines : LLM produit des labels contraints à la liste BDD ─────
                    // Le CSV ne contient pas de colonne disciplines. On les tire du LLM.
                    // DisciplineMapperService convertit les libellés en entités Discipline.
                    //
                    // IMPORTANT : ScrapedResource::disciplines est un champ string (CSV textuel
                    // pour affichage rapide). Les entités Discipline ne sont pas liées à
                    // ScrapedResource directement (seulement à Resource lors de la validation).
                    // On met donc à jour uniquement le champ string $disciplines.
                    if ($enrichment->disciplinesLabels !== null && $enrichment->disciplinesLabels !== []) {
                        // Construit la string CSV depuis les labels contraints
                        // ex: ["Musique", "Danse"] → "Musique, Danse"
                        $disciplinesString = implode(', ', $enrichment->disciplinesLabels);
                        $scraped->setDisciplines(mb_substr($disciplinesString, 0, 150));
                    } elseif ($enrichment->disciplines !== null && $enrichment->disciplines !== '') {
                        // Fallback : si disciplinesLabels est vide mais disciplines (string) existe
                        // (cas improbable avec le nouveau prompt, mais on sécurise)
                        $scraped->setDisciplines(mb_substr($enrichment->disciplines, 0, 150));
                    }

                    $io->writeln('<info>ENRICHI</info>');
                    $enrichedCount++;

                    // Flush par lots (BATCH_SIZE éléments à la fois)
                    $batchCount++;
                    if ($batchCount % self::ENRICH_BATCH_SIZE === 0) {
                        $this->em->flush();
                        // GC pour libérer la mémoire sur les gros lots
                        gc_collect_cycles();
                    }

                } catch (\Exception $e) {
                    // Une erreur d'enrichissement ne doit PAS annuler l'import principal
                    // On log l'erreur et on continue sur l'URL suivante
                    $io->writeln(sprintf(
                        '<error>ERREUR enrichissement : %s</error>',
                        $e->getMessage()
                    ));
                    $this->logger->error('[ImportGrantCsv] Erreur enrichissement LLM', [
                        // On utilise $insertedUrl et $displayTitle (disponibles dans la boucle AV-4)
                        // $opp n'existe plus dans cette boucle (refacto vers insertedUrls).
                        'url'       => $insertedUrl,
                        'title'     => $displayTitle,
                        'exception' => $e->getMessage(),
                    ]);
                    $enrichErrors++;
                }
            }

            // Flush final du dernier lot incomplet
            if ($enrichedCount > 0) {
                $this->em->flush();
            }
        }

        // ── Résumé final ───────────────────────────────────────────────────────
        $io->section('Résumé final');
        $io->table(
            headers: ['Métrique', 'Valeur'],
            rows: array_filter([
                // SG-1 : renommé "Lignes totales" → "Lignes traitées" car avec --limit
                // le chiffre reflète les lignes PARSÉES (pas le total du fichier).
                ['Lignes traitées (parsées)',     $countTotal],
                ['Importables (avec URL valide)', $countImportable],
                ['Ignorées (rapport ci-dessus)',  $countIgnored],
                ['Créées en BDD (nouvelles)',     $persistResult->inserted],
                ['Mises à jour (URL connue)',     $persistResult->updated],
                ['Réactivées (archivées)',        $persistResult->reactivated],
                ['Ignorées par déduplication',   $persistResult->skipped],
                $doEnrich ? ['Enrichies LLM',    $enrichedCount]    : null,
                $doEnrich ? ['Erreurs LLM',      $enrichErrors]     : null,
            ]),
        );

        if ($persistResult->inserted > 0) {
            $io->success(sprintf(
                '%d opportunité(s) créée(s) dans scraped_resources (status=pending). '
                . 'À valider sur /admin/scraped-opportunities.',
                $persistResult->inserted
            ));
        } elseif ($persistResult->updated > 0 || $persistResult->reactivated > 0) {
            $io->note(sprintf(
                'Aucune nouvelle opportunité : %d mise(s) à jour, %d réactivée(s) (déjà en BDD).',
                $persistResult->updated,
                $persistResult->reactivated,
            ));
        } else {
            $io->warning('Aucune opportunité importée — toutes les URLs étaient déjà en BDD.');
        }

        return Command::SUCCESS;
    }
}

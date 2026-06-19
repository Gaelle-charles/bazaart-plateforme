<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DiscoveryResult;
use App\Service\GrantCsvImporter;
use App\Service\ListingUrlDiscoverer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * DiscoverListingUrlsCommand — Pour chaque site du CSV, trouve la page qui LISTE les opportunités.
 *
 * Cette commande résout le problème suivant :
 *   Le CSV de Gaëlle contient des domaines racines (ex: institutfrancais.com) mais
 *   n'indique pas quelle sous-page liste les appels à candidatures/bourses/résidences.
 *   Cette commande cherche automatiquement cette page et l'enregistre comme
 *   ScrapingSource agrégateur pour que le scraper existant (ADR-0016 Lot 1) puisse
 *   ensuite collecter les opportunités.
 *
 * Flux de traitement (délégué à ListingUrlDiscoverer) :
 *   1. HEURISTIQUE : tester des chemins courants FR+EN (/appels-a-projets, /grants, etc.)
 *      → Économique, sans coût API, fonctionne sur ~60 % des sites
 *   2. FALLBACK LLM : si l'heuristique échoue, interroger Mistral avec la page d'accueil
 *      → Couvre les sites aux URLs atypiques (ex: /nos-actions/soutiens)
 *   3. ENREGISTREMENT : créer une ScrapingSource agrégateur en BDD (type: html_llm,
 *      estAgregateur: true, scraperSlug: null) si pas de doublon
 *
 * Entrées :
 *   [csv-path] : argument optionnel — chemin vers le CSV de Gaëlle
 *                Par défaut : /var/www/html/var/grant.csv (chemin container)
 *
 * Options :
 *   --url=<url>  : tester un seul site (bypasse le CSV, utile pour debug)
 *   --dry-run    : simuler sans créer de source en BDD
 *   --limit=N    : traiter seulement N sites (utile pour tester sur un sous-ensemble)
 *
 * Exemples :
 *   php bin/console app:discover-listing-urls --dry-run --url=https://institutfrancais.com
 *   php bin/console app:discover-listing-urls var/grant.csv --limit=10
 *   php bin/console app:discover-listing-urls --limit=2
 *
 * IMPORTANT : NE PAS confondre avec app:discover-sources (qui analyse les pages des
 *   agrégateurs CONNUS pour trouver de nouveaux organismes).
 *   Cette commande fait l'inverse : pour chaque organisme connu (dans le CSV),
 *   elle trouve la PAGE-LISTE des opportunités.
 */
#[AsCommand(
    name: 'app:discover-listing-urls',
    description: 'Pour chaque site du CSV, trouve et enregistre la page qui liste les opportunités (heuristique + LLM).',
)]
class DiscoverListingUrlsCommand extends Command
{
    /**
     * Chemin par défaut vers le CSV dans le container Docker.
     * Le CSV est monté via le volume Docker à cet emplacement.
     */
    private const DEFAULT_CSV_PATH = '/var/www/html/var/grant.csv';

    public function __construct(
        // Service de découverte — contient TOUTE la logique métier (heuristique + LLM + BDD)
        private readonly ListingUrlDiscoverer $discoverer,
        // GrantCsvImporter — réutilisé pour parser le CSV et extraire URLs/domaines
        // extractUrl() → URL propre depuis le champ LINK
        // extractDomain() → domaine depuis une URL (ex: "institutfrancais.com")
        // resolveCountry() → conversion code ISO → nom en clair (ex: "FR" → "France")
        private readonly GrantCsvImporter $csvImporter,
        // EntityManager — pour le flush() en fin de boucle (lazy flush)
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'csv-path',
                InputArgument::OPTIONAL,
                'Chemin vers le fichier CSV des grants (défaut : /var/www/html/var/grant.csv)',
                self::DEFAULT_CSV_PATH
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Tester un seul site (ex: --url=https://institutfrancais.com). Bypasse le CSV.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simuler sans créer de ScrapingSource en BDD (affiche les URLs-listes détectées)'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre maximum de sites à traiter (utile pour tester sur un sous-ensemble du CSV)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Lire le --limit (null = pas de limite)
        $limitRaw = $input->getOption('limit');
        $limit    = $limitRaw !== null ? (int) $limitRaw : null;

        $io->title('BazaArt — Découverte des URLs-listes d\'opportunités');

        if ($dryRun) {
            $io->note('Mode --dry-run : aucune ScrapingSource ne sera créée en BDD.');
        }

        // ── Cas 1 : option --url — tester un seul site ────────────────────────
        /** @var string|null $singleUrl */
        $singleUrl = $input->getOption('url');

        if ($singleUrl !== null) {
            return $this->processSingleUrl($singleUrl, $io, $output, $dryRun);
        }

        // ── Cas 2 : traitement depuis le CSV ──────────────────────────────────
        /** @var string $csvPath */
        $csvPath = $input->getArgument('csv-path');

        return $this->processFromCsv($csvPath, $io, $output, $dryRun, $limit);
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  TRAITEMENT D'UN SEUL SITE (option --url)
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Traite une seule URL (mode --url).
     *
     * Utile pour tester la commande sur un site spécifique avant de lancer
     * un traitement complet du CSV.
     *
     * Avec le flag -v (--verbose), les étapes de diagnostic du chemin LLM sont affichées :
     * taille de la page d'accueil, nombre de liens extraits, réponse brute du LLM,
     * motif de rejet éventuel (hors-liste, SSRF, URL invalide, etc.).
     *
     * @param string          $url    URL du site à analyser
     * @param SymfonyStyle    $io     Interface console
     * @param OutputInterface $output Interface de sortie (pour vérifier le niveau de verbosité)
     * @param bool            $dryRun Mode simulation
     * @return int Code retour Symfony (SUCCESS ou FAILURE)
     */
    private function processSingleUrl(string $url, SymfonyStyle $io, OutputInterface $output, bool $dryRun): int
    {
        $io->text(sprintf('Analyse du site : %s', $url));
        $io->newLine();

        // Extraire le nom depuis le domaine (ex: "institutfrancais.com")
        $baseUrl = $this->discoverer->extractBaseUrl($url);
        $nom     = $baseUrl !== null
            ? (string) (parse_url($baseUrl, PHP_URL_HOST) ?? $url)
            : $url;

        // Lancer la découverte
        $result = $this->discoverer->discoverForSite(
            siteUrl: $url,
            nomSite: $nom,
            paysZone: null,
            dryRun: $dryRun,
        );

        // Afficher le résultat (+ diagnostic LLM en mode -v)
        $this->displayResult($result, $io, $output, $dryRun);

        // Flush si une source a été créée (non dry-run)
        if (!$dryRun && $result->found() && !$result->isDuplicate()) {
            $this->em->flush();
            $io->success('ScrapingSource créée et persistée en BDD.');
        }

        return Command::SUCCESS;
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  TRAITEMENT DEPUIS LE CSV
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Traite les sites extraits du CSV.
     *
     * EXTRACTION DES SITES DEPUIS LE CSV :
     *   Le CSV contient des colonnes OPEN CALLS, LINK, WHERE.
     *   Pour chaque ligne :
     *     1. Extraire l'URL depuis le champ LINK (réutilise GrantCsvImporter::extractUrl())
     *     2. Extraire le domaine de base (ex: "https://institutfrancais.com")
     *     3. Déduplication intra-lot : on ne traite pas le même domaine deux fois dans
     *        le même run (le CSV peut avoir plusieurs lignes pour le même organisme)
     *     4. Appeler ListingUrlDiscoverer::discoverForSite()
     *
     * DÉDUPLICATION :
     *   On déduplique par DOMAINE (pas par URL complète) car le CSV peut avoir :
     *     "https://institutfrancais.com/calls" ET "https://institutfrancais.com/grants"
     *   → on ne veut analyser institutfrancais.com qu'UNE SEULE fois.
     *
     * MODE VERBOSE (-v) :
     *   Pour les sites où le fallback LLM a été tenté, les étapes de diagnostic
     *   sont affichées sous chaque site (taille de la page d'accueil, nombre de
     *   liens extraits, réponse brute du LLM, motif de rejet).
     *
     * @param string          $csvPath Chemin vers le CSV
     * @param SymfonyStyle    $io      Interface console
     * @param OutputInterface $output  Interface de sortie (pour le niveau de verbosité)
     * @param bool            $dryRun  Mode simulation
     * @param int|null        $limit   Nombre max de sites à traiter
     * @return int Code retour Symfony
     */
    private function processFromCsv(
        string $csvPath,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $dryRun,
        ?int $limit,
    ): int {
        // ── Vérification du fichier CSV ───────────────────────────────────────
        if (!file_exists($csvPath)) {
            $io->error(sprintf('Fichier CSV introuvable : %s', $csvPath));
            $io->text('Vérifiez que le fichier existe et que le chemin est correct.');
            $io->text('Vous pouvez aussi utiliser --url=<url> pour tester un seul site.');
            return Command::FAILURE;
        }

        $io->text(sprintf('Fichier CSV : %s', $csvPath));
        if ($limit !== null) {
            $io->text(sprintf('Limite : %d site(s)', $limit));
        }
        $io->newLine();

        // ── Parsing du CSV pour extraire les sites ────────────────────────────
        // On réutilise GrantCsvImporter::parseFile() pour lire le CSV.
        // Mais on a besoin des domaines, pas des opportunités complètes.
        // On lit le CSV directement avec SplFileObject pour extraire LINK + WHERE.
        $sites = $this->extractSitesFromCsv($csvPath, $limit, $io);

        if (empty($sites)) {
            $io->warning('Aucun site avec URL exploitable trouvé dans le CSV.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('%d site(s) unique(s) à analyser.', count($sites)));
        $io->newLine();

        // ── Compteurs pour le rapport final ───────────────────────────────────
        /** @var DiscoveryResult[] $results */
        $results        = [];
        $foundHeuristic = 0; // Trouvés par heuristique
        $foundLlm       = 0; // Trouvés par LLM
        $notFound       = 0; // Non trouvés
        $duplicates     = 0; // Doublons ignorés
        $created        = 0; // Sources créées en BDD

        // ── Traitement de chaque site ─────────────────────────────────────────
        foreach ($sites as $siteInfo) {
            $siteUrl  = $siteInfo['url'];
            $nom      = $siteInfo['nom'];
            $paysZone = $siteInfo['pays_zone'];

            $io->text(sprintf('  → Analyse : <info>%s</info> (%s)', $nom, $siteUrl));

            // Déléguer la découverte au service métier
            $result = $this->discoverer->discoverForSite(
                siteUrl: $siteUrl,
                nomSite: $nom,
                paysZone: $paysZone,
                dryRun: $dryRun,
            );

            $results[] = $result;

            // ── Affichage du résultat pour ce site ────────────────────────────
            if ($result->found()) {
                $prefix = match ($result->method) {
                    'heuristic' => '    [HEURISTIQUE]',
                    'llm'       => '    [LLM]',
                    default     => '    [?]',
                };

                if ($result->isDuplicate()) {
                    $io->text(sprintf(
                        '%s URL-liste : %s  <comment>(déjà en BDD — ignoré)</comment>',
                        $prefix,
                        $result->listingUrl
                    ));
                    $duplicates++;
                } else {
                    $io->text(sprintf(
                        '%s URL-liste : <info>%s</info>  %s',
                        $prefix,
                        $result->listingUrl,
                        $dryRun ? '<comment>[DRY-RUN — non persisté]</comment>' : '<comment>[sera créé]</comment>'
                    ));
                    $created++;
                }

                // Compteurs par méthode
                if ($result->method === 'heuristic') {
                    $foundHeuristic++;
                } elseif ($result->method === 'llm') {
                    $foundLlm++;
                }
            } else {
                $io->text('    <comment>Aucune URL-liste détectée</comment>');
                $notFound++;
            }

            // ── Mode verbeux (-v) : diagnostic LLM pour ce site ──────────────
            // En mode -v, on affiche les étapes du chemin LLM pour chaque site
            // qui a tenté le fallback LLM (debugSteps non vide).
            // Cela permet de voir, site par site, pourquoi la découverte a échoué
            // (ou réussi) sans avoir à fouiller les logs du container.
            if ($output->isVerbose() && !empty($result->debugSteps)) {
                foreach ($result->debugSteps as $step) {
                    $io->text(sprintf('      <comment>[LLM diagnostic]</comment> %s', $step));
                }
            }
        }

        // ── Flush si des sources ont été créées ───────────────────────────────
        // Un seul flush à la fin = une seule transaction pour tout le lot.
        // Beaucoup plus efficace que de flusher après chaque source.
        if (!$dryRun && $created > 0) {
            $this->em->flush();
            $io->newLine();
            $io->text('<info>Flush BDD effectué.</info>');
        }

        // ── Rapport de synthèse ───────────────────────────────────────────────
        $this->displaySummary($io, $results, [
            'total'          => count($sites),
            'foundHeuristic' => $foundHeuristic,
            'foundLlm'       => $foundLlm,
            'notFound'       => $notFound,
            'duplicates'     => $duplicates,
            'created'        => $created,
            'dryRun'         => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  PARSING DU CSV
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Extrait la liste des sites uniques depuis le CSV.
     *
     * Réutilise GrantCsvImporter::extractUrl() et resolveCountry() pour le parsing
     * du champ LINK et la conversion du code pays.
     *
     * Déduplication par DOMAINE : si institutfrancais.com apparaît dans 3 lignes du CSV,
     * on ne l'analyse qu'une seule fois (on prend le premier nom rencontré).
     *
     * @param string      $csvPath Chemin vers le CSV
     * @param int|null    $limit   Nombre max de sites à retourner
     * @param SymfonyStyle $io     Interface console (pour les avertissements)
     * @return array<int, array{url: string, nom: string, pays_zone: string|null}> Sites uniques
     */
    private function extractSitesFromCsv(string $csvPath, ?int $limit, SymfonyStyle $io): array
    {
        $sites      = []; // Résultat final : tableau de {url, nom, pays_zone}
        $seenHosts  = []; // Guard de déduplication par domaine (ex: "institutfrancais.com")

        try {
            // ── Ouverture du CSV ───────────────────────────────────────────────
            $file = new \SplFileObject($csvPath, 'r');
            $file->setFlags(
                \SplFileObject::READ_CSV
                | \SplFileObject::SKIP_EMPTY
                | \SplFileObject::READ_AHEAD
                | \SplFileObject::DROP_NEW_LINE
            );
            $file->setCsvControl(',', '"', '\\');

            // ── Lecture de l'en-tête ───────────────────────────────────────────
            $rawHeader = $file->current();
            if (!is_array($rawHeader)) {
                $io->error('Le CSV est vide ou son en-tête n\'est pas lisible.');
                return [];
            }

            // Suppression du BOM UTF-8 (même logique que GrantCsvImporter)
            if (isset($rawHeader[0])) {
                $rawHeader[0] = ltrim($rawHeader[0], "\xEF\xBB\xBF");
            }

            // Normalisation des noms de colonnes : trim + majuscules
            $header = array_map(
                static fn (string $col): string => strtoupper(trim($col)),
                $rawHeader
            );

            $file->next();

            // ── Lecture des lignes ─────────────────────────────────────────────
            while ($file->valid()) {
                // Arrêt si limite atteinte
                if ($limit !== null && count($sites) >= $limit) {
                    break;
                }

                /** @var array<int, string>|null $rawRow */
                $rawRow = $file->current();
                $file->next();

                if (!is_array($rawRow) || count($rawRow) < 2) {
                    continue;
                }

                // Mapping en-tête → valeur pour cette ligne
                /** @var array<string, string> $row */
                $row = [];
                foreach ($header as $idx => $colName) {
                    $row[$colName] = isset($rawRow[$idx]) ? trim($rawRow[$idx]) : '';
                }

                // ── Extraction de l'URL depuis le champ LINK ───────────────────
                // GrantCsvImporter::extractUrl() gère tous les formats du CSV :
                //   "https://example.com", "Texte (example.com)", "Texte | (example.com)"...
                $rawLink  = $row['LINK'] ?? '';
                $rawTitle = $row['OPEN CALLS'] ?? '';
                $rawWhere = $row['WHERE'] ?? '';

                $url = $this->csvImporter->extractUrl($rawLink);

                if ($url === null) {
                    // Ligne sans URL — ignorée silencieusement (rapport à la fin)
                    continue;
                }

                // ── Extraction du domaine pour déduplication ───────────────────
                // On travaille au niveau domaine, pas URL complète, pour regrouper
                // les lignes du même organisme (ex: plusieurs bourses sur le même site)
                $baseUrl = $this->discoverer->extractBaseUrl($url);

                if ($baseUrl === null) {
                    continue;
                }

                $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?? '');
                if ($host === '') {
                    continue;
                }

                // Déduplication : si ce domaine est déjà dans $seenHosts, on passe
                if (isset($seenHosts[$host])) {
                    continue;
                }

                $seenHosts[$host] = true;

                // ── Nom lisible ────────────────────────────────────────────────
                // Priorité : titre du CSV > domaine (fallback)
                // On nettoie le titre (souvent une description d'opportunité, pas un nom d'organisme)
                $nom = trim($rawTitle) !== ''
                    ? mb_substr(trim($rawTitle), 0, 255)
                    : $host;

                // ── Zone géographique ──────────────────────────────────────────
                // GrantCsvImporter::resolveCountry() convertit "FR" → "France", etc.
                $paysZone = $this->csvImporter->resolveCountry($rawWhere) ?: null;

                $sites[] = [
                    'url'       => $baseUrl, // On utilise la base URL (pas l'URL complète de l'opp)
                    'nom'       => $nom,
                    'pays_zone' => $paysZone,
                ];
            }

        } catch (\Throwable $e) {
            $io->error(sprintf('Erreur de lecture du CSV : %s', $e->getMessage()));
            return [];
        }

        return $sites;
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  AFFICHAGE
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Affiche le résultat pour un seul site (mode --url).
     *
     * En mode verbeux (-v), affiche également les étapes de diagnostic du chemin LLM
     * (DiscoveryResult::$debugSteps). Cela permet de voir exactement où la découverte
     * a bloqué : fetch de la page d'accueil, extraction de liens, réponse du LLM,
     * validation anti-hallucination, etc.
     *
     * @param DiscoveryResult $result  Résultat de la découverte
     * @param SymfonyStyle    $io      Interface console
     * @param OutputInterface $output  Interface de sortie (pour le niveau de verbosité)
     * @param bool            $dryRun  Mode simulation
     */
    private function displayResult(
        DiscoveryResult $result,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $dryRun,
    ): void {
        $io->definitionList(
            ['Site analysé'  => $result->siteUrl],
            ['URL-liste'     => $result->listingUrl ?? '(aucune)'],
            ['Méthode'       => $result->method],
            ['Statut BDD'    => $result->isDuplicate() ? 'Doublon (déjà en BDD)' : ($dryRun ? 'DRY-RUN (non persisté)' : 'Créée')],
        );

        // ── Mode verbeux (-v) : afficher les étapes de diagnostic du LLM ──────
        // $debugSteps n'est rempli que si le fallback LLM a été tenté.
        // Il permet de diagnostiquer exactement où bloque la découverte :
        //   - page d'accueil inaccessible (timeout, HTTP non-200, SSRF bloqué)
        //   - nombre de liens extraits (0 = SPA JavaScript sans <a href>)
        //   - réponse brute du LLM (hallucination ? JSON invalide ?)
        //   - motif de rejet (hors-liste, SSRF, URL mal formée)
        if ($output->isVerbose() && !empty($result->debugSteps)) {
            $io->section('Diagnostic LLM (--verbose)');
            foreach ($result->debugSteps as $step) {
                $io->text(sprintf('  <comment>•</comment> %s', $step));
            }
            $io->newLine();
        }

        if ($result->found() && !$dryRun && !$result->isDuplicate()) {
            $io->success(sprintf('URL-liste trouvée (%s) : %s', $result->method, $result->listingUrl));
        } elseif ($result->found() && $dryRun) {
            $io->note(sprintf('[DRY-RUN] URL-liste détectée (%s) : %s', $result->method, $result->listingUrl));
        } elseif ($result->isDuplicate()) {
            $io->note(sprintf('URL déjà en BDD — aucune source créée : %s', $result->listingUrl));
        } else {
            $io->warning('Aucune URL-liste détectée pour ce site.');
            $io->text('Causes possibles :');
            $io->listing([
                'Le site n\'a pas de page dédiée aux opportunités',
                'L\'URL de listing n\'est pas dans les chemins testés par l\'heuristique',
                'La page d\'accueil est derrière un mur de cookies ou JavaScript',
                'Les clés API LLM (Mistral/Anthropic) ne sont pas configurées dans /admin/settings',
            ]);
            // En mode verbeux, suggérer de chercher dans les étapes de diagnostic
            if ($output->isVerbose() && empty($result->debugSteps)) {
                $io->text('<comment>Note (-v) : aucune étape LLM à afficher (l\'heuristique a peut-être planté avant d\'atteindre le LLM, ou le site était inaccessible).</comment>');
            }
        }
    }

    /**
     * Affiche le rapport de synthèse après le traitement du CSV.
     *
     * FORMAT DU RAPPORT :
     *   - Tableau récapitulatif des compteurs
     *   - Liste des sites où AUCUNE URL-liste n'a été trouvée (pour action manuelle)
     *   - Message de succès/info selon le contexte
     *
     * @param SymfonyStyle         $io      Interface console
     * @param DiscoveryResult[]    $results Tous les résultats
     * @param array<string, mixed> $stats   Compteurs du traitement
     */
    private function displaySummary(SymfonyStyle $io, array $results, array $stats): void
    {
        $io->newLine();
        $io->title('Rapport de découverte des URLs-listes');

        // ── Tableau récapitulatif ─────────────────────────────────────────────
        $io->definitionList(
            ['Sites traités'                   => (int) $stats['total']],
            ['URLs-listes trouvées (heuristique)' => (int) $stats['foundHeuristic']],
            ['URLs-listes trouvées (LLM)'      => (int) $stats['foundLlm']],
            ['Aucune URL-liste détectée'        => (int) $stats['notFound']],
            ['Doublons ignorés (déjà en BDD)'  => (int) $stats['duplicates']],
            ['Sources créées en BDD'            => $stats['dryRun'] ? ((int) $stats['created'] . ' (simulation)') : (int) $stats['created']],
        );

        // ── Liste des sites sans URL-liste (pour action manuelle de Gaëlle) ──
        $failedSites = array_filter($results, fn (DiscoveryResult $r) => !$r->found());

        if (!empty($failedSites)) {
            $io->newLine();
            $io->section('Sites sans URL-liste détectée (à traiter manuellement)');
            $io->text('Ces sites n\'ont pas de page-liste identifiable par heuristique ni LLM :');
            $io->newLine();

            foreach ($failedSites as $r) {
                $io->text(sprintf('  - <comment>%s</comment>  (%s)', $r->nom, $r->siteUrl));
            }

            $io->newLine();
            $io->text('Pour ces sites, vous pouvez :');
            $io->listing([
                'Ajouter manuellement la source via /admin/scraping-sources',
                'Utiliser --url=<url> pour tester individuellement avec plus de verbosité',
                'Vérifier si le site publie vraiment des opportunités régulières',
            ]);
        }

        // ── Message de conclusion ─────────────────────────────────────────────
        $totalFound = (int) $stats['foundHeuristic'] + (int) $stats['foundLlm'];

        if ($totalFound === 0) {
            $io->note('Aucune nouvelle source créée (toutes déjà en BDD ou aucune URL-liste détectée).');
        } elseif ((bool) $stats['dryRun']) {
            // Mode simulation : on indique le nombre d'URLs qui auraient été persistées
            $io->note(sprintf(
                '[DRY-RUN] %d URL(s)-listes auraient été enregistrées. Relancez sans --dry-run pour persister.',
                (int) $stats['created']
            ));
        } else {
            // Mode réel : les sources ont été créées et flushées
            $io->success(sprintf(
                '%d source(s) agrégateur créée(s) en BDD. Lancez "app:scrape-opportunities" pour collecter les opportunités.',
                (int) $stats['created']
            ));
        }
    }
}

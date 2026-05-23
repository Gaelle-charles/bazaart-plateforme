<?php

namespace App\Command;

use App\Entity\ScrapedResource;
use App\Repository\ScrapedResourceRepository;
use App\Service\AfrodiasporaRelevanceScorer;
use App\Service\Scraper\CnapScraper;
use App\Service\Scraper\CnmScraper;
use App\Service\Scraper\MusiquesActuellesScraper;
use App\Service\Scraper\ProHelvetiaScraper;
use App\Service\Scraper\SaifScraper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ScrapeOpportunitiesCommand — Collecte les opportunités culturelles et les stocke en BDD.
 *
 * Flux :
 *   1. Scrapers collectent les opportunités sur chaque site
 *   2. Score Afrodiaspora calculé pour chaque opportunité
 *   3. Sauvegarde en BDD dans la table scraped_resources (status = 'pending')
 *   4. Déduplication par URL : une opportunité déjà présente n'est pas réinsérée
 *
 * Lancement manuel :
 *   docker compose exec app php bin/console app:scrape-opportunities
 *
 * Option --dry-run pour tester sans écrire en BDD :
 *   docker compose exec app php bin/console app:scrape-opportunities --dry-run
 *
 * Automatisation (cron 3x/semaine → lundi, mercredi, vendredi à 7h) :
 *   0 7 * * 1,3,5 docker compose exec -T app php bin/console app:scrape-opportunities
 */
#[AsCommand(
    name: 'app:scrape-opportunities',
    description: 'Scrape les opportunités culturelles françaises et les stocke en base de données',
)]
class ScrapeOpportunitiesCommand extends Command
{
    public function __construct(
        private readonly CnapScraper $cnapScraper,
        private readonly CnmScraper $cnmScraper,
        private readonly ProHelvetiaScraper $proHelvetiaScraper,
        private readonly SaifScraper $saifScraper,
        private readonly MusiquesActuellesScraper $musiquesActuellesScraper,
        private readonly AfrodiasporaRelevanceScorer $relevanceScorer,
        // EntityManager pour sauvegarder en BDD
        private readonly EntityManagerInterface $em,
        // Repository pour vérifier les doublons avant insertion
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
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
            'Affiche les détails techniques de chaque fetch (status HTTP, sélecteurs trouvés...)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $isDryRun  = $input->getOption('dry-run');
        $isDebug   = $input->getOption('debug');

        $io->title('BazaArt — Scraping des opportunités culturelles');

        if ($isDryRun) {
            $io->note('Mode DRY-RUN actif : les données ne seront PAS écrites en base de données');
        }

        // ── Liste des scrapers actifs ────────────────────────────────────────
        // Pour ajouter un site : créer son scraper et l'ajouter ici
        $scrapers = [
            $this->cnapScraper,                // cnap.fr        — HTML (arts plastiques)
            $this->cnmScraper,                 // cnm.fr         — RSS  (musique)
            $this->proHelvetiaScraper,         // prohelvetia.ch — RSS  (multidiscipline)
            $this->saifScraper,                // saif.fr        — HTML (image fixe)
            $this->musiquesActuellesScraper,   // musiquesactuelles.fr — RSS (musique FR)
        ];

        /** @var \App\DTO\ScrapedOpportunity[] $allOpportunities */
        $allOpportunities = [];
        $errors           = [];

        // ── Boucle sur chaque scraper ────────────────────────────────────────
        foreach ($scrapers as $scraper) {
            $io->section('Scraping : ' . $scraper->getName());

            // Mode debug : inspecte le fetch AVANT de scraper
            if ($isDebug) {
                $info = $scraper->getDebugInfo($scraper->getTestUrl());
                $io->definitionList(
                    ['URL testée'    => $info['url']],
                    ['Status HTTP'   => $info['status_code'] ?: ('ERREUR: ' . $info['error'])],
                    ['Taille HTML'   => $info['html_length'] . ' octets'],
                    ['h2 a'          => $info['selectors']['h2 a'] ?? 0],
                    ['h3 a'          => $info['selectors']['h3 a'] ?? 0],
                    ['article'       => $info['selectors']['article'] ?? 0],
                    ['.wp-block-cnm-cnm-card' => $info['selectors']['.wp-block-cnm-cnm-card'] ?? 0],
                    ['.news-item'    => $info['selectors']['.news-item'] ?? 0],
                    ['a[href] total' => $info['selectors']['a[href]'] ?? 0],
                );
            }

            try {
                $opportunities = $scraper->scrape();
                $count         = \count($opportunities);

                if ($count === 0) {
                    $io->warning('Aucune opportunité trouvée (site inaccessible ou aucun appel en cours)');
                } else {
                    $io->success(\sprintf('%d opportunité(s) trouvée(s)', $count));
                    $allOpportunities = \array_merge($allOpportunities, $opportunities);
                }
            } catch (\Exception $e) {
                $errors[] = $scraper->getName() . ' : ' . $e->getMessage();
                $io->error('Erreur : ' . $e->getMessage());
            }
        }

        // ── Résumé terminal ──────────────────────────────────────────────────
        $io->section('Résumé');
        $io->text(sprintf('<info>Total collecté : %d opportunité(s)</info>', count($allOpportunities)));

        if (!empty($allOpportunities)) {
            // Aperçu des 10 premières dans le terminal
            $previewRows = [];
            foreach (array_slice($allOpportunities, 0, 10) as $opp) {
                $score       = $this->relevanceScorer->score($opp->title, $opp->description ?? '');
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
                $io->note(sprintf('... et %d autre(s). Voir l\'admin pour la liste complète.', count($allOpportunities) - 10));
            }
        }

        // ── Sauvegarde en base de données ────────────────────────────────────
        if (!$isDryRun && !empty($allOpportunities)) {
            $io->section('Sauvegarde en base de données');

            $inserted  = 0;
            $skipped   = 0;

            foreach ($allOpportunities as $opp) {
                // Déduplication : si l'URL existe déjà en BDD, on ne réinsère pas
                if ($opp->url && $this->scrapedResourceRepository->findByUrl($opp->url) !== null) {
                    $skipped++;
                    continue;
                }

                // Calcule le score de pertinence Afrodiaspora
                $score = $this->relevanceScorer->score($opp->title, $opp->description ?? '');

                // Crée l'entité ScrapedResource et remplit les champs
                $scraped = new ScrapedResource();
                $scraped->setTitle($opp->title);
                $scraped->setDescription($opp->description ?: null);
                $scraped->setUrl($opp->url ?: null);
                $scraped->setType($opp->type ?: null);
                $scraped->setSourceSite($opp->source ?: null);
                $scraped->setDeadline($opp->deadline ?: null);
                $scraped->setRelevanceScore($score);
                $scraped->setDocuments($opp->documents ?: null);
                // Status par défaut : 'pending' (À vérifier) — l'admin valide ensuite

                $this->em->persist($scraped);
                $inserted++;
            }

            // Envoie toutes les insertions en une seule transaction
            $this->em->flush();

            $io->success(sprintf('%d opportunité(s) ajoutée(s) en BDD (%d doublon(s) ignoré(s))', $inserted, $skipped));
        }

        // ── Rapport erreurs ───────────────────────────────────────────────────
        if (!empty($errors)) {
            $io->section('Erreurs rencontrées');
            foreach ($errors as $error) {
                $io->text('- ' . $error);
            }
        }

        $io->success('Scraping terminé !');
        return Command::SUCCESS;
    }
}

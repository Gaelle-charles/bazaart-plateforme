<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScrapingSource;
use App\Enum\ScrapingSourceType;
use App\Repository\ScrapingSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SeedScrapingSourcesCommand — Initialise les sources de scraping en base de données.
 *
 * Cette commande crée les enregistrements scraping_sources pour les 10 sources
 * historiques du projet (celles qui ont leurs classes PHP dédiées dans App\Service\Scraper\).
 *
 * Elle est IDEMPOTENTE : on peut la relancer sans risque.
 * La déduplication se fait par URL — si une source existe déjà, elle est skippée.
 *
 * Avec --force : met à jour les champs (nom, type, discipline, zone) des sources existantes
 * sans toucher à actif, scraperSlug, ni aux stats de run.
 *
 * Lancement initial :
 *   docker compose exec app php bin/console app:seed-scraping-sources
 *
 * Forcer la mise à jour des métadonnées :
 *   docker compose exec app php bin/console app:seed-scraping-sources --force
 *
 * POUR AJOUTER UNE NOUVELLE SOURCE CUSTOM (avec classe PHP) :
 *   1. Créer la classe App\Service\Scraper\MonSiteScraper
 *   2. Ajouter son slug dans ScraperRegistry
 *   3. Ajouter une entrée dans $sources ci-dessous
 *   4. Relancer la commande
 */
#[AsCommand(
    name: 'app:seed-scraping-sources',
    description: 'Initialise les sources de scraping (scraping_sources) en BDD si absentes',
)]
class SeedScrapingSourcesCommand extends Command
{
    public function __construct(
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Met à jour les métadonnées (nom, type, discipline, zone) des sources déjà existantes'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $io->title('BazaArt — Initialisation des sources de scraping');

        if ($force) {
            $io->warning('Mode --force activé : les métadonnées des sources existantes seront mises à jour.');
        }

        /**
         * Définition des sources à initialiser.
         *
         * Colonnes :
         *   nom        → Libellé lisible affiché dans l'admin
         *   url        → URL du flux RSS ou de la page HTML (clé de déduplication)
         *   type       → ScrapingSourceType enum
         *   slug       → scraperSlug (null = GenericScraper, renseigné = classe PHP dédiée)
         *   discipline → Discipline principale (pour enrichir les DTOs dans GenericScraper)
         *   zone       → Zone géographique (affichage admin)
         *   actif      → Activée par défaut ?
         *
         * NOTE : culture.gouv.fr est désactivé (actif = false) car le site utilise JavaScript/Algolia
         * pour charger son contenu — la page HTML reçue est vide. Désactivé sans supprimer
         * l'entrée BDD pour garder la traçabilité.
         */
        /**
         * Sources de scraping — description des colonnes :
         *
         *   nom          → Libellé lisible affiché dans l'admin
         *   url          → URL du flux RSS ou de la page HTML (clé de déduplication)
         *   type         → ScrapingSourceType enum (RSS, HtmlLlm, HtmlCss)
         *   slug         → scraperSlug (null = GenericScraper, renseigné = classe PHP dédiée)
         *   discipline   → Discipline principale (pour enrichir les DTOs dans GenericScraper)
         *   zone         → Zone géographique (affichage admin)
         *   actif        → Activée par défaut ?
         *   est_agregateur → true si ce site LISTE d'autres organismes (vs publie ses propres opps)
         *                    Les agrégateurs sont analysés par app:discover-sources pour
         *                    trouver de nouvelles sources à ajouter au système.
         *
         * Agrégateurs identifiés (est_agregateur = true) :
         *   on-the-move   → Réseau international de mobilité culturelle, liste des partenaires mondiaux
         *   resartis      → Réseau mondial de résidences, répertorie des centaines d'institutions membres
         *   culture-moves-eu → EACEA, recense les programmes UE et leurs partenaires institutionnels
         *
         * Sources directes (est_agregateur = false) :
         *   cnap, cnm, prohelvetia, saif, musiques-actuelles, adagp, culture-gouv
         *   → Ces sites publient LEURS PROPRES opportunités, pas des listes d'organismes tiers.
         */
        $sources = [
            [
                'nom'            => 'CNAP - Centre National des Arts Plastiques',
                'url'            => 'https://www.cnap.fr/actualites',
                'type'           => ScrapingSourceType::HtmlCss,
                'slug'           => 'cnap',
                'discipline'     => 'Arts plastiques',
                'zone'           => 'France',
                'actif'          => true,
                'est_agregateur' => false, // Source directe : publie ses propres bourses et prix
            ],
            [
                'nom'            => 'CNM - Centre National de la Musique',
                'url'            => 'https://cnm.fr/feed/',
                'type'           => ScrapingSourceType::RSS,
                'slug'           => 'cnm',
                'discipline'     => 'Musique',
                'zone'           => 'France',
                'actif'          => true,
                'est_agregateur' => false, // Source directe : publie ses propres aides à la musique
            ],
            [
                'nom'            => 'Pro Helvetia - Fondation suisse pour la culture',
                'url'            => 'https://prohelvetia.ch/fr/feed/',
                'type'           => ScrapingSourceType::RSS,
                'slug'           => 'prohelvetia',
                'discipline'     => 'Toutes disciplines',
                'zone'           => 'Europe (Suisse)',
                'actif'          => true,
                'est_agregateur' => false, // Source directe : publie ses propres bourses suisses
            ],
            [
                'nom'            => 'SAIF - Auteurs arts visuels et image fixe',
                'url'            => 'https://www.saif.fr/fr/bourses-et-prix',
                'type'           => ScrapingSourceType::HtmlCss,
                'slug'           => 'saif',
                'discipline'     => 'Image fixe, Photographie',
                'zone'           => 'France',
                'actif'          => true,
                'est_agregateur' => false, // Source directe : publie ses propres bourses photo
            ],
            [
                'nom'            => 'Musiques Actuelles en France',
                'url'            => 'https://musiquesactuelles.fr/feed/',
                'type'           => ScrapingSourceType::RSS,
                'slug'           => 'musiques-actuelles',
                'discipline'     => 'Musique',
                'zone'           => 'France',
                'actif'          => true,
                'est_agregateur' => false, // Source directe : publie ses propres actualités musique
            ],
            [
                'nom'            => 'ADAGP - Arts graphiques et plastiques',
                'url'            => 'https://www.adagp.fr/fr/actualites',
                'type'           => ScrapingSourceType::HtmlCss,
                'slug'           => 'adagp',
                'discipline'     => 'Arts plastiques, Illustration',
                'zone'           => 'France',
                'actif'          => true,
                'est_agregateur' => false, // Source directe : publie ses propres actualités ADAGP
            ],
            [
                'nom'            => 'Ministère de la Culture',
                'url'            => 'https://www.culture.gouv.fr/Actualites',
                'type'           => ScrapingSourceType::HtmlCss,
                'slug'           => 'culture-gouv',
                'discipline'     => 'Toutes disciplines',
                'zone'           => 'France',
                // DÉSACTIVÉ : page JS/Algolia — le HTML reçu est une coquille vide (~17 Ko).
                // Les actualités sont chargées dynamiquement par JavaScript côté navigateur.
                // Pour réactiver : identifier un flux RSS officiel ou une API culture.gouv.fr.
                'actif'          => false,
                'est_agregateur' => false, // Source directe (même si désactivée)
            ],
            [
                'nom'            => 'On The Move - Mobilité culturelle internationale',
                'url'            => 'https://on-the-move.org/calls',
                'type'           => ScrapingSourceType::HtmlLlm,
                'slug'           => 'on-the-move',
                'discipline'     => 'Toutes disciplines',
                'zone'           => 'Europe',
                'actif'          => true,
                // AGRÉGATEUR : On The Move est un réseau international qui recense des centaines
                // d'organismes partenaires mondiaux (fondations, réseaux culturels, institutions).
                // Sa page /calls liste des appels venant de sources tierces → très riche en organismes
                // potentiellement intéressants à ajouter au système de scraping Bazaart.
                'est_agregateur' => true,
            ],
            [
                'nom'            => "Resartis - Résidences d'artistes",
                'url'            => 'https://www.resartis.org/feed/',
                'type'           => ScrapingSourceType::RSS,
                'slug'           => 'resartis',
                'discipline'     => 'Résidences',
                'zone'           => 'Monde',
                'actif'          => true,
                // AGRÉGATEUR : Resartis est le principal réseau mondial de résidences d'artistes.
                // Il répertorie des centaines d'institutions membres dans le monde entier.
                // Son feed RSS et ses pages contiennent des liens vers des résidences gérées
                // par des organismes tiers → riche en nouvelles sources à découvrir.
                'est_agregateur' => true,
            ],
            [
                'nom'            => 'EACEA - Creative Europe (subventions UE)',
                'url'            => 'https://eacea.ec.europa.eu/grants_en',
                'type'           => ScrapingSourceType::HtmlLlm,
                'slug'           => 'culture-moves-eu',
                'discipline'     => 'Toutes disciplines',
                'zone'           => 'Europe',
                'actif'          => true,
                // AGRÉGATEUR : L'EACEA est l'agence exécutive de la Commission Européenne.
                // Sa page de subventions liste des dizaines de programmes UE avec leurs
                // partenaires institutionnels (Erasmus+, Europe Créative, etc.).
                // Ces partenaires sont souvent des organismes culturels avec leurs propres opps.
                'est_agregateur' => true,
            ],
        ];

        $created = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($sources as $def) {
            // Déduplication par URL — chaque URL doit être unique dans scraping_sources
            //
            // Comportement de --force :
            //   Met à jour nom, url, type, scraperSlug et disciplinePrincipale/paysZone
            //   depuis ce fichier, MAIS ne touche PAS :
            //     - actif    → l'admin peut avoir désactivé une source manuellement
            //     - derniereExecution, statutDernierRun, nbItemsDernierRun → stats de run
            //   Ces champs sont sous la responsabilité de ScrapeOpportunitiesCommand.
            $existing = $this->scrapingSourceRepository->findByUrl($def['url']);

            if ($existing !== null) {
                if ($force) {
                    // En mode --force, on met à jour les métadonnées (label, type, discipline, zone)
                    // SANS toucher aux stats de run ni au champ actif (l'admin peut l'avoir modifié)
                    $existing->setNom($def['nom']);
                    $existing->setType($def['type']);
                    $existing->setScraperSlug($def['slug']);
                    $existing->setDisciplinePrincipale($def['discipline']);
                    $existing->setPaysZone($def['zone']);
                    // estAgregateur est mis à jour car c'est une métadonnée de classification,
                    // pas une donnée admin (l'admin ne la modifie pas manuellement).
                    $existing->setEstAgregateur($def['est_agregateur']);
                    // Note : on ne touche PAS à actif — l'admin peut avoir désactivé manuellement

                    $io->text(sprintf('  <comment>MISE À JOUR</comment> %s (%s)', $def['nom'], $def['url']));
                    $updated++;
                } else {
                    $io->text(sprintf('  <info>EXISTANT</info>   %s → ignoré (--force pour mettre à jour)', $def['nom']));
                    $skipped++;
                }
                continue;
            }

            // Création de la nouvelle source
            $source = new ScrapingSource();
            $source->setNom($def['nom']);
            $source->setUrl($def['url']);
            $source->setType($def['type']);
            $source->setScraperSlug($def['slug']);
            $source->setDisciplinePrincipale($def['discipline']);
            $source->setPaysZone($def['zone']);
            $source->setActif($def['actif']);
            // Classification agrégateur — détermine si app:discover-sources analysera cette source
            $source->setEstAgregateur($def['est_agregateur']);

            $this->em->persist($source);

            $io->text(sprintf(
                '  <info>CRÉÉ</info>       %s (%s, %s)',
                $def['nom'],
                $def['type']->label(),
                $def['actif'] ? 'active' : 'inactive'
            ));
            $created++;
        }

        // Flush unique en fin de boucle — une seule transaction pour tout le batch
        $this->em->flush();

        $io->newLine();
        $io->success(sprintf(
            '%d créée(s) | %d ignorée(s) | %d mise(s) à jour.',
            $created,
            $skipped,
            $updated
        ));

        if ($created > 0 || $updated > 0) {
            $io->note('Visualisez les sources sur /admin/scraping-sources');
        }

        return Command::SUCCESS;
    }
}

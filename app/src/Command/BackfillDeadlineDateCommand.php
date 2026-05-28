<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScrapedResource;
use App\Repository\ScrapedResourceRepository;
use App\Service\DeadlineParserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * BackfillDeadlineDateCommand — Rétro-remplissage de la colonne deadline_date.
 *
 * CONTEXTE :
 *   La colonne deadline_date a été ajoutée par la migration Version20260528000131.
 *   Les enregistrements existants ont deadline_date = NULL.
 *   Cette commande les rétro-remplit en parsant le champ deadline (string)
 *   via DeadlineParserService.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SÉQUENCE DE DÉPLOIEMENT OBLIGATOIRE (à suivre dans cet ordre exact) :
 * ─────────────────────────────────────────────────────────────────────────────
 *   1. php bin/console doctrine:migrations:migrate --no-interaction
 *      → Crée la colonne deadline_date (nullable) en base
 *
 *   2. php bin/console app:backfill-deadline-date
 *      → Rétro-remplit deadline_date pour les enregistrements existants
 *      → (optionnel : tester avec --dry-run d'abord)
 *
 *   3. Vérifier en BDD que les deadlineDate ont bien été remplis :
 *      SELECT COUNT(*) FROM scraped_resources WHERE deadline_date IS NOT NULL;
 *      SELECT COUNT(*) FROM scraped_resources WHERE deadline IS NOT NULL AND deadline != '' AND deadline_date IS NULL;
 *      (la deuxième requête donne les deadlines non parseables — à nettoyer manuellement si besoin)
 *
 *   4. À partir de là, archiveExpired() (DQL) fonctionnera normalement.
 *      Si vous constatez un comportement inattendu, activer archive_use_legacy = 1
 *      dans /admin/settings pour rebrancher l'ancienne méthode string-parsing.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Cette commande est idempotente : si elle est relancée, elle ne touche que les
 * lignes qui ont encore deadline_date = NULL (elle ne réécrase pas les valeurs déjà remplies).
 *
 * Lancement :
 *   docker compose exec app php bin/console app:backfill-deadline-date
 *   docker compose exec app php bin/console app:backfill-deadline-date --dry-run
 */
#[AsCommand(
    name: 'app:backfill-deadline-date',
    description: 'Rétro-remplit la colonne deadline_date depuis le champ deadline (string) pour les enregistrements existants',
)]
class BackfillDeadlineDateCommand extends Command
{
    public function __construct(
        private readonly ScrapedResourceRepository $repository,
        private readonly DeadlineParserService     $deadlineParser,
        private readonly EntityManagerInterface    $em,
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
                'Affiche ce qui serait mis à jour sans écrire en base de données'
            )
            ->setHelp(<<<'HELP'
Cette commande est <info>one-shot</info> : elle ne doit être lancée qu'une seule fois
après la migration qui ajoute la colonne deadline_date.

<comment>SÉQUENCE DE DÉPLOIEMENT OBLIGATOIRE :</comment>

  <info>1.</info> php bin/console doctrine:migrations:migrate --no-interaction
     → Crée la colonne deadline_date (nullable) en base

  <info>2.</info> php bin/console app:backfill-deadline-date
     → Rétro-remplit deadline_date pour les enregistrements existants
     → (optionnel : tester avec --dry-run d'abord)

  <info>3.</info> Vérifier en BDD :
     SELECT COUNT(*) FROM scraped_resources WHERE deadline_date IS NOT NULL;
     SELECT COUNT(*) FROM scraped_resources WHERE deadline IS NOT NULL AND deadline != '' AND deadline_date IS NULL;
     (la 2e requête donne les deadlines non parseables — à nettoyer manuellement si besoin)

  <info>4.</info> À partir de là, archiveExpired() (DQL) fonctionnera normalement.
     En cas de comportement inattendu, activer archive_use_legacy = 1
     dans /admin/settings pour rebrancher l'ancienne méthode string-parsing.

La commande est <info>idempotente</info> : si relancée, elle ne touche que les lignes
avec deadline_date = NULL (ne réécrase pas les valeurs déjà remplies).
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');

        $io->title('Backfill — deadline_date depuis deadline (string)');

        if ($isDryRun) {
            $io->note('Mode DRY-RUN actif : aucune écriture en base de données');
        }

        // ── Chargement des candidats ──────────────────────────────────────────
        // On ne charge QUE les lignes où deadline_date est NULL et deadline est renseigné.
        // Les lignes déjà remplies (deadline_date IS NOT NULL) sont ignorées.
        // Cela rend la commande idempotente.
        $candidates = $this->repository->findForDeadlineBackfill();

        $total = count($candidates);

        if ($total === 0) {
            $io->success('Aucune ligne à traiter (toutes les deadline_date sont déjà remplies).');
            return Command::SUCCESS;
        }

        $io->text(sprintf('<info>%d enregistrement(s) à traiter</info>', $total));
        $io->newLine();

        $filled        = 0; // Lignes avec deadline parsée avec succès
        $notParseable  = 0; // Lignes avec deadline non parseable (logguées)
        $batchSize     = 50; // Flush par lots pour éviter de saturer l'EntityManager

        foreach ($candidates as $i => $resource) {
            $deadlineStr = $resource->getDeadline();

            // deadlineStr ne peut pas être null ici (filtrés par findForDeadlineBackfill)
            // mais on caste en string pour satisfaire le type strict de parse()
            $parsed = $this->deadlineParser->parse((string) $deadlineStr);

            if ($parsed !== null) {
                // Parsing réussi — on remplit deadlineDate
                if (!$isDryRun) {
                    $resource->setDeadlineDate($parsed);
                }
                $filled++;
            } else {
                // Parsing échoué — on logue pour nettoyage manuel éventuel
                $io->writeln(sprintf(
                    '  <comment>[non-parseable]</comment> id=%d deadline="%s"',
                    (int) $resource->getId(),
                    $deadlineStr
                ));
                $notParseable++;
            }

            // Flush par lots : évite une transaction unique trop longue sur les grandes tables.
            // NOTE : em->clear() volontairement ABSENT ici.
            //   em->clear() détacherait toutes les entités déjà chargées dans $candidates,
            //   rendant silencieusement les objets du lot suivant "détachés" (non managed) —
            //   le flush final ne persisterait alors que les 50 premiers items.
            //   La table scraped_resources ne dépassera pas quelques milliers de lignes :
            //   la consommation mémoire reste acceptable sans clear().
            if (!$isDryRun && ($i + 1) % $batchSize === 0) {
                $this->em->flush();
                $io->write('.'); // Indicateur de progression discret
            }
        }

        // Flush final pour le dernier lot (< $batchSize)
        if (!$isDryRun) {
            $this->em->flush();
        }

        $io->newLine(2);

        // ── Résumé ────────────────────────────────────────────────────────────
        $io->table(
            ['Catégorie', 'Nombre'],
            [
                ['Deadlines parsées avec succès' . ($isDryRun ? ' (dry-run)' : ''), $filled],
                ['Deadlines non parseables (voir ci-dessus)', $notParseable],
                ['Total traité', $total],
            ]
        );

        if ($notParseable > 0) {
            $io->note(sprintf(
                '%d deadline(s) non parseable(s) — les id correspondants sont affichés ci-dessus. '
                . 'Ces lignes auront deadline_date = NULL et ne seront PAS archivées automatiquement. '
                . 'Corriger manuellement dans /admin/scraped-opportunities si besoin.',
                $notParseable
            ));
        }

        if ($isDryRun) {
            $io->warning('DRY-RUN : aucune donnée écrite. Relancer sans --dry-run pour appliquer.');
        } else {
            $io->success(sprintf(
                'Backfill terminé : %d deadline_date remplies, %d non parseables.',
                $filled,
                $notParseable
            ));
        }

        return Command::SUCCESS;
    }
}

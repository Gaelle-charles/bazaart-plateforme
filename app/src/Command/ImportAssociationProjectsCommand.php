<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AssociationProject\AssociationProjectImporter;
use App\Service\AssociationProject\AssociationProjectImportEntry;
use App\Service\AssociationProject\AssociationProjectImportReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remplit les fiches « Projets en cours » existantes à partir d'un dossier
 * projets.json + images/ (ADR-0039). Règles et garanties : AssociationProjectImporter.
 *
 * Usage (procédure complète dans docs/projets-association.md) :
 *   php bin/console app:import-association-projects --dry-run   # essai : rien n'est écrit
 *   php bin/console app:import-association-projects             # import réel
 *
 * Le dossier par défaut est var/import (ignoré par git : les contenus ne doivent
 * jamais être commités, le dépôt est public).
 *
 * EN PRODUCTION, la commande refuse de tourner en root : le dossier des images serait
 * créé au nom de root et le back-office (qui tourne sous www-data) ne pourrait plus y
 * enregistrer d'image. Il faut la lancer avec `docker compose exec -u www-data …`.
 *
 * Code de sortie : 0 si tout s'est bien passé, 1 s'il reste une erreur, un titre
 * introuvable ou une fiche non touchée (publiée, exclue, en double).
 */
#[AsCommand(
    name: 'app:import-association-projects',
    description: 'Remplit les champs vides des fiches « Projets en cours » depuis projets.json + images/ (jamais d\'écrasement)',
)]
class ImportAssociationProjectsCommand extends Command
{
    public function __construct(
        private readonly AssociationProjectImporter $importer,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dossier', InputArgument::OPTIONAL, 'Dossier contenant projets.json et images/ (relatif au dossier de l\'application)', 'var/import')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Essai : tout est vérifié (images comprises), rien n\'est enregistré');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($this->environment === 'prod' && $this->effectiveUserId() === 0) {
            $io->error([
                'Cette commande ne doit pas être lancée en root en production.',
                'Sinon, le dossier des images appartiendrait à root et le back-office ne pourrait plus y enregistrer d\'image.',
                'Relancez-la avec « -u www-data » : docker compose … exec -T -u www-data platform_app php bin/console app:import-association-projects',
            ]);

            return Command::FAILURE;
        }

        $io->title($dryRun ? 'Import des fiches projets : ESSAI (rien ne sera enregistré)' : 'Import des fiches projets');

        try {
            $report = $this->importer->import((string) $input->getArgument('dossier'), $dryRun);
        } catch (\Throwable $exception) {
            $io->error([
                'Import annulé : rien n\'a été enregistré (retour arrière en base, images de cette exécution supprimées).',
                sprintf('%s : %s', $exception::class, $exception->getMessage()),
            ]);

            return Command::FAILURE;
        }

        $io->text(sprintf('Dossier : %s', $report->directory));

        foreach ($report->globalErrors as $error) {
            $io->error($error);
        }

        foreach ($report->entries as $entry) {
            $this->renderEntry($io, $entry, $report->written);
        }

        $this->renderTotals($io, $report);

        if ($report->storedImages !== []) {
            $io->text('Images enregistrées dans le dossier privé (à supprimer en cas de retour arrière) :');
            $io->listing($report->storedImages);
        }

        if ($report->written) {
            $report->hasProblems()
                ? $io->warning('Import enregistré, mais certains points sont à vérifier (voir ci-dessus).')
                : $io->success('Import enregistré. Aucune fiche n\'a été publiée : l\'équipe relit et publie elle-même.');
        } elseif ($dryRun) {
            $io->note('Essai (--dry-run) : rien n\'a été enregistré.');
        } else {
            $io->error('Import non lancé : rien n\'a été enregistré. Corrigez les erreurs ci-dessus puis relancez.');
        }

        return $report->hasProblems() ? Command::FAILURE : Command::SUCCESS;
    }

    /** Utilisateur effectif du processus (null si l'extension posix est absente). */
    protected function effectiveUserId(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    private function renderEntry(SymfonyStyle $io, AssociationProjectImportEntry $entry, bool $written): void
    {
        $status = match ($entry->status) {
            AssociationProjectImportEntry::STATUS_UPDATED   => $written ? 'mise à jour' : 'à mettre à jour',
            AssociationProjectImportEntry::STATUS_UNCHANGED => 'rien à remplir',
            AssociationProjectImportEntry::STATUS_NOT_FOUND => 'TITRE INTROUVABLE',
            AssociationProjectImportEntry::STATUS_DUPLICATE => 'NON TOUCHÉE (titre en double)',
            AssociationProjectImportEntry::STATUS_PUBLISHED => 'NON TOUCHÉE (déjà publiée)',
            AssociationProjectImportEntry::STATUS_EXCLUDED  => 'NON TOUCHÉE (exclue)',
            default                                         => 'ENTRÉE INVALIDE',
        };
        $title = $entry->title !== '' ? $entry->title : sprintf('(fiche n° %d du JSON, sans titre)', $entry->index);
        $io->section(sprintf('%s : %s', $title, $status));

        $lines = [
            [$written ? 'Remplis' : 'À remplir', $entry->filled, true],
            ['Déjà remplis, identiques au JSON', $entry->alreadySame, false],
            ['Déjà remplis avec une autre valeur, NON écrasés', $entry->alreadyDifferent, false],
            ['Vidés depuis un import précédent, non re-remplis', $entry->notRefilled, false],
            ['Vides ou null dans le JSON, non touchés', $entry->nullInJson, false],
            ['Type ou état différents du JSON, non modifiés (à arbitrer)', $entry->typeStateDifferences, false],
        ];
        foreach ($lines as [$label, $fields, $always]) {
            if ($fields === [] && !$always) {
                continue;
            }
            $io->text(sprintf('%s (%d)%s', $label, count($fields), $fields === [] ? '' : ' : ' . implode(', ', $fields)));
        }
        foreach ($entry->errors as $error) {
            $io->text('<error> ! </error> ' . $error);
        }
    }

    private function renderTotals(SymfonyStyle $io, AssociationProjectImportReport $report): void
    {
        $written = $report->written;
        $io->section('Totaux');
        $io->table(['Compteur', 'Nombre'], [
            ['Fiches dans le JSON', count($report->entries)],
            [$written ? 'Fiches mises à jour' : 'Fiches à mettre à jour', $report->countStatus(AssociationProjectImportEntry::STATUS_UPDATED)],
            ['Fiches sans rien à remplir', $report->countStatus(AssociationProjectImportEntry::STATUS_UNCHANGED)],
            ['Titres introuvables (rien créé)', $report->countStatus(AssociationProjectImportEntry::STATUS_NOT_FOUND)],
            ['Fiches non touchées (publiées, exclues, en double, invalides)', $report->countStatus(
                AssociationProjectImportEntry::STATUS_PUBLISHED,
                AssociationProjectImportEntry::STATUS_EXCLUDED,
                AssociationProjectImportEntry::STATUS_DUPLICATE,
                AssociationProjectImportEntry::STATUS_INVALID,
            )],
            [$written ? 'Champs remplis (visuel compris)' : 'Champs à remplir (visuel compris)', $report->sum('filled')],
            ['Champs ignorés car déjà remplis', $report->sum('alreadySame') + $report->sum('alreadyDifferent')],
            ['  dont identiques au JSON', $report->sum('alreadySame')],
            ['  dont avec une autre valeur (non écrasés)', $report->sum('alreadyDifferent')],
            ['Champs vidés depuis un import, non re-remplis', $report->sum('notRefilled')],
            ['Champs vides ou null dans le JSON', $report->sum('nullInJson')],
            ['Type ou état différents du JSON (non modifiés)', $report->sum('typeStateDifferences')],
            [$written ? 'Visuels envoyés' : 'Visuels à envoyer (contrôles passés)', $report->imagesSent()],
            ['Erreurs', $report->errorCount()],
        ]);
    }
}

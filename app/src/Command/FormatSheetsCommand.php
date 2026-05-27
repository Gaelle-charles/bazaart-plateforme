<?php

namespace App\Command;

use App\Service\GoogleSheetsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * FormatSheetsCommand — Applique la mise en forme sur le Google Sheet existant.
 *
 * À utiliser une seule fois (ou après réinitialisation du Sheet) pour :
 *   - Mettre les en-têtes en gras
 *   - Ajouter le menu déroulant sur la colonne "Statut"
 *
 * Lancement :
 *   docker compose exec app php bin/console app:sheets-format
 *
 * @deprecated Commande Google Sheets abandonnée — le scraping écrit désormais directement en BDD.
 *   Cette commande est conservée pour compatibilité mais ne devrait plus être lancée.
 *   À supprimer en V2 après vérification des données historiques.
 *   Référence : tâche "Abandon Google Sheets" du 25 mai 2026.
 */
#[AsCommand(
    name: 'app:sheets-format',
    description: 'Applique la mise en forme (gras + dropdown Statut) sur le Google Sheet existant',
)]
class FormatSheetsCommand extends Command
{
    public function __construct(
        private readonly GoogleSheetsService $googleSheetsService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BazaArt — Formatage du Google Sheet');

        try {
            $this->googleSheetsService->formatExistingSheet();
            $io->success('Mise en forme appliquée : en-têtes en gras + dropdown Statut sur la colonne K.');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

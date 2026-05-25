<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LiveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SendLiveRemindersCommand — Envoi des rappels email 24h avant les lives planifiés.
 *
 * Cette commande est conçue pour être exécutée toutes les heures via un cron.
 * Elle trouve les lives SCHEDULED qui démarrent dans les 24 prochaines heures
 * et envoie un email à tous les inscrits qui n'ont pas encore été notifiés.
 *
 * Usage :
 *   docker compose exec app php bin/console app:live:send-reminders
 *   docker compose exec app php bin/console app:live:send-reminders --dry-run
 *
 * Cron suggéré (toutes les heures) :
 *   0 * * * * docker compose exec -T app php bin/console app:live:send-reminders
 *
 * Idempotence :
 *   Le flag `reminderSent` sur chaque LiveAttendee garantit qu'un rappel
 *   n'est envoyé qu'une seule fois, même si la commande tourne plusieurs fois
 *   dans la fenêtre de 24h (ex : toutes les heures).
 *
 * Logs :
 *   Les erreurs d'envoi individuelles sont loguées dans LiveService sans bloquer
 *   l'exécution. La commande retourne toujours SUCCESS sauf configuration
 *   complètement cassée.
 */
#[AsCommand(
    name: 'app:live:send-reminders',
    description: 'Envoie les rappels email 24h avant les lives planifiés aux utilisateurs inscrits',
)]
class SendLiveRemindersCommand extends Command
{
    public function __construct(
        private readonly LiveService $liveService,
    ) {
        // Appel obligatoire du constructeur parent pour l'enregistrement Symfony
        parent::__construct();
    }

    /**
     * Déclare les options disponibles en ligne de commande.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simule l\'envoi sans réellement envoyer les emails (utile pour tester)'
            );
    }

    /**
     * Point d'entrée principal de la commande.
     *
     * En mode dry-run : affiche ce qui serait fait sans envoyer d'email.
     * En mode normal  : délègue à LiveService::sendReminders() et affiche le résumé.
     *
     * Retourne Command::SUCCESS dans tous les cas sauf erreur système grave.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle fournit une interface console enrichie (titres, listes, tableaux)
        $io = new SymfonyStyle($input, $output);

        $isDryRun = (bool) $input->getOption('dry-run');

        $io->title('Bazaart — Rappels email pour les lives planifiés');

        if ($isDryRun) {
            // En dry-run, on affiche un message informatif et on sort sans rien faire.
            // Note : pour un vrai dry-run avec liste des inscrits, il faudrait
            // exposer une méthode LiveService::dryRunReminders() qui retourne les
            // inscrits sans envoyer. En V1, le dry-run est une simple simulation.
            $io->note('[DRY-RUN] Mode simulation activé — aucun email ne sera envoyé.');
            $io->warning(
                'Le dry-run complet (avec liste des destinataires) sera implémenté en V2. '
                . 'Pour tester, utiliser Mailpit (http://localhost:8025).'
            );
            $io->success('[DRY-RUN] Terminé sans envoi.');
            return Command::SUCCESS;
        }

        // ── Envoi réel ────────────────────────────────────────────────────────
        $io->text('Recherche des lives démarrant dans les 24 prochaines heures...');

        try {
            // LiveService::sendReminders() retourne le nombre d'emails envoyés
            $sentCount = $this->liveService->sendReminders();

            $io->newLine();

            if ($sentCount === 0) {
                $io->info('Aucun rappel à envoyer (aucun live dans les 24h ou tous les inscrits déjà notifiés).');
            } else {
                $io->success(sprintf('%d rappel(s) email envoyé(s) avec succès.', $sentCount));
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            // Erreur système grave (connexion BDD, Mailer complètement HS, etc.)
            // Les erreurs individuelles d'envoi sont absorbées dans LiveService.
            $io->error(sprintf('Erreur système lors de l\'envoi des rappels : %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}

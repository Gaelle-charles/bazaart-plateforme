<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\AlertFrequency;
use App\Repository\ResourceAlertRepository;
use App\Service\ResourceAlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SendResourceAlertsCommand — Job d'envoi des alertes email de la Ressourcerie.
 *
 * Cette command parcourt tous les profils d'alertes actifs (notifyOnNewResource = true)
 * et envoie un email personnalisé à chaque utilisateur dont les préférences correspondent
 * à au moins une ressource publiée dans la fenêtre temporelle de sa fréquence.
 *
 * Règles de fréquence V1 :
 *   - immediate → fenêtre 24h (le cron est quotidien, pas temps-réel en V1)
 *   - daily     → fenêtre 24h
 *   - weekly    → fenêtre 7 jours — envoyé UNIQUEMENT le lundi
 *                 (sauf avec --force-weekly)
 *
 * Usage manuel :
 *   docker compose exec app php bin/console app:send-resource-alerts
 *   docker compose exec app php bin/console app:send-resource-alerts --dry-run
 *   docker compose exec app php bin/console app:send-resource-alerts --force-weekly
 *
 * Automatisation (cron quotidien 8h) :
 *   0 8 * * * docker compose exec -T app php bin/console app:send-resource-alerts
 *
 * Le lundi, la command traitera automatiquement les alertes weekly en plus de daily/immediate.
 * Les autres jours, seules daily et immediate sont traitées.
 */
#[AsCommand(
    name: 'app:send-resource-alerts',
    description: 'Envoie les alertes email aux membres dont les préférences correspondent à de nouvelles ressources',
)]
class SendResourceAlertsCommand extends Command
{
    public function __construct(
        private readonly ResourceAlertRepository $alertRepository,
        private readonly ResourceAlertService $alertService,
    ) {
        // Important : appeler le constructeur parent pour que Symfony enregistre la command
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
                'Affiche ce qui serait envoyé sans réellement envoyer les emails (mode test)'
            )
            ->addOption(
                'force-weekly',
                null,
                InputOption::VALUE_NONE,
                'Force le traitement des alertes weekly même si aujourd\'hui n\'est pas lundi (utile pour les tests)'
            );
    }

    /**
     * Point d'entrée principal de la command.
     *
     * Retourne Command::SUCCESS si tout s'est bien passé (même s'il n'y avait rien à envoyer).
     * Retourne Command::FAILURE uniquement si TOUTES les tentatives d'envoi ont échoué.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle = interface console enrichie (titres, tableaux, progress bar, etc.)
        $io = new SymfonyStyle($input, $output);

        // Lecture des options CLI
        $isDryRun     = (bool) $input->getOption('dry-run');
        $forceWeekly  = (bool) $input->getOption('force-weekly');

        $io->title('Bazaart — Envoi des alertes email Ressourcerie');

        if ($isDryRun) {
            $io->note('[DRY-RUN] Mode simulation : aucun email ne sera envoyé.');
        }

        // ── Déterminer si aujourd'hui est lundi ──────────────────────────────
        // On utilise Europe/Paris explicitement pour éviter les décalages UTC
        // sur le serveur Docker (qui tourne souvent en UTC). Sans ça, un cron
        // à 8h Paris (= 6h ou 7h UTC) pourrait tomber le "mauvais" jour.
        // date('N') : 1 = lundi, 7 = dimanche (format ISO-8601)
        $nowParis = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $isMonday = $nowParis->format('N') === '1';

        if ($forceWeekly && !$isMonday) {
            $io->note('[FORCE-WEEKLY] Traitement des alertes hebdomadaires forcé (pas lundi aujourd\'hui).');
        }

        // ── Récupération des profils d'alertes actifs ───────────────────────
        // findAllActive() charge déjà user, filterDisciplines et filterResourceTypes
        // en une seule requête SQL (évite N+1 dans la boucle ci-dessous)
        $alerts = $this->alertRepository->findAllActive();

        if (empty($alerts)) {
            $io->warning('Aucun profil d\'alerte actif trouvé en base de données.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('<info>%d profil(s) d\'alerte actif(s) trouvé(s)</info>', count($alerts)));
        $io->newLine();

        // ── Tri des alertes par fréquence (immediate → daily → weekly) ─────────
        // Le repository ne trie pas en BDD (CASE WHEN non valide en DQL pur).
        // On trie côté PHP : c'est plus sûr et le volume d'alertes en V1 reste faible.
        $frequencyOrder = [
            AlertFrequency::Immediate->value => 1,
            AlertFrequency::Daily->value     => 2,
            AlertFrequency::Weekly->value    => 3,
        ];
        usort($alerts, function ($a, $b) use ($frequencyOrder): int {
            return ($frequencyOrder[$a->getFrequency()->value] ?? 99)
                <=> ($frequencyOrder[$b->getFrequency()->value] ?? 99);
        });

        // ── Compteurs pour le résumé final ───────────────────────────────────
        $countSent    = 0;  // emails réellement envoyés (hors dry-run)
        $countDryRun  = 0;  // emails simulés en dry-run (compteur séparé pour la clarté)
        $countSkipped = 0;  // alertes ignorées (weekly hors lundi, ou aucune ressource)
        $countErrors  = 0;  // tentatives d'envoi ayant échoué (erreur SMTP/transport)

        // ── Boucle principale ────────────────────────────────────────────────
        foreach ($alerts as $alert) {
            $userEmail = $alert->getUser()->getEmail();
            $frequency = $alert->getFrequency();

            // Règle weekly : traiter uniquement le lundi (sauf --force-weekly)
            if ($frequency === AlertFrequency::Weekly && !$isMonday && !$forceWeekly) {
                // En mode verbose (-v), on affiche les skips pour le debugging
                $io->writeln(
                    sprintf('  <comment>[SKIP-WEEKLY]</comment> %s (weekly, pas lundi)', $userEmail),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                $countSkipped++;
                continue;
            }

            // Trouve les ressources correspondant aux préférences de cet utilisateur
            $resources = $this->alertService->findMatchingResources($alert);

            if (empty($resources)) {
                // Aucune nouvelle ressource pour cet utilisateur → pas d'email
                $io->writeln(
                    sprintf('  <comment>[SKIP-EMPTY]</comment> %s (0 ressource correspondante)', $userEmail),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                $countSkipped++;
                continue;
            }

            // En mode dry-run : affiche ce qui serait envoyé sans l'envoyer
            if ($isDryRun) {
                $io->writeln(sprintf(
                    '  <info>[DRY-RUN]</info> %s → %d ressource(s) [fréquence: %s]',
                    $userEmail,
                    count($resources),
                    $frequency->value
                ));

                // Détail des ressources en verbose (-v)
                foreach ($resources as $resource) {
                    $io->writeln(
                        sprintf('    - #%d : %s', $resource->getId(), $resource->getTitle()),
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                }

                $countDryRun++;
                continue;
            }

            // ── Envoi réel de l'email ────────────────────────────────────────
            // sendAlertEmail retourne true si succès, false si erreur transport
            $success = $this->alertService->sendAlertEmail($alert, $resources);

            if ($success) {
                $io->writeln(sprintf(
                    '  <info>[OK]</info> %s → %d ressource(s) envoyée(s)',
                    $userEmail,
                    count($resources)
                ));
                $countSent++;
            } else {
                $io->writeln(sprintf(
                    '  <error>[ERREUR]</error> %s → échec de l\'envoi (voir les logs)',
                    $userEmail
                ));
                $countErrors++;
            }
        }

        // ── Résumé final ─────────────────────────────────────────────────────
        $io->newLine();
        $io->section('Résumé');

        $summaryLines = $isDryRun
            ? [
                sprintf('Emails simulés : <info>%d</info>', $countDryRun),
                sprintf('Ignorés        : <comment>%d</comment>', $countSkipped),
            ]
            : [
                sprintf('Emails envoyés : <info>%d</info>', $countSent),
                sprintf('Ignorés        : <comment>%d</comment>', $countSkipped),
                sprintf('Erreurs        : <error>%d</error>', $countErrors),
            ];
        $io->listing($summaryLines);

        // En dry-run : toujours SUCCESS (on ne peut pas vraiment "échouer")
        if ($isDryRun) {
            $io->success(sprintf('[DRY-RUN] %d email(s) auraient été envoyés.', $countDryRun));
            return Command::SUCCESS;
        }

        // Cas d'échec total : TOUTES les tentatives ont échoué
        if ($countErrors > 0 && $countSent === 0) {
            $io->error('Toutes les tentatives d\'envoi ont échoué. Vérifier la configuration SMTP et les logs.');
            return Command::FAILURE;
        }

        if ($countErrors > 0) {
            // Succès partiel : certains ont échoué, mais d'autres ont été envoyés
            $io->warning(sprintf(
                'Terminé avec %d erreur(s). Les emails concernés n\'ont pas été envoyés.',
                $countErrors
            ));
            // On retourne SUCCESS car le job a partiellement fonctionné
            return Command::SUCCESS;
        }

        $io->success(sprintf('Job terminé. %d email(s) envoyé(s).', $countSent));

        return Command::SUCCESS;
    }
}

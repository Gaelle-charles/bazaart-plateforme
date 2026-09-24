<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ProjectTaskRepository;
use App\Service\Project\ProjectClock;
use App\Service\Project\ProjectMemberService;
use App\Service\Project\ProjectNotifier;
use App\Service\Project\ProjectTaskService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * app:projets:rappels — récap quotidien par email des tâches de chaque membre (ADR-0037).
 *
 * Chaque membre reçoit (si elle n'a pas désactivé les emails) la liste de SES tâches :
 *   - en retard ;
 *   - dues aujourd'hui ;
 *   - dues dans les 3 prochains jours.
 * Personne n'est dérangé si sa liste est vide.
 *
 * À lancer une fois par jour par le cron du serveur (cf. docs/espace-projets.md), ex. :
 *   0 11 * * 1-5 /usr/bin/docker exec bazaart_platform_app php bin/console app:projets:rappels --env=prod
 *   (11h UTC = 7h en Guadeloupe, du lundi au vendredi)
 *
 * --dry-run : affiche ce qui serait envoyé, sans rien envoyer.
 */
#[AsCommand(
    name: 'app:projets:rappels',
    description: 'Envoie à chaque membre de l\'Espace projets le récap de ses tâches en retard et à venir.',
)]
class ProjectDigestCommand extends Command
{
    public function __construct(
        private readonly ProjectMemberService $memberService,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectTaskService $taskService,
        private readonly ProjectNotifier $notifier,
        private readonly ProjectClock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche le récap sans envoyer d\'email.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $today  = $this->clock->today();
        $limit  = $today->modify('+3 days');
        $sent   = 0;

        foreach ($this->memberService->getMembers() as $member) {
            $groups = ['overdue' => [], 'today' => [], 'soon' => []];
            foreach ($this->taskService->sort($this->taskRepository->findOpenAssignedTo($member), 'due', 'asc') as $task) {
                $due = $task->getDueDate();
                if ($due === null || $due > $limit) {
                    continue;
                }
                $groups[match (true) {
                    $due < $today  => 'overdue',
                    $due == $today => 'today',
                    default        => 'soon',
                }][] = $task;
            }

            $summary = sprintf(
                '%s : %d en retard, %d aujourd\'hui, %d bientôt',
                ProjectMemberService::displayName($member),
                count($groups['overdue']),
                count($groups['today']),
                count($groups['soon']),
            );

            if ($dryRun) {
                $io->text('[simulation] ' . $summary);
                continue;
            }

            if ($this->notifier->sendDigest($member, $groups)) {
                ++$sent;
                $io->text('Envoyé à ' . $summary);
            }
        }

        $io->success($dryRun ? 'Simulation terminée (aucun email envoyé).' : sprintf('%d récap(s) envoyé(s).', $sent));

        return Command::SUCCESS;
    }
}

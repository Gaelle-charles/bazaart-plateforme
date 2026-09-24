<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\DTO\Project\ProjectData;
use App\Entity\Project;
use App\Entity\ProjectActivity;
use App\Entity\ProjectSubtask;
use App\Entity\ProjectTask;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectTaskStatus;
use App\Exception\GoogleDriveException;
use App\Repository\ProjectTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ProjectService — logique métier des projets de l'Espace projets (ADR-0037).
 *
 * Création (avec modèle de tâches), modification, changement de statut,
 * suppression, dossier Drive, statistiques d'avancement et chronologie.
 * Chaque action significative laisse une trace dans le journal d'activité.
 */
class ProjectService
{
    /**
     * Palette fermée des couleurs de projets et d'étiquettes.
     * Fermée = on ne peut pas enregistrer une couleur arbitraire (pas d'injection
     * CSS possible via le style="background: …" des pastilles).
     */
    public const array COLORS = [
        '#FFCB10', // jaune Bazaart (accent)
        '#FF6B2C', // orange tangerine (accent 2)
        '#E5484D', // rouge
        '#EC4899', // rose
        '#8B5CF6', // violet
        '#3B82F6', // bleu
        '#0EA5A4', // turquoise
        '#1F8A5B', // vert
        '#84CC16', // vert anis
        '#5B584F', // gris chaud
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectMemberService $memberService,
        private readonly ProjectActivityLogger $activityLogger,
        private readonly ProjectTemplateCatalog $templateCatalog,
        private readonly GoogleDriveService $driveService,
    ) {}

    /**
     * Crée un projet, et ses tâches si un modèle est choisi.
     *
     * Les données doivent avoir été validées au préalable ($data->validate() === []).
     */
    public function create(ProjectData $data, User $actor): Project
    {
        $project = new Project();
        $project->setCreatedBy($actor);
        $this->apply($project, $data);
        $this->em->persist($project);

        $message = sprintf('a créé le projet %s', ProjectActivityLogger::quote($project->getName()));

        // ── Modèle : génération des tâches ───────────────────────────────────
        if ($data->templateKey !== null && $this->templateCatalog->has($data->templateKey)) {
            $position = $this->taskRepository->nextPosition(ProjectTaskStatus::Todo);
            foreach ($this->templateCatalog->buildTasks($data->templateKey, $project->getDueDate()) as $template) {
                $task = (new ProjectTask())
                    ->setProject($project)
                    ->setTitle($template['title'])
                    ->setPriority($template['priority'])
                    ->setDueDate($template['dueDate'])
                    ->setPosition($position++)
                    ->setCreatedBy($actor);
                // Les tâches du modèle sont confiées à la responsable du projet : elles
                // apparaissent tout de suite dans SES tâches, puis l'équipe se les répartit
                // (vue « Par personne », glisser-déposer). Pas d'email ici : une dizaine
                // de notifications d'un coup serait du bruit.
                if ($project->getOwner() !== null) {
                    $task->addAssignee($project->getOwner());
                }
                foreach ($template['subtasks'] as $i => $title) {
                    $task->addSubtask((new ProjectSubtask())->setTitle($title)->setPosition($i));
                }
                $this->em->persist($task);
            }
            $message .= sprintf(' (modèle %s)', $this->templateCatalog->label($data->templateKey));
        }

        $this->activityLogger->log(ProjectActivity::PROJECT_CREATED, $message, $actor, $project);
        $this->em->flush();

        return $project;
    }

    /** Met à jour un projet existant depuis le formulaire d'édition. */
    public function update(Project $project, ProjectData $data, User $actor): void
    {
        $previousStatus = $project->getStatus();
        $this->apply($project, $data);

        $message = $previousStatus !== $project->getStatus()
            ? sprintf('a passé le projet %s en « %s »', ProjectActivityLogger::quote($project->getName()), $project->getStatus()->label())
            : sprintf('a modifié le projet %s', ProjectActivityLogger::quote($project->getName()));

        $this->activityLogger->log(ProjectActivity::PROJECT_UPDATED, $message, $actor, $project);
        $this->em->flush();
    }

    /** Changement rapide de statut (bouton « Archiver », « Marquer terminé »…). */
    public function changeStatus(Project $project, ProjectStatus $status, User $actor): void
    {
        if ($project->getStatus() === $status) {
            return;
        }

        $project->setStatus($status);
        $this->activityLogger->log(
            ProjectActivity::PROJECT_UPDATED,
            sprintf('a passé le projet %s en « %s »', ProjectActivityLogger::quote($project->getName()), $status->label()),
            $actor,
            $project,
        );
        $this->em->flush();
    }

    /**
     * Suppression DÉFINITIVE : tâches, pièces jointes et activités du projet
     * disparaissent (ON DELETE CASCADE). Les notes rattachées restent sur le mur.
     * Les fichiers du Drive ne sont PAS touchés (on ne supprime que des références).
     */
    public function delete(Project $project, User $actor): void
    {
        $name = $project->getName();
        $this->em->remove($project);
        // Trace globale (sans projet, puisqu'il n'existe plus).
        $this->activityLogger->log(ProjectActivity::PROJECT_UPDATED, sprintf('a supprimé le projet %s', ProjectActivityLogger::quote($name)), $actor);
        $this->em->flush();
    }

    /** Rattache un dossier Drive existant (choisi dans le sélecteur) au projet. */
    public function linkDriveFolder(Project $project, string $folderId, User $actor): void
    {
        $folder = $this->driveService->getFile($folderId);
        if ($folder['isFolder'] !== true) {
            throw new GoogleDriveException('Choisis un dossier (et non un fichier) comme dossier du projet.');
        }

        $project->setDriveFolder((string) $folder['id'], (string) $folder['name']);
        $this->activityLogger->log(
            ProjectActivity::PROJECT_UPDATED,
            sprintf('a rattaché le dossier Drive %s au projet', ProjectActivityLogger::quote((string) $folder['name'])),
            $actor,
            $project,
        );
        $this->em->flush();
    }

    public function unlinkDriveFolder(Project $project): void
    {
        $project->setDriveFolder(null, null);
        $this->em->flush();
    }

    /**
     * Crée un dossier « [Projet] Nom » dans le Drive et le rattache au projet.
     *
     * @return string|null message d'erreur (affiché en flash), ou null si tout s'est bien passé
     */
    public function createDriveFolderFor(Project $project, User $actor): ?string
    {
        try {
            $folder = $this->driveService->createFolder($project->getName());
        } catch (GoogleDriveException $e) {
            return 'Le projet est créé, mais le dossier Drive n\'a pas pu l\'être : ' . $e->getMessage();
        }

        $project->setDriveFolder((string) $folder['id'], (string) $folder['name']);
        $this->activityLogger->log(
            ProjectActivity::PROJECT_UPDATED,
            sprintf('a créé le dossier Drive %s', ProjectActivityLogger::quote((string) $folder['name'])),
            $actor,
            $project,
        );
        $this->em->flush();

        return null;
    }

    /**
     * Avancement de chaque projet, avec pourcentage calculé.
     *
     * @return array<int, array{total: int, done: int, overdue: int, percent: int}>
     */
    public function getStats(\DateTimeImmutable $today): array
    {
        $stats = [];
        foreach ($this->taskRepository->countStatsByProject($today) as $projectId => $row) {
            $row['percent']     = $row['total'] > 0 ? (int) round($row['done'] * 100 / $row['total']) : 0;
            $stats[$projectId]  = $row;
        }

        return $stats;
    }

    /**
     * Données de la vue « Chronologie » (diagramme de Gantt simplifié).
     *
     * La fenêtre affichée fait 6 mois, à partir du mois précédant $from.
     * Chaque projet daté devient une barre positionnée en POURCENTAGES (left/width)
     * de la largeur totale : le CSS n'a plus qu'à appliquer ces valeurs.
     *
     * @param list<Project> $projects
     *
     * @return array{
     *     start: \DateTimeImmutable,
     *     end: \DateTimeImmutable,
     *     months: list<array{label: string, left: float, width: float}>,
     *     rows: list<array{project: Project, left: float, width: float, clippedStart: bool, clippedEnd: bool}>,
     *     todayLeft: float|null,
     *     undated: list<Project>,
     *     outside: list<Project>
     * }
     */
    public function buildTimeline(array $projects, \DateTimeImmutable $from, \DateTimeImmutable $today): array
    {
        $start     = $from->modify('first day of this month')->setTime(0, 0);
        $end       = $start->modify('+6 months')->modify('-1 day');
        $totalDays = (int) $start->diff($end)->days + 1;

        // En-têtes de mois
        $months = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $monthEnd = $cursor->modify('last day of this month');
            $months[] = [
                'label' => ProjectDateFormatter::monthLabel($cursor),
                'left'  => round(((int) $start->diff($cursor)->days) * 100 / $totalDays, 3),
                'width' => round(((int) $cursor->diff($monthEnd)->days + 1) * 100 / $totalDays, 3),
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        $rows = $undated = $outside = [];
        foreach ($projects as $project) {
            $projectStart = $project->getStartDate() ?? $project->getDueDate();
            $projectEnd   = $project->getDueDate() ?? $project->getStartDate();
            if ($projectStart === null || $projectEnd === null) {
                $undated[] = $project;
                continue;
            }
            if ($projectEnd < $start || $projectStart > $end) {
                $outside[] = $project;
                continue;
            }

            $visibleStart = max($projectStart, $start);
            $visibleEnd   = min($projectEnd, $end);
            $rows[] = [
                'project'      => $project,
                'left'         => round(((int) $start->diff($visibleStart)->days) * 100 / $totalDays, 3),
                // Au moins 1 jour de large, pour qu'un projet d'une journée reste visible.
                'width'        => round(max(1, (int) $visibleStart->diff($visibleEnd)->days + 1) * 100 / $totalDays, 3),
                'clippedStart' => $projectStart < $start,
                'clippedEnd'   => $projectEnd > $end,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['left'] <=> $b['left']);

        $todayLeft = ($today >= $start && $today <= $end)
            ? round(((int) $start->diff($today)->days + 0.5) * 100 / $totalDays, 3)
            : null;

        return [
            'start'     => $start,
            'end'       => $end,
            'months'    => $months,
            'rows'      => $rows,
            'todayLeft' => $todayLeft,
            'undated'   => $undated,
            'outside'   => $outside,
        ];
    }

    /**
     * Trie les projets pour la page « Projets » : en cours d'abord, puis à venir,
     * en pause, terminés, archivés ; à statut égal, l'échéance la plus proche.
     *
     * @param list<Project> $projects
     *
     * @return list<Project>
     */
    public function sortForDisplay(array $projects): array
    {
        $order = [
            ProjectStatus::Active->value    => 0,
            ProjectStatus::Planned->value   => 1,
            ProjectStatus::OnHold->value    => 2,
            ProjectStatus::Completed->value => 3,
            ProjectStatus::Archived->value  => 4,
        ];

        usort($projects, static function (Project $a, Project $b) use ($order): int {
            return [$order[$a->getStatus()->value], $a->getDueDate() === null, $a->getDueDate(), mb_strtolower($a->getName())]
                <=> [$order[$b->getStatus()->value], $b->getDueDate() === null, $b->getDueDate(), mb_strtolower($b->getName())];
        });

        return $projects;
    }

    /** Copie les données validées du DTO dans l'entité. */
    private function apply(Project $project, ProjectData $data): void
    {
        $project
            ->setName($data->name)
            ->setDescription($data->description)
            ->setColor($data->color)
            ->setStatus($data->status)
            ->setPriority($data->priority)
            ->setStartDate($data->startDate)
            ->setDueDate($data->dueDate)
            // Seule une membre de l'équipe peut être responsable : un ID arbitraire
            // envoyé dans le formulaire est ignoré (findMember renvoie null).
            ->setOwner($data->ownerId !== null ? $this->memberService->findMember($data->ownerId) : null);
    }
}

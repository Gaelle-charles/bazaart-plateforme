<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\DTO\Project\ProjectTaskData;
use App\Entity\ProjectActivity;
use App\Entity\ProjectLabel;
use App\Entity\ProjectSubtask;
use App\Entity\ProjectTask;
use App\Entity\ProjectTaskComment;
use App\Entity\User;
use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use App\Repository\ProjectLabelRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectSubtaskRepository;
use App\Repository\ProjectTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ProjectTaskService — logique métier des tâches de l'Espace projets (ADR-0037).
 *
 * Création / modification / déplacement (glisser-déposer) / suppression,
 * sous-tâches, commentaires, tri et regroupement pour les différentes vues.
 *
 * Toute modification passe par ici pour garantir que :
 *   - seules des membres de l'équipe peuvent être assignées ;
 *   - le journal d'activité est tenu à jour ;
 *   - les personnes nouvellement assignées sont prévenues.
 */
class ProjectTaskService
{
    /** Nombre maximum de tâches « Terminé » affichées dans la colonne Kanban. */
    public const int DONE_COLUMN_LIMIT = 30;

    /** Champs modifiables par glisser-déposer (vues Kanban, Par personne, Par priorité, Calendrier). */
    public const array MOVABLE_FIELDS = ['status', 'priority', 'assignee', 'dueDate'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectLabelRepository $labelRepository,
        private readonly ProjectSubtaskRepository $subtaskRepository,
        private readonly ProjectMemberService $memberService,
        private readonly ProjectActivityLogger $activityLogger,
        private readonly ProjectNotifier $notifier,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // Création / modification
    // ═════════════════════════════════════════════════════════════════════════

    /** Crée une tâche. $data doit avoir été validé ($data->validate() === []). */
    public function create(ProjectTaskData $data, User $actor): ProjectTask
    {
        $task = (new ProjectTask())
            ->setCreatedBy($actor)
            ->setPosition($this->taskRepository->nextPosition($data->status));
        $this->applyData($task, $data);
        $added = $this->syncAssignees($task, $data->assigneeIds);

        $this->em->persist($task);
        $this->activityLogger->log(
            ProjectActivity::TASK_CREATED,
            sprintf('a créé la tâche %s', ProjectActivityLogger::quote($task->getTitle())),
            $actor,
            task: $task,
        );
        if ($added !== []) {
            $this->logAssignment($task, $added, $actor);
        }
        $this->em->flush();

        foreach ($added as $user) {
            $this->notifier->notifyAssigned($task, $user, $actor);
        }

        return $task;
    }

    /** Enregistre le formulaire complet de la fiche tâche. */
    public function update(ProjectTask $task, ProjectTaskData $data, User $actor): void
    {
        $previousStatus = $task->getStatus();
        $this->applyData($task, $data);
        $added = $this->syncAssignees($task, $data->assigneeIds);

        if ($previousStatus !== $task->getStatus()) {
            $this->logStatusChange($task, $actor);
        } else {
            $this->activityLogger->log(
                ProjectActivity::TASK_UPDATED,
                sprintf('a modifié la tâche %s', ProjectActivityLogger::quote($task->getTitle())),
                $actor,
                task: $task,
            );
        }
        if ($added !== []) {
            $this->logAssignment($task, $added, $actor);
        }
        $this->em->flush();

        foreach ($added as $user) {
            $this->notifier->notifyAssigned($task, $user, $actor);
        }
    }

    /**
     * Glisser-déposer : change UN champ de la tâche selon la colonne de destination.
     *
     * @param string    $field      'status' | 'priority' | 'assignee' | 'dueDate'
     * @param string    $value      valeur de la colonne cible (ex. 'in_progress', '12', '2026-10-03', 'none')
     * @param int|null  $fromUserId vue « Par personne » : colonne d'ORIGINE (la personne à retirer)
     * @param list<int> $orderedIds vue Kanban : ordre complet des cartes de la colonne cible
     *
     * @throws \InvalidArgumentException si la valeur ne correspond à rien de connu
     */
    public function move(ProjectTask $task, string $field, string $value, ?int $fromUserId, array $orderedIds, User $actor): void
    {
        $title = ProjectActivityLogger::quote($task->getTitle());

        switch ($field) {
            case 'status':
                $status = ProjectTaskStatus::tryFrom($value) ?? throw new \InvalidArgumentException('Statut inconnu.');
                if ($status !== $task->getStatus()) {
                    $task->setStatus($status);
                    $this->logStatusChange($task, $actor);
                }
                // Réordonne la colonne d'arrivée (0, 1, 2…) selon l'ordre envoyé par le front.
                $this->reorder($orderedIds);
                break;

            case 'priority':
                $priority = ProjectTaskPriority::tryFrom($value) ?? throw new \InvalidArgumentException('Priorité inconnue.');
                if ($priority !== $task->getPriority()) {
                    $task->setPriority($priority);
                    $this->activityLogger->log(
                        ProjectActivity::TASK_UPDATED,
                        sprintf('a passé %s en priorité %s', $title, $priority->label()),
                        $actor,
                        task: $task,
                    );
                }
                break;

            case 'assignee':
                $this->moveAssignee($task, $value, $fromUserId, $actor);
                break;

            case 'dueDate':
                $due = null;
                if ($value !== 'none') {
                    $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if ($due === false || $due->format('Y-m-d') !== $value) {
                        throw new \InvalidArgumentException('Date invalide.');
                    }
                }
                $task->setDueDate($due);
                $this->activityLogger->log(
                    ProjectActivity::TASK_UPDATED,
                    $due !== null
                        ? sprintf('a replanifié %s au %s', $title, ProjectDateFormatter::short($due))
                        : sprintf('a retiré l\'échéance de %s', $title),
                    $actor,
                    task: $task,
                );
                break;

            default:
                throw new \InvalidArgumentException('Champ non modifiable.');
        }

        $this->em->flush();
    }

    /** Case à cocher rapide « terminée » (liste, vue d'ensemble). */
    public function toggleDone(ProjectTask $task, User $actor): void
    {
        $task->setStatus($task->isDone() ? ProjectTaskStatus::Todo : ProjectTaskStatus::Done);
        $this->logStatusChange($task, $actor);
        $this->em->flush();
    }

    public function delete(ProjectTask $task, User $actor): void
    {
        // La trace est rattachée au PROJET (la tâche va disparaître) : le titre
        // reste lisible dans le fil du projet grâce au message figé.
        $this->activityLogger->log(
            ProjectActivity::TASK_DELETED,
            sprintf('a supprimé la tâche %s', ProjectActivityLogger::quote($task->getTitle())),
            $actor,
            $task->getProject(),
        );
        $this->em->remove($task);
        $this->em->flush();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Sous-tâches et commentaires
    // ═════════════════════════════════════════════════════════════════════════

    /** @return string|null message d'erreur, ou null si la sous-tâche est ajoutée */
    public function addSubtask(ProjectTask $task, string $title): ?string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            return 'Une sous-tâche doit faire entre 1 et 255 caractères.';
        }
        if ($task->getSubtasks()->count() >= 100) {
            return 'Cette tâche a déjà 100 sous-tâches : découpe-la en plusieurs tâches.';
        }

        $subtask = (new ProjectSubtask())
            ->setTitle($title)
            ->setPosition($this->subtaskRepository->nextPosition($task));
        $task->addSubtask($subtask);
        $this->em->persist($subtask);
        $this->em->flush();

        return null;
    }

    public function toggleSubtask(ProjectSubtask $subtask): void
    {
        $subtask->setDone(!$subtask->isDone());
        $this->em->flush();
    }

    public function deleteSubtask(ProjectSubtask $subtask): void
    {
        $subtask->getTask()->removeSubtask($subtask);
        $this->em->remove($subtask);
        $this->em->flush();
    }

    /** @return string|null message d'erreur, ou null si le commentaire est publié */
    public function addComment(ProjectTask $task, string $content, User $author): ?string
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 5000) {
            return 'Un commentaire doit faire entre 1 et 5 000 caractères.';
        }

        $comment = (new ProjectTaskComment())
            ->setTask($task)
            ->setAuthor($author)
            ->setContent($content);
        $this->em->persist($comment);
        $this->activityLogger->log(
            ProjectActivity::COMMENT_ADDED,
            sprintf('a commenté %s', ProjectActivityLogger::quote($task->getTitle())),
            $author,
            task: $task,
        );
        $this->em->flush();

        $this->notifier->notifyComment($comment, $author);

        return null;
    }

    public function deleteComment(ProjectTaskComment $comment): void
    {
        $this->em->remove($comment);
        $this->em->flush();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Tri et regroupements pour les vues
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Trie une liste de tâches (vue Liste). Tri fait en PHP : voir ProjectTaskRepository.
     *
     * @param list<ProjectTask> $tasks
     *
     * @return list<ProjectTask>
     */
    public function sort(array $tasks, string $sort, string $direction): array
    {
        $key = static fn (ProjectTask $t): array => match ($sort) {
            // Sans échéance → toujours en fin de liste (d'où le booléen en 1re position)
            'due'      => [$t->getDueDate() === null, $t->getDueDate(), -$t->getPriority()->weight()],
            'priority' => [-$t->getPriority()->weight(), $t->getDueDate() === null, $t->getDueDate()],
            'status'   => [array_search($t->getStatus(), ProjectTaskStatus::cases(), true), $t->getPosition()],
            'title'    => [mb_strtolower($t->getTitle())],
            'project'  => [$t->getProject() === null, mb_strtolower($t->getProject()?->getName() ?? ''), $t->getPosition()],
            'updated'  => [-$t->getUpdatedAt()->getTimestamp()],
            default    => [$t->isDone(), $t->getDueDate() === null, $t->getDueDate(), -$t->getPriority()->weight()],
        };

        usort($tasks, static fn (ProjectTask $a, ProjectTask $b): int => $key($a) <=> $key($b));

        return $direction === 'desc' ? array_reverse($tasks) : $tasks;
    }

    /**
     * Colonnes des vues « tableau » : Kanban (par statut), Par priorité, Par personne.
     *
     * Chaque colonne indique le champ et la valeur à envoyer au serveur quand on y
     * dépose une carte (data-field / data-value dans le HTML).
     *
     * @param list<ProjectTask> $tasks
     *
     * @return list<array{field: string, value: string, label: string, hint: string|null, tasks: list<ProjectTask>, hiddenCount: int, user: User|null, cssModifier: string}>
     */
    public function buildBoard(array $tasks, string $groupBy, bool $limitDone = true): array
    {
        return match ($groupBy) {
            'priority' => $this->boardByPriority($tasks),
            'person'   => $this->boardByPerson($tasks),
            default    => $this->boardByStatus($tasks, $limitDone),
        };
    }

    /**
     * Mes tâches ouvertes regroupées par urgence (vue d'ensemble).
     *
     * @param list<ProjectTask> $tasks
     *
     * @return array{overdue: list<ProjectTask>, today: list<ProjectTask>, week: list<ProjectTask>, later: list<ProjectTask>, nodate: list<ProjectTask>}
     */
    public function groupByUrgency(array $tasks, \DateTimeImmutable $today): array
    {
        $groups  = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'nodate' => []];
        $weekEnd = $today->modify('+6 days');

        foreach ($this->sort($tasks, 'due', 'asc') as $task) {
            $due = $task->getDueDate();
            $bucket = match (true) {
                $due === null      => 'nodate',
                $due < $today      => 'overdue',
                $due == $today     => 'today',
                $due <= $weekEnd   => 'week',
                default            => 'later',
            };
            $groups[$bucket][] = $task;
        }

        return $groups;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Outils internes
    // ═════════════════════════════════════════════════════════════════════════

    /** Copie les champs simples du DTO + projet + étiquettes. */
    private function applyData(ProjectTask $task, ProjectTaskData $data): void
    {
        $task
            ->setTitle($data->title)
            ->setDescription($data->description)
            ->setStatus($data->status)
            ->setPriority($data->priority)
            ->setStartDate($data->startDate)
            ->setDueDate($data->dueDate)
            ->setProject($data->projectId !== null ? $this->projectRepository->find($data->projectId) : null);

        // ── Étiquettes : existantes cochées + nouvelles tapées ────────────────
        $wanted = $data->labelIds !== [] ? $this->labelRepository->findBy(['id' => $data->labelIds]) : [];
        foreach ($data->newLabels as $name) {
            $wanted[] = $this->findOrCreateLabel($name);
        }

        $wantedIds = [];
        foreach ($wanted as $label) {
            $task->addLabel($label);
            $wantedIds[] = spl_object_id($label);
        }
        foreach ($task->getLabels()->toArray() as $label) {
            if (!in_array(spl_object_id($label), $wantedIds, true)) {
                $task->removeLabel($label);
            }
        }
    }

    /**
     * Aligne les personnes assignées sur la liste d'IDs.
     * Un ID qui n'est pas celui d'une membre de l'équipe est ignoré (sécurité).
     *
     * @param list<int> $userIds
     *
     * @return list<User> les personnes NOUVELLEMENT assignées (à prévenir)
     */
    private function syncAssignees(ProjectTask $task, array $userIds): array
    {
        $wanted = [];
        foreach ($userIds as $id) {
            $member = $this->memberService->findMember($id);
            if ($member !== null) {
                $wanted[(int) $member->getId()] = $member;
            }
        }

        foreach ($task->getAssignees()->toArray() as $current) {
            if (!isset($wanted[(int) $current->getId()])) {
                $task->removeAssignee($current);
            }
        }

        $added = [];
        foreach ($wanted as $member) {
            if (!$task->isAssignedTo($member)) {
                $task->addAssignee($member);
                $added[] = $member;
            }
        }

        return $added;
    }

    private function moveAssignee(ProjectTask $task, string $value, ?int $fromUserId, User $actor): void
    {
        $title = ProjectActivityLogger::quote($task->getTitle());

        // Colonne « Non assignées » : on retire tout le monde.
        if ($value === 'none') {
            foreach ($task->getAssignees()->toArray() as $assignee) {
                $task->removeAssignee($assignee);
            }
            $this->activityLogger->log(ProjectActivity::TASK_ASSIGNED, sprintf('a retiré toutes les personnes de %s', $title), $actor, task: $task);

            return;
        }

        $target = ctype_digit($value) ? $this->memberService->findMember((int) $value) : null;
        if ($target === null) {
            throw new \InvalidArgumentException('Personne inconnue.');
        }

        // On retire la personne de la colonne d'origine (la tâche « change de mains »),
        // sauf si on la redépose sur la même personne.
        if ($fromUserId !== null && $fromUserId !== $target->getId()) {
            $from = $this->memberService->findMember($fromUserId);
            if ($from !== null) {
                $task->removeAssignee($from);
            }
        }

        if (!$task->isAssignedTo($target)) {
            $task->addAssignee($target);
            $this->logAssignment($task, [$target], $actor);
            // flush() dans move() AVANT l'email ; ici on se contente de préparer.
            $this->em->flush();
            $this->notifier->notifyAssigned($task, $target, $actor);
        }
    }

    /** @param list<int> $orderedIds */
    private function reorder(array $orderedIds): void
    {
        // Garde-fou : une colonne Kanban ne contient pas des milliers de cartes.
        $orderedIds = array_slice(array_values(array_unique($orderedIds)), 0, 500);
        if ($orderedIds === []) {
            return;
        }

        $positions = array_flip($orderedIds);
        foreach ($this->taskRepository->findBy(['id' => $orderedIds]) as $task) {
            $task->setPosition($positions[(int) $task->getId()]);
        }
    }

    private function logStatusChange(ProjectTask $task, User $actor): void
    {
        $title = ProjectActivityLogger::quote($task->getTitle());
        $task->isDone()
            ? $this->activityLogger->log(ProjectActivity::TASK_COMPLETED, sprintf('a terminé %s', $title), $actor, task: $task)
            : $this->activityLogger->log(ProjectActivity::TASK_MOVED, sprintf('a déplacé %s vers %s', $title, $task->getStatus()->label()), $actor, task: $task);
    }

    /** @param list<User> $users */
    private function logAssignment(ProjectTask $task, array $users, User $actor): void
    {
        $names = implode(', ', array_map(static fn (User $u): string => ProjectMemberService::displayName($u), $users));
        $this->activityLogger->log(
            ProjectActivity::TASK_ASSIGNED,
            sprintf('a assigné %s à %s', ProjectActivityLogger::quote($task->getTitle()), $names),
            $actor,
            task: $task,
        );
    }

    private function findOrCreateLabel(string $name): ProjectLabel
    {
        $existing = $this->labelRepository->findOneByNameInsensitive($name);
        if ($existing !== null) {
            return $existing;
        }

        // Couleur suivante de la palette, pour que les étiquettes se distinguent.
        $color = ProjectService::COLORS[$this->labelRepository->count([]) % count(ProjectService::COLORS)];
        $label = (new ProjectLabel())->setName($name)->setColor($color);
        $this->em->persist($label);

        return $label;
    }

    // ── Constructions des colonnes ──────────────────────────────────────────

    /**
     * @param list<ProjectTask> $tasks
     *
     * @return list<array{field: string, value: string, label: string, hint: string|null, tasks: list<ProjectTask>, hiddenCount: int, user: User|null, cssModifier: string}>
     */
    private function boardByStatus(array $tasks, bool $limitDone): array
    {
        $columns = [];
        foreach (ProjectTaskStatus::cases() as $status) {
            $columnTasks = array_values(array_filter($tasks, static fn (ProjectTask $t): bool => $t->getStatus() === $status));
            $hidden      = 0;

            // La colonne « Terminé » grossit indéfiniment : on n'affiche que les
            // plus récentes (le reste reste accessible via la vue Liste).
            if ($status->isDone() && $limitDone && count($columnTasks) > self::DONE_COLUMN_LIMIT) {
                usort($columnTasks, static fn (ProjectTask $a, ProjectTask $b): int => $b->getCompletedAt() <=> $a->getCompletedAt());
                $hidden      = count($columnTasks) - self::DONE_COLUMN_LIMIT;
                $columnTasks = array_slice($columnTasks, 0, self::DONE_COLUMN_LIMIT);
            }

            $columns[] = [
                'field'       => 'status',
                'value'       => $status->value,
                'label'       => $status->label(),
                'hint'        => $status->hint(),
                'tasks'       => $columnTasks,
                'hiddenCount' => $hidden,
                'user'        => null,
                'cssModifier' => $status->cssModifier(),
            ];
        }

        return $columns;
    }

    /**
     * @param list<ProjectTask> $tasks
     *
     * @return list<array{field: string, value: string, label: string, hint: string|null, tasks: list<ProjectTask>, hiddenCount: int, user: User|null, cssModifier: string}>
     */
    private function boardByPriority(array $tasks): array
    {
        $columns = [];
        foreach (ProjectTaskPriority::cases() as $priority) {
            $columnTasks = array_values(array_filter($tasks, static fn (ProjectTask $t): bool => $t->getPriority() === $priority));
            $columns[] = [
                'field'       => 'priority',
                'value'       => $priority->value,
                'label'       => $priority->label(),
                'hint'        => null,
                'tasks'       => $this->sort($columnTasks, 'default', 'asc'),
                'hiddenCount' => 0,
                'user'        => null,
                'cssModifier' => $priority->cssModifier(),
            ];
        }

        return $columns;
    }

    /**
     * Une colonne par membre + « Non assignées ». Une tâche partagée apparaît
     * dans la colonne de chacune des personnes assignées.
     *
     * @param list<ProjectTask> $tasks
     *
     * @return list<array{field: string, value: string, label: string, hint: string|null, tasks: list<ProjectTask>, hiddenCount: int, user: User|null, cssModifier: string}>
     */
    private function boardByPerson(array $tasks): array
    {
        $columns = [];
        foreach ($this->memberService->getMembers() as $member) {
            $columnTasks = array_values(array_filter($tasks, static fn (ProjectTask $t): bool => $t->isAssignedTo($member)));
            $columns[] = [
                'field'       => 'assignee',
                'value'       => (string) $member->getId(),
                'label'       => ProjectMemberService::displayName($member),
                'hint'        => null,
                'tasks'       => $this->sort($columnTasks, 'default', 'asc'),
                'hiddenCount' => 0,
                'user'        => $member,
                'cssModifier' => 'person',
            ];
        }

        $unassigned = array_values(array_filter($tasks, static fn (ProjectTask $t): bool => $t->getAssignees()->isEmpty()));
        $columns[] = [
            'field'       => 'assignee',
            'value'       => 'none',
            'label'       => 'Non assignées',
            'hint'        => 'Tâches que personne n\'a encore prises',
            'tasks'       => $this->sort($unassigned, 'default', 'asc'),
            'hiddenCount' => 0,
            'user'        => null,
            'cssModifier' => 'unassigned',
        ];

        return $columns;
    }
}

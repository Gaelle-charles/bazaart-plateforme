<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\DTO\Project\ProjectTaskData;
use App\DTO\Project\ProjectTaskFilter;
use App\Entity\ProjectSubtask;
use App\Entity\ProjectTask;
use App\Entity\ProjectTaskComment;
use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use App\Repository\ProjectActivityRepository;
use App\Repository\ProjectLabelRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTaskCommentRepository;
use App\Repository\ProjectTaskRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\Project\GoogleDriveService;
use App\Service\Project\ProjectCalendarBuilder;
use App\Service\Project\ProjectClock;
use App\Service\Project\ProjectMemberService;
use App\Service\Project\ProjectTaskService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminProjectTaskController — tâches de l'Espace projets (ADR-0037).
 *
 * Une seule page « Tâches » avec 5 vues interchangeables, qui partagent les mêmes
 * filtres (ProjectTaskFilter) :
 *   - kanban   : colonnes par statut, glisser-déposer + ordre manuel
 *   - list     : tableau triable
 *   - calendar : grille mensuelle par échéance, glisser pour replanifier
 *   - person   : une colonne par membre (charge de chacune), glisser pour réassigner
 *   - priority : colonnes Urgente / Haute / Normale / Basse
 *
 * La dernière vue utilisée est mémorisée dans le profil de la membre.
 */
#[Route('/admin/projets', name: 'app_admin_pm_')]
#[IsGranted(ProjectVoter::ACCESS)]
class AdminProjectTaskController extends AbstractController
{
    use ProjectControllerTrait;

    public const array VIEWS = [
        'kanban'   => 'Kanban',
        'list'     => 'Liste',
        'calendar' => 'Calendrier',
        'person'   => 'Par personne',
        'priority' => 'Par priorité',
    ];

    public function __construct(
        private readonly ProjectTaskService $taskService,
        private readonly ProjectMemberService $memberService,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectLabelRepository $labelRepository,
        private readonly ProjectActivityRepository $activityRepository,
        private readonly ProjectTaskCommentRepository $commentRepository,
        private readonly ProjectCalendarBuilder $calendarBuilder,
        private readonly GoogleDriveService $driveService,
        private readonly ProjectClock $clock,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // Page « Tâches » (toutes les vues)
    // ═════════════════════════════════════════════════════════════════════════

    #[Route('/taches', name: 'tasks', methods: ['GET'])]
    public function tasks(Request $request): Response
    {
        $user    = $this->currentUser();
        $today   = $this->clock->today();
        $profile = $this->memberService->getProfile($user);

        // ── Vue demandée, sinon la dernière utilisée, sinon Kanban ────────────
        $view = (string) $request->query->get('view', '');
        if (!array_key_exists($view, self::VIEWS)) {
            $view = array_key_exists((string) $profile->getLastView(), self::VIEWS) ? (string) $profile->getLastView() : 'kanban';
        } elseif ($view !== $profile->getLastView()) {
            $profile->setLastView($view);
            $this->memberService->saveProfile($profile);
        }

        $filter = ProjectTaskFilter::fromRequest($request);

        // Les vues « charge de travail » masquent par défaut les tâches terminées
        // (sauf ?done=show ou filtre de statut explicite). On travaille sur une COPIE
        // du filtre pour ne pas propager ce défaut dans les liens vers les autres vues.
        $effective = clone $filter;
        if (in_array($view, ['person', 'priority'], true) && $filter->status === null && $request->query->get('done') !== 'show') {
            $effective->hideDone = true;
        }

        $context = [
            'view'       => $view,
            'views'      => self::VIEWS,
            'filter'     => $filter,
            'baseQuery'  => $filter->toQuery(),
            'members'    => $this->memberService->getMembers(),
            'projects'   => $this->projectRepository->findSelectable(),
            'labels'     => $this->labelRepository->findAllOrdered(),
            'statuses'   => ProjectTaskStatus::cases(),
            'priorities' => ProjectTaskPriority::cases(),
            'dueOptions' => ProjectTaskFilter::DUE_OPTIONS,
            'doneHiddenByDefault' => $effective->hideDone && !$filter->hideDone,
        ];

        if ($view === 'calendar') {
            // Seulement les tâches datées de la grille affichée (+ un bac « sans échéance »).
            $month = ProjectCalendarBuilder::parseMonth($request->query->has('month') ? (string) $request->query->get('month') : null, $today);
            $range = ProjectCalendarBuilder::gridRange($month);

            $calendarFilter          = clone $effective;
            $calendarFilter->dueFrom = $range['from'];
            $calendarFilter->dueTo   = $range['to'];
            $calendarFilter->onlyWithDueDate = true;
            $tasks = $this->taskRepository->findByFilter($calendarFilter, $user, $today);

            $undatedFilter           = clone $effective;
            $undatedFilter->due      = 'none';
            $undatedFilter->hideDone = true;
            $undated = array_slice($this->taskService->sort($this->taskRepository->findByFilter($undatedFilter, $user, $today), 'priority', 'asc'), 0, 40);

            $projects = array_values(array_filter(
                $this->projectRepository->findSelectable(),
                static fn ($p): bool => $p->getDueDate() !== null
                    && $p->getDueDate() >= $range['from'] && $p->getDueDate() <= $range['to']
                    && ($filter->projectId === null || $p->getId() === $filter->projectId),
            ));

            $context['calendar']  = $this->calendarBuilder->build($month, $tasks, $projects, $today);
            $context['undated']   = $undated;
            $context['cardStats'] = [];
        } else {
            $tasks = $this->taskRepository->findByFilter($effective, $user, $today);

            if ($view === 'list') {
                $sort = (string) $request->query->get('sort', 'default');
                $dir  = $request->query->get('dir') === 'desc' ? 'desc' : 'asc';
                $context['tasks'] = $this->taskService->sort($tasks, $sort, $dir);
                $context['sort']  = $sort;
                $context['dir']   = $dir;
            } else {
                // Kanban : si on filtre explicitement « Terminé », on montre toute la colonne.
                $groupBy = ['kanban' => 'status', 'person' => 'person', 'priority' => 'priority'][$view];
                $context['columns'] = $this->taskService->buildBoard($tasks, $groupBy, $filter->status === null);
            }

            $context['cardStats'] = $this->taskRepository->fetchCardStats(array_map(static fn (ProjectTask $t): int => (int) $t->getId(), $tasks));
            $context['total']     = count($tasks);
        }

        return $this->render('admin/projects/tasks.html.twig', $context);
    }

    /**
     * GET /admin/projets/taches/export.csv — export tableur des tâches filtrées.
     *
     * Séparateur « ; » et BOM UTF-8 : c'est ce qu'attend Excel en français pour
     * ouvrir le fichier directement avec les accents corrects.
     */
    #[Route('/taches/export.csv', name: 'tasks_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $tasks = $this->taskService->sort(
            $this->taskRepository->findByFilter(ProjectTaskFilter::fromRequest($request), $this->currentUser(), $this->clock->today()),
            'project',
            'asc',
        );

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de générer l\'export.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Titre', 'Projet', 'Statut', 'Priorité', 'Assignées', 'Début', 'Échéance', 'Étiquettes', 'Créée le', 'Terminée le'], ';', '"', '');
        foreach ($tasks as $task) {
            fputcsv($handle, array_map(self::csvSafe(...), [
                $task->getTitle(),
                $task->getProject()?->getName() ?? '',
                $task->getStatus()->label(),
                $task->getPriority()->label(),
                implode(', ', array_map(static fn ($u): string => ProjectMemberService::displayName($u), $task->getAssignees()->toArray())),
                $task->getStartDate()?->format('d/m/Y') ?? '',
                $task->getDueDate()?->format('d/m/Y') ?? '',
                implode(', ', array_map(static fn ($l): string => $l->getName(), $task->getLabels()->toArray())),
                $this->clock->toLocal($task->getCreatedAt())->format('d/m/Y H:i'),
                $task->getCompletedAt() !== null ? $this->clock->toLocal($task->getCompletedAt())->format('d/m/Y H:i') : '',
            ]), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="taches-bazaart-%s.csv"', $this->clock->today()->format('Y-m-d')),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Création / fiche / modification
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * GET  /admin/projets/taches/nouvelle — formulaire complet (pré-rempli via l'URL)
     * POST /admin/projets/taches/nouvelle — création (formulaire complet OU ajout rapide)
     */
    #[Route('/taches/nouvelle', name: 'task_new', methods: ['GET', 'POST'])]
    public function newTask(Request $request): Response
    {
        $isQuick = $request->request->getBoolean('quick');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pm_task_new', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

                return $this->redirectBack($request, 'app_admin_pm_tasks');
            }

            $data   = ProjectTaskData::fromRequest($request);
            $errors = $data->validate();
            if ($errors === []) {
                $task = $this->taskService->create($data, $this->currentUser());
                $this->addFlash('success', sprintf('Tâche « %s » créée.', $task->getTitle()));

                return $isQuick
                    ? $this->redirectBack($request, 'app_admin_pm_tasks')
                    : $this->redirectToRoute('app_admin_pm_task_show', ['id' => $task->getId()]);
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
            if ($isQuick) {
                return $this->redirectBack($request, 'app_admin_pm_tasks');
            }
        } else {
            // Pré-remplissage depuis l'URL (bouton « + » d'une colonne, d'un jour du calendrier…)
            $data = new ProjectTaskData();
            $project = (string) $request->query->get('project', '');
            $data->projectId = ctype_digit($project) ? (int) $project : null;
            $data->status    = ProjectTaskStatus::tryFrom((string) $request->query->get('status', '')) ?? ProjectTaskStatus::Todo;
            $data->priority  = ProjectTaskPriority::tryFrom((string) $request->query->get('priority', '')) ?? ProjectTaskPriority::Medium;
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('dueDate', ''));
            $data->dueDate = $due !== false ? $due : null;
            $assignee = (string) $request->query->get('assignee', '');
            $data->assigneeIds = ctype_digit($assignee) ? [(int) $assignee] : [];
        }

        return $this->render('admin/projects/task_form.html.twig', [
            'data'       => $data,
            'members'    => $this->memberService->getMembers(),
            'projects'   => $this->projectRepository->findSelectable(),
            'labels'     => $this->labelRepository->findAllOrdered(),
            'statuses'   => ProjectTaskStatus::cases(),
            'priorities' => ProjectTaskPriority::cases(),
        ]);
    }

    /** GET /admin/projets/taches/{id} — fiche complète d'une tâche. */
    #[Route('/taches/{id}', name: 'task_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ProjectTask $task): Response
    {
        return $this->render('admin/projects/task_show.html.twig', [
            'task'           => $task,
            'comments'       => $this->commentRepository->findBy(['task' => $task], ['createdAt' => 'ASC']),
            'activities'     => $this->activityRepository->findRecent(20, task: $task),
            'members'        => $this->memberService->getMembers(),
            'projects'       => $this->projectRepository->findSelectable(),
            'labels'         => $this->labelRepository->findAllOrdered(),
            'statuses'       => ProjectTaskStatus::cases(),
            'priorities'     => ProjectTaskPriority::cases(),
            'driveConnected' => $this->driveService->isConnected(),
        ]);
    }

    /** POST /admin/projets/taches/{id} — enregistre le formulaire de la fiche. */
    #[Route('/taches/{id}', name: 'task_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(ProjectTask $task, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_task_' . $task->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

            return $this->redirectToRoute('app_admin_pm_task_show', ['id' => $task->getId()]);
        }

        $data   = ProjectTaskData::fromRequest($request);
        $errors = $data->validate();
        if ($errors === []) {
            $this->taskService->update($task, $data, $this->currentUser());
            $this->addFlash('success', 'Tâche enregistrée.');
        }
        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_admin_pm_task_show', ['id' => $task->getId()]);
    }

    /**
     * POST /admin/projets/taches/{id}/deplacer — glisser-déposer (JSON).
     *
     * Corps attendu : {"field": "status", "value": "in_progress", "fromUserId": null, "orderedIds": [4, 9, 2]}
     */
    #[Route('/taches/{id}/deplacer', name: 'task_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(ProjectTask $task, Request $request): JsonResponse
    {
        if (!$this->isAjaxCsrfValid($request)) {
            return $this->jsonError('Jeton de sécurité invalide, recharge la page.', 403);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->jsonError('Requête invalide.');
        }

        $field      = is_string($payload['field'] ?? null) ? $payload['field'] : '';
        $value      = is_scalar($payload['value'] ?? null) ? (string) $payload['value'] : '';
        $fromUserId = is_numeric($payload['fromUserId'] ?? null) ? (int) $payload['fromUserId'] : null;
        $orderedIds = is_array($payload['orderedIds'] ?? null)
            ? array_values(array_map('intval', array_filter($payload['orderedIds'], 'is_numeric')))
            : [];

        if (!in_array($field, ProjectTaskService::MOVABLE_FIELDS, true)) {
            return $this->jsonError('Champ non modifiable.');
        }

        try {
            $this->taskService->move($task, $field, $value, $fromUserId, $orderedIds, $this->currentUser());
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage());
        }

        return new JsonResponse(['ok' => true, 'status' => $task->getStatus()->value, 'done' => $task->isDone()]);
    }

    /** POST /admin/projets/taches/{id}/terminer — case à cocher « terminée ». */
    #[Route('/taches/{id}/terminer', name: 'task_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(ProjectTask $task, Request $request): Response
    {
        if ($this->isCsrfTokenValid('pm_task_toggle_' . $task->getId(), (string) $request->request->get('_token'))) {
            $this->taskService->toggleDone($task, $this->currentUser());
        } else {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        }

        return $this->redirectBack($request, 'app_admin_pm_tasks');
    }

    /** POST /admin/projets/taches/{id}/supprimer */
    #[Route('/taches/{id}/supprimer', name: 'task_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(ProjectTask $task, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_task_delete_' . $task->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_admin_pm_task_show', ['id' => $task->getId()]);
        }

        $project = $task->getProject();
        $title   = $task->getTitle();
        $this->taskService->delete($task, $this->currentUser());
        $this->addFlash('success', sprintf('Tâche « %s » supprimée.', $title));

        return $project !== null
            ? $this->redirectToRoute('app_admin_pm_project_show', ['id' => $project->getId()])
            : $this->redirectToRoute('app_admin_pm_tasks');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Sous-tâches
    // ═════════════════════════════════════════════════════════════════════════

    #[Route('/taches/{id}/sous-taches', name: 'task_subtask_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function newSubtask(ProjectTask $task, Request $request): Response
    {
        if ($this->isCsrfTokenValid('pm_task_' . $task->getId(), (string) $request->request->get('_token'))) {
            $error = $this->taskService->addSubtask($task, (string) $request->request->get('title', ''));
            if ($error !== null) {
                $this->addFlash('error', $error);
            }
        }

        return $this->redirect($this->generateUrl('app_admin_pm_task_show', ['id' => $task->getId()]) . '#sous-taches');
    }

    /** Coche / décoche. Répond en JSON si appelé par fetch (case cochée sans rechargement). */
    #[Route('/sous-taches/{id}/cocher', name: 'task_subtask_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleSubtask(ProjectSubtask $subtask, Request $request): Response
    {
        $taskId = $subtask->getTask()->getId();
        $valid  = $this->isAjaxCsrfValid($request)
            || $this->isCsrfTokenValid('pm_task_' . $taskId, (string) $request->request->get('_token'));

        if ($valid) {
            $this->taskService->toggleSubtask($subtask);
        }
        if ($request->headers->has('X-CSRF-Token')) {
            return $valid ? new JsonResponse(['ok' => true, 'done' => $subtask->isDone()]) : $this->jsonError('Jeton invalide.', 403);
        }

        return $this->redirect($this->generateUrl('app_admin_pm_task_show', ['id' => $taskId]) . '#sous-taches');
    }

    #[Route('/sous-taches/{id}/supprimer', name: 'task_subtask_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteSubtask(ProjectSubtask $subtask, Request $request): Response
    {
        $taskId = $subtask->getTask()->getId();
        if ($this->isCsrfTokenValid('pm_task_' . $taskId, (string) $request->request->get('_token'))) {
            $this->taskService->deleteSubtask($subtask);
        }

        return $this->redirect($this->generateUrl('app_admin_pm_task_show', ['id' => $taskId]) . '#sous-taches');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Commentaires
    // ═════════════════════════════════════════════════════════════════════════

    #[Route('/taches/{id}/commentaires', name: 'task_comment_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function newComment(ProjectTask $task, Request $request): Response
    {
        if ($this->isCsrfTokenValid('pm_task_' . $task->getId(), (string) $request->request->get('_token'))) {
            $error = $this->taskService->addComment($task, (string) $request->request->get('content', ''), $this->currentUser());
            if ($error !== null) {
                $this->addFlash('error', $error);
            }
        }

        return $this->redirect($this->generateUrl('app_admin_pm_task_show', ['id' => $task->getId()]) . '#commentaires');
    }

    #[Route('/commentaires/{id}/supprimer', name: 'task_comment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteComment(ProjectTaskComment $comment, Request $request): Response
    {
        $taskId = $comment->getTask()->getId();
        if ($this->isCsrfTokenValid('pm_comment_delete_' . $comment->getId(), (string) $request->request->get('_token'))) {
            // Seule l'autrice peut supprimer son commentaire.
            $this->denyAccessUnlessGranted(ProjectVoter::COMMENT_DELETE, $comment);
            $this->taskService->deleteComment($comment);
        }

        return $this->redirect($this->generateUrl('app_admin_pm_task_show', ['id' => $taskId]) . '#commentaires');
    }

    /**
     * Neutralise l'« injection de formule » CSV : une cellule qui commence par
     * = + - @ serait interprétée comme une formule par Excel. On la préfixe d'une apostrophe.
     */
    private static function csvSafe(string $value): string
    {
        return ($value !== '' && str_contains('=+-@', $value[0])) ? "'" . $value : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\DTO\Project\ProjectData;
use App\DTO\Project\ProjectTaskFilter;
use App\Entity\Project;
use App\Enum\ProjectStatus;
use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use App\Exception\GoogleDriveException;
use App\Repository\ProjectActivityRepository;
use App\Repository\ProjectAttachmentRepository;
use App\Repository\ProjectLabelRepository;
use App\Repository\ProjectNoteRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTaskRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\Project\GoogleDriveService;
use App\Service\Project\ProjectClock;
use App\Service\Project\ProjectLabelService;
use App\Service\Project\ProjectMemberService;
use App\Service\Project\ProjectOnboardingService;
use App\Service\Project\ProjectService;
use App\Service\Project\ProjectTaskService;
use App\Service\Project\ProjectTemplateCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminProjectController — Espace projets : vue d'ensemble, projets, équipe (ADR-0037).
 *
 * Préfixe d'URL : /admin/projets — préfixe de nom de route : app_admin_pm_
 * (« pm » = project management). Les tâches, notes et le Drive ont leurs propres
 * contrôleurs dans ce même dossier.
 *
 * SÉCURITÉ (défense en profondeur, deux verrous indépendants) :
 *   1. security.yaml : access_control ^/admin/projets → ROLE_PROJECT
 *   2. #[IsGranted(ProjectVoter::ACCESS)] sur la classe ci-dessous
 *
 * Contrôleur « fin » : il lit la requête, appelle les services, choisit le template.
 */
#[Route('/admin/projets', name: 'app_admin_pm_')]
#[IsGranted(ProjectVoter::ACCESS)]
class AdminProjectController extends AbstractController
{
    use ProjectControllerTrait;

    public function __construct(
        private readonly ProjectService $projectService,
        private readonly ProjectTaskService $taskService,
        private readonly ProjectMemberService $memberService,
        private readonly ProjectOnboardingService $onboardingService,
        private readonly ProjectTemplateCatalog $templateCatalog,
        private readonly GoogleDriveService $driveService,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectNoteRepository $noteRepository,
        private readonly ProjectActivityRepository $activityRepository,
        private readonly ProjectAttachmentRepository $attachmentRepository,
        private readonly ProjectLabelRepository $labelRepository,
        private readonly ProjectLabelService $labelService,
        private readonly ProjectClock $clock,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // Vue d'ensemble
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * GET /admin/projets — tableau de bord personnel : mes tâches par urgence,
     * projets en cours, notes épinglées, charge de l'équipe, activité, onboarding.
     */
    #[Route('', name: 'overview', methods: ['GET'])]
    public function overview(): Response
    {
        $user  = $this->currentUser();
        $today = $this->clock->today();

        $myTasks = $this->taskRepository->findOpenAssignedTo($user);
        $groups  = $this->taskService->groupByUrgency($myTasks, $today);

        $projects = array_values(array_filter(
            $this->projectService->sortForDisplay($this->projectRepository->findForList()),
            static fn (Project $p): bool => in_array($p->getStatus(), ProjectStatus::openCases(), true),
        ));

        $steps = $this->onboardingService->getChecklist($user);

        return $this->render('admin/projects/overview.html.twig', [
            'groups'        => $groups,
            'kpis'          => [
                'open'     => count($myTasks),
                'overdue'  => count($groups['overdue']),
                'week'     => count($groups['today']) + count($groups['week']),
                'projects' => $this->projectRepository->countOpen(),
            ],
            'projects'      => array_slice($projects, 0, 8),
            'projectStats'  => $this->projectService->getStats($today),
            'pinnedNotes'   => $this->noteRepository->findForWall(pinnedOnly: true, limit: 6),
            'activities'    => $this->activityRepository->findRecent(15),
            'members'       => $this->memberService->getMembers(),
            'workload'      => $this->taskRepository->countWorkloadByAssignee($today, $today->modify('-7 days')),
            'steps'         => $steps,
            'showChecklist' => $this->onboardingService->shouldShowChecklist($user, $steps),
            'stepsDone'     => count(array_filter($steps, static fn (array $s): bool => $s['done'])),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Projets : liste, chronologie, fiche
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * GET /admin/projets/projets — cartes des projets ou chronologie (?view=timeline).
     *
     * ?status= : un statut précis, « all » (y compris archivés), ou rien (non archivés).
     */
    #[Route('/projets', name: 'projects', methods: ['GET'])]
    public function projects(Request $request): Response
    {
        $today       = $this->clock->today();
        $statusParam = (string) $request->query->get('status', '');
        $status      = ProjectStatus::tryFrom($statusParam);
        $view        = $request->query->get('view') === 'timeline' ? 'timeline' : 'grid';

        $projects = $this->projectService->sortForDisplay(
            $this->projectRepository->findForList($status, $statusParam === 'all'),
        );

        // Chronologie : fenêtre de 6 mois commençant le mois précédent (?from=AAAA-MM pour naviguer).
        $timeline = null;
        if ($view === 'timeline') {
            $fromParam = (string) $request->query->get('from', '');
            $from      = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fromParam) === 1
                ? new \DateTimeImmutable($fromParam . '-01')
                : $today->modify('first day of last month');
            $timeline = $this->projectService->buildTimeline($projects, $from, $today);
        }

        return $this->render('admin/projects/projects.html.twig', [
            'projects'     => $projects,
            'projectStats' => $this->projectService->getStats($today),
            'statusParam'  => $statusParam,
            'view'         => $view,
            'timeline'     => $timeline,
        ]);
    }

    /** GET|POST /admin/projets/projets/nouveau — création (avec modèle de tâches optionnel). */
    #[Route('/projets/nouveau', name: 'project_new', methods: ['GET', 'POST'])]
    public function newProject(Request $request): Response
    {
        $data = new ProjectData();
        $data->ownerId = $this->currentUser()->getId();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pm_project_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

                return $this->redirectToRoute('app_admin_pm_project_new');
            }

            $data   = ProjectData::fromRequest($request);
            $errors = $data->validate();
            if ($data->templateKey !== null && !$this->templateCatalog->has($data->templateKey)) {
                $errors[] = 'Modèle de projet inconnu.';
            }

            if ($errors === []) {
                $project = $this->projectService->create($data, $this->currentUser());
                $this->addFlash('success', sprintf('Projet « %s » créé.', $project->getName()));

                if ($data->createDriveFolder && $this->driveService->isConnected()) {
                    $driveError = $this->projectService->createDriveFolderFor($project, $this->currentUser());
                    if ($driveError !== null) {
                        $this->addFlash('warning', $driveError);
                    }
                }

                return $this->redirectToRoute('app_admin_pm_project_show', ['id' => $project->getId()]);
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('admin/projects/project_form.html.twig', $this->projectFormContext(null, $data));
    }

    /** GET /admin/projets/projets/{id} — fiche projet (tâches, fichiers, notes, activité). */
    #[Route('/projets/{id}', name: 'project_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showProject(Project $project, Request $request): Response
    {
        $today = $this->clock->today();
        $view  = $request->query->get('view') === 'list' ? 'list' : 'kanban';

        // On réutilise le filtre des tâches, limité à ce projet.
        $filter            = new ProjectTaskFilter();
        $filter->projectId = $project->getId();
        $tasks             = $this->taskRepository->findByFilter($filter, $this->currentUser(), $today);

        return $this->render('admin/projects/project_show.html.twig', [
            'project'     => $project,
            'view'        => $view,
            'tasks'       => $view === 'list' ? $this->taskService->sort($tasks, 'default', 'asc') : $tasks,
            'columns'     => $this->taskService->buildBoard($tasks, 'status'),
            'cardStats'   => $this->taskRepository->fetchCardStats(array_map(static fn ($t): int => (int) $t->getId(), $tasks)),
            'stats'       => $this->projectService->getStats($today)[(int) $project->getId()] ?? ['total' => 0, 'done' => 0, 'overdue' => 0, 'percent' => 0],
            'attachments' => $this->attachmentRepository->findBy(['project' => $project], ['createdAt' => 'DESC']),
            'notes'       => $this->noteRepository->findByProject($project, 10),
            'activities'  => $this->activityRepository->findRecent(20, $project),
            'labels'      => $this->labelRepository->findAllOrdered(),
            'filter'      => $filter,
        ]);
    }

    /** GET|POST /admin/projets/projets/{id}/modifier */
    #[Route('/projets/{id}/modifier', name: 'project_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editProject(Project $project, Request $request): Response
    {
        $data = new ProjectData();
        $data->name        = $project->getName();
        $data->description = $project->getDescription();
        $data->color       = $project->getColor();
        $data->status      = $project->getStatus();
        $data->priority    = $project->getPriority();
        $data->ownerId     = $project->getOwner()?->getId();
        $data->startDate   = $project->getStartDate();
        $data->dueDate     = $project->getDueDate();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pm_project_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

                return $this->redirectToRoute('app_admin_pm_project_edit', ['id' => $project->getId()]);
            }

            $data   = ProjectData::fromRequest($request);
            $errors = $data->validate();
            if ($errors === []) {
                $this->projectService->update($project, $data, $this->currentUser());
                $this->addFlash('success', 'Projet mis à jour.');

                return $this->redirectToRoute('app_admin_pm_project_show', ['id' => $project->getId()]);
            }
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('admin/projects/project_form.html.twig', $this->projectFormContext($project, $data));
    }

    /** POST /admin/projets/projets/{id}/statut — boutons rapides (Archiver, Terminé…). */
    #[Route('/projets/{id}/statut', name: 'project_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeProjectStatus(Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_project_status_' . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_admin_pm_project_show', ['id' => $project->getId()]);
        }

        $status = ProjectStatus::tryFrom((string) $request->request->get('status', ''));
        if ($status !== null) {
            $this->projectService->changeStatus($project, $status, $this->currentUser());
            $this->addFlash('success', sprintf('Projet passé en « %s ».', $status->label()));
        }

        return $this->redirectBack($request, 'app_admin_pm_project_show', ['id' => $project->getId()]);
    }

    /** POST /admin/projets/projets/{id}/supprimer — suppression définitive (créatrice, responsable ou admin). */
    #[Route('/projets/{id}/supprimer', name: 'project_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteProject(Project $project, Request $request): Response
    {
        // CSRF d'abord, puis autorisation (cf. mémoire relecteur : ordre CSRF/autorisation).
        if (!$this->isCsrfTokenValid('pm_project_delete_' . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_admin_pm_project_show', ['id' => $project->getId()]);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::PROJECT_DELETE, $project);

        $name = $project->getName();
        $this->projectService->delete($project, $this->currentUser());
        $this->addFlash('success', sprintf('Projet « %s » supprimé (les fichiers du Drive sont conservés).', $name));

        return $this->redirectToRoute('app_admin_pm_projects');
    }

    /**
     * POST /admin/projets/projets/{id}/drive — rattache (folderId) ou détache ('none')
     * le dossier Drive du projet. Appelé en JSON par le sélecteur Drive.
     */
    #[Route('/projets/{id}/drive', name: 'project_drive_folder', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function linkDriveFolder(Project $project, Request $request): JsonResponse
    {
        if (!$this->isAjaxCsrfValid($request)) {
            return $this->jsonError('Jeton de sécurité invalide, recharge la page.', 403);
        }

        $payload  = $request->toArray();
        $folderId = is_string($payload['folderId'] ?? null) ? $payload['folderId'] : '';

        if ($folderId === 'none') {
            $this->projectService->unlinkDriveFolder($project);

            return new JsonResponse(['ok' => true]);
        }

        try {
            $this->projectService->linkDriveFolder($project, $folderId, $this->currentUser());
        } catch (GoogleDriveException $e) {
            return $this->jsonError($e->getMessage());
        }

        return new JsonResponse(['ok' => true, 'folderName' => $project->getDriveFolderName()]);
    }

    /** POST /admin/projets/projets/{id}/drive/creer — crée un dossier Drive pour un projet existant. */
    #[Route('/projets/{id}/drive/creer', name: 'project_drive_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createDriveFolder(Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_project_drive_' . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        } else {
            $error = $this->projectService->createDriveFolderFor($project, $this->currentUser());
            $error === null
                ? $this->addFlash('success', 'Dossier Drive créé et rattaché au projet.')
                : $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_admin_pm_project_show', ['id' => $project->getId()]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Équipe & réglages
    // ═════════════════════════════════════════════════════════════════════════

    /** GET /admin/projets/equipe — membres, charge, préférences, étiquettes, Drive. */
    #[Route('/equipe', name: 'team', methods: ['GET'])]
    public function team(): Response
    {
        $today = $this->clock->today();

        return $this->render('admin/projects/team.html.twig', [
            'members'  => $this->memberService->getMembers(),
            'workload' => $this->taskRepository->countWorkloadByAssignee($today, $today->modify('-7 days')),
            'profile'  => $this->memberService->getProfile($this->currentUser()),
            'labels'   => $this->labelRepository->findAllOrdered(),
            'colors'   => ProjectService::COLORS,
            'drive'    => $this->driveService->getConnection(),
            'driveConfigured' => $this->driveService->isConfigured(),
            'driveAccount'    => $this->driveService->getExpectedAccount(),
        ]);
    }

    /** POST /admin/projets/equipe/preferences — couleur d'avatar et emails. */
    #[Route('/equipe/preferences', name: 'team_prefs', methods: ['POST'])]
    public function savePreferences(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_prefs', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_admin_pm_team');
        }

        $profile = $this->memberService->getProfile($this->currentUser());
        $profile->setEmailNotifications($request->request->getBoolean('emailNotifications'));
        $color = (string) $request->request->get('color', '');
        if (in_array($color, ProjectService::COLORS, true) || in_array($color, ProjectMemberService::MEMBER_COLORS, true)) {
            $profile->setColor($color);
        }
        $this->memberService->saveProfile($profile);
        $this->addFlash('success', 'Préférences enregistrées.');

        return $this->redirectToRoute('app_admin_pm_team');
    }

    /** POST /admin/projets/equipe/etiquettes — crée une étiquette. */
    #[Route('/equipe/etiquettes', name: 'team_label_new', methods: ['POST'])]
    public function newLabel(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_labels', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_admin_pm_team');
        }

        $error = $this->labelService->create(
            (string) $request->request->get('name', ''),
            (string) $request->request->get('color', ''),
        );
        $error === null ? $this->addFlash('success', 'Étiquette ajoutée.') : $this->addFlash('error', $error);

        return $this->redirectToRoute('app_admin_pm_team');
    }

    /** POST /admin/projets/equipe/etiquettes/{id}/supprimer */
    #[Route('/equipe/etiquettes/{id}/supprimer', name: 'team_label_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteLabel(int $id, Request $request): Response
    {
        $label = $this->labelRepository->find($id);
        if (!$this->isCsrfTokenValid('pm_label_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->flashInvalidToken();
        } elseif ($label !== null) {
            $this->labelService->delete($label);
            $this->addFlash('success', sprintf('Étiquette « %s » supprimée (retirée des tâches).', $label->getName()));
        }

        return $this->redirectToRoute('app_admin_pm_team');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Onboarding
    // ═════════════════════════════════════════════════════════════════════════

    /** POST /admin/projets/onboarding/visite — la visite guidée a été terminée ou passée (fetch). */
    #[Route('/onboarding/visite', name: 'onboarding_tour', methods: ['POST'])]
    public function completeTour(Request $request): JsonResponse
    {
        if (!$this->isAjaxCsrfValid($request)) {
            return $this->jsonError('Jeton de sécurité invalide.', 403);
        }
        $this->onboardingService->completeTour($this->currentUser());

        return new JsonResponse(['ok' => true]);
    }

    /** POST /admin/projets/onboarding/checklist — masquer / réafficher « Bien démarrer ». */
    #[Route('/onboarding/checklist', name: 'onboarding_checklist', methods: ['POST'])]
    public function toggleChecklist(Request $request): Response
    {
        if ($this->isCsrfTokenValid('pm_checklist', (string) $request->request->get('_token'))) {
            $request->request->get('action') === 'restore'
                ? $this->onboardingService->restoreChecklist($this->currentUser())
                : $this->onboardingService->dismissChecklist($this->currentUser());
        } else {
            $this->flashInvalidToken();
        }

        return $this->redirectBack($request, 'app_admin_pm_overview');
    }

    /**
     * Variables communes aux formulaires de création et d'édition de projet.
     *
     * @return array<string, mixed>
     */
    private function projectFormContext(?Project $project, ProjectData $data): array
    {
        return [
            'project'        => $project,
            'data'           => $data,
            'colors'         => ProjectService::COLORS,
            'statuses'       => ProjectStatus::cases(),
            'priorities'     => ProjectTaskPriority::cases(),
            'members'        => $this->memberService->getMembers(),
            'templates'      => $this->templateCatalog->all(),
            'driveConnected' => $this->driveService->isConnected(),
            'taskStatuses'   => ProjectTaskStatus::cases(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\ProjectActivity;
use App\Entity\User;
use App\Enum\ProjectAttachmentSource;
use App\Repository\ProjectActivityRepository;
use App\Repository\ProjectAttachmentRepository;
use App\Repository\ProjectNoteRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ProjectOnboardingService — prise en main de l'Espace projets (ADR-0037).
 *
 * Deux dispositifs complémentaires :
 *
 *   1. La VISITE GUIDÉE : 6 écrans présentés à la première visite (fenêtre modale).
 *      On mémorise seulement qu'elle a été vue ou passée (tourCompletedAt).
 *
 *   2. La CHECKLIST « Bien démarrer » sur la vue d'ensemble : 7 étapes concrètes,
 *      COCHÉES AUTOMATIQUEMENT à partir de ce que la personne a réellement fait
 *      (on interroge les données, rien à cocher à la main). Elle disparaît quand
 *      tout est fait, ou si la personne la masque.
 */
class ProjectOnboardingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectMemberService $memberService,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectActivityRepository $activityRepository,
        private readonly ProjectNoteRepository $noteRepository,
        private readonly ProjectAttachmentRepository $attachmentRepository,
    ) {}

    /**
     * Étapes de la checklist, avec leur état.
     *
     * `route` = page où réaliser l'étape ; `tour` = true pour l'étape « visite guidée »
     * (le bouton rouvre la visite au lieu de naviguer).
     *
     * @return list<array{key: string, label: string, help: string, done: bool, route: string, params: array<string, string>, cta: string, tour: bool}>
     */
    public function getChecklist(User $user): array
    {
        $profile = $this->memberService->getProfile($user);

        return [
            [
                'key'    => 'tour',
                'label'  => 'Suivre la visite guidée',
                'help'   => '2 minutes pour découvrir les vues, les filtres, les notes et le Drive.',
                'done'   => $profile->isTourCompleted(),
                'route'  => 'app_admin_pm_overview',
                'params' => [],
                'cta'    => 'Lancer la visite',
                'tour'   => true,
            ],
            [
                'key'    => 'name',
                'label'  => 'Indiquer mon prénom',
                'help'   => 'C\'est lui qui signe tes notes, commentaires et tâches (sinon l\'équipe voit le début de ton email).',
                'done'   => trim((string) $user->getFirstName()) !== '',
                'route'  => 'app_admin_pm_team',
                'params' => ['_fragment' => 'pm-me'],
                'cta'    => 'Mon prénom',
                'tour'   => false,
            ],
            [
                'key'    => 'project',
                'label'  => 'Créer ou piloter un projet',
                'help'   => 'Astuce : pars d\'un modèle (événement, formation, candidature…) pour générer les tâches.',
                'done'   => $this->projectRepository->count(['createdBy' => $user]) > 0
                    || $this->projectRepository->count(['owner' => $user]) > 0,
                'route'  => 'app_admin_pm_project_new',
                'params' => [],
                'cta'    => 'Nouveau projet',
                'tour'   => false,
            ],
            [
                'key'    => 'task',
                'label'  => 'Créer une tâche et l\'assigner',
                'help'   => 'Une tâche peut être assignée à une ou plusieurs personnes, avec une échéance.',
                'done'   => $this->taskRepository->countCreatedAndAssignedBy($user) > 0,
                'route'  => 'app_admin_pm_tasks',
                'params' => ['view' => 'kanban'],
                'cta'    => 'Ouvrir les tâches',
                'tour'   => false,
            ],
            [
                'key'    => 'move',
                'label'  => 'Faire avancer une tâche dans le Kanban',
                'help'   => 'Glisse une carte d\'une colonne à l\'autre : « À faire » vers « En cours »…',
                'done'   => $this->activityRepository->count([
                    'actor'  => $user,
                    'action' => [ProjectActivity::TASK_MOVED, ProjectActivity::TASK_COMPLETED],
                ]) > 0,
                'route'  => 'app_admin_pm_tasks',
                'params' => ['view' => 'kanban'],
                'cta'    => 'Voir le Kanban',
                'tour'   => false,
            ],
            [
                'key'    => 'note',
                'label'  => 'Écrire une note sur le mur d\'équipe',
                'help'   => 'Les notes sont signées : tout le monde sait qui a écrit quoi.',
                'done'   => $this->noteRepository->count(['author' => $user]) > 0,
                'route'  => 'app_admin_pm_notes',
                'params' => [],
                'cta'    => 'Aller au mur',
                'tour'   => false,
            ],
            [
                'key'    => 'drive',
                'label'  => 'Joindre un fichier du Drive',
                'help'   => 'Depuis une tâche ou un projet : « Joindre depuis le Drive ».',
                'done'   => $this->attachmentRepository->count([
                    'addedBy' => $user,
                    'source'  => ProjectAttachmentSource::Drive,
                ]) > 0,
                'route'  => 'app_admin_pm_drive',
                'params' => [],
                'cta'    => 'Ouvrir le Drive',
                'tour'   => false,
            ],
        ];
    }

    /**
     * La checklist est-elle à afficher (pas masquée et pas entièrement faite) ?
     *
     * @param list<array<string, mixed>> $steps résultat de getChecklist()
     */
    public function shouldShowChecklist(User $user, array $steps): bool
    {
        if ($this->memberService->getProfile($user)->isChecklistDismissed()) {
            return false;
        }

        foreach ($steps as $step) {
            if (!$step['done']) {
                return true;
            }
        }

        return false;
    }

    public function shouldShowTour(User $user): bool
    {
        return !$this->memberService->getProfile($user)->isTourCompleted();
    }

    public function completeTour(User $user): void
    {
        $this->memberService->getProfile($user)->completeTour();
        $this->em->flush();
    }

    public function dismissChecklist(User $user): void
    {
        $this->memberService->getProfile($user)->dismissChecklist();
        $this->em->flush();
    }

    public function restoreChecklist(User $user): void
    {
        $this->memberService->getProfile($user)->restoreChecklist();
        $this->em->flush();
    }
}

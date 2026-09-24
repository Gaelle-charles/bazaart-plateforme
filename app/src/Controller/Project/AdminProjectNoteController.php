<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\DTO\Project\ProjectNoteData;
use App\Entity\ProjectNote;
use App\Enum\ProjectNoteColor;
use App\Repository\ProjectNoteRepository;
use App\Repository\ProjectRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\Project\ProjectMemberService;
use App\Service\Project\ProjectNoteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminProjectNoteController — mur de notes signées de l'équipe (ADR-0037).
 *
 * Tout le monde lit, écrit et épingle ; seule l'autrice modifie ou supprime
 * sa note (ProjectVoter::NOTE_EDIT).
 */
#[Route('/admin/projets/notes', name: 'app_admin_pm_')]
#[IsGranted(ProjectVoter::ACCESS)]
class AdminProjectNoteController extends AbstractController
{
    use ProjectControllerTrait;

    public function __construct(
        private readonly ProjectNoteService $noteService,
        private readonly ProjectNoteRepository $noteRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectMemberService $memberService,
    ) {}

    /** GET /admin/projets/notes — le mur (?author=ID, ?project=ID, ?q=texte, ?pinned=1). */
    #[Route('', name: 'notes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $author  = (string) $request->query->get('author', '');
        $project = (string) $request->query->get('project', '');
        $search  = trim((string) $request->query->get('q', ''));

        $filters = [
            'author'  => ctype_digit($author) ? (int) $author : null,
            'project' => ctype_digit($project) ? (int) $project : null,
            'q'       => $search !== '' ? mb_substr($search, 0, 100) : null,
            'pinned'  => $request->query->getBoolean('pinned'),
        ];

        return $this->render('admin/projects/notes.html.twig', [
            'notes'    => $this->noteRepository->findForWall($filters['author'], $filters['project'], $filters['q'], $filters['pinned']),
            'filters'  => $filters,
            'members'  => $this->memberService->getMembers(),
            'projects' => $this->projectRepository->findSelectable(),
            'colors'   => ProjectNoteColor::cases(),
        ]);
    }

    /** POST /admin/projets/notes — publie une note (signée par l'utilisatrice connectée). */
    #[Route('', name: 'note_new', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_note_new', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

            return $this->redirectBack($request, 'app_admin_pm_notes');
        }

        $data   = ProjectNoteData::fromRequest($request);
        $errors = $data->validate();
        if ($errors === []) {
            $this->noteService->create($data, $this->currentUser());
            $this->addFlash('success', 'Note publiée sur le mur.');
        }
        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectBack($request, 'app_admin_pm_notes');
    }

    /** POST /admin/projets/notes/{id}/modifier — autrice uniquement. */
    #[Route('/{id}/modifier', name: 'note_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function edit(ProjectNote $note, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectBack($request, 'app_admin_pm_notes');
        }
        $this->denyAccessUnlessGranted(ProjectVoter::NOTE_EDIT, $note);

        $data   = ProjectNoteData::fromRequest($request);
        $data->pinned = $note->isPinned();
        $errors = $data->validate();
        if ($errors === []) {
            $this->noteService->update($note, $data);
            $this->addFlash('success', 'Note modifiée.');
        }
        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectBack($request, 'app_admin_pm_notes');
    }

    /** POST /admin/projets/notes/{id}/epingler — ouvert à toute l'équipe. */
    #[Route('/{id}/epingler', name: 'note_pin', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pin(ProjectNote $note, Request $request): Response
    {
        if ($this->isCsrfTokenValid('pm_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            $this->noteService->togglePin($note);
        } else {
            $this->flashInvalidToken();
        }

        return $this->redirectBack($request, 'app_admin_pm_notes');
    }

    /** POST /admin/projets/notes/{id}/supprimer — autrice uniquement. */
    #[Route('/{id}/supprimer', name: 'note_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(ProjectNote $note, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectBack($request, 'app_admin_pm_notes');
        }
        $this->denyAccessUnlessGranted(ProjectVoter::NOTE_EDIT, $note);

        $this->noteService->delete($note);
        $this->addFlash('success', 'Note supprimée.');

        return $this->redirectBack($request, 'app_admin_pm_notes');
    }
}

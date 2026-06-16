<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CourseProposal;
use App\Entity\User;
use App\Repository\CourseProposalRepository;
use App\Service\CourseProposalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminCourseProposalController — revue admin des propositions de formation (ADR-0012).
 *
 * Toutes les routes sont sous /admin → déjà protégées par access_control (ROLE_ADMIN).
 * On ajoute #[IsGranted('ROLE_ADMIN')] sur la classe par défense en profondeur.
 *
 * Préfixe : /admin/formations/propositions (ne collisionne pas avec AdminCourseController :
 * /admin/formations/{id}/... a un suffixe distinct).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/formations/propositions', name: 'app_admin_course_proposal_')]
class AdminCourseProposalController extends AbstractController
{
    public function __construct(
        private readonly CourseProposalRepository $proposalRepository,
        private readonly CourseProposalService $proposalService,
    ) {}

    /**
     * Liste : propositions en attente (à traiter) + historique des traitées.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/course_proposals.html.twig', [
            'pending'  => $this->proposalRepository->findPending(),
            'reviewed' => $this->proposalRepository->findReviewed(),
        ]);
    }

    /**
     * Accepte une proposition.
     * Route : POST /admin/formations/propositions/{id}/accepter
     */
    #[Route('/{id}/accepter', name: 'accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(CourseProposal $proposal, Request $request): Response
    {
        // Token partagé accepter/refuser (un seul formulaire, deux boutons via formaction)
        if (!$this->isCsrfTokenValid('proposal_review_' . $proposal->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_proposal_index');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $note  = $request->request->get('note');

        $this->proposalService->accept($proposal, $admin, is_string($note) ? $note : null);
        $this->addFlash('success', 'Proposition acceptée. L\'auteur a été notifié par email.');

        return $this->redirectToRoute('app_admin_course_proposal_index');
    }

    /**
     * Refuse une proposition.
     * Route : POST /admin/formations/propositions/{id}/refuser
     */
    #[Route('/{id}/refuser', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(CourseProposal $proposal, Request $request): Response
    {
        // Token partagé accepter/refuser (cf. accept())
        if (!$this->isCsrfTokenValid('proposal_review_' . $proposal->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_proposal_index');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $note  = $request->request->get('note');

        $this->proposalService->reject($proposal, $admin, is_string($note) ? $note : null);
        $this->addFlash('success', 'Proposition refusée. L\'auteur a été notifié par email.');

        return $this->redirectToRoute('app_admin_course_proposal_index');
    }
}

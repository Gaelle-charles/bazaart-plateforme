<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\CourseLevel;
use App\Service\CourseProposalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CourseProposalController — propositions de formation par les membres (ADR-0012).
 *
 * Réservé aux utilisateurs connectés (ROLE_USER). Le formulaire est un formulaire
 * HTML natif avec token CSRF manuel (même convention que le forum).
 *
 * ⚠️ Chemin volontairement à 3 segments (/formations/propositions/nouvelle) pour ne
 * PAS entrer en collision avec app_course_show (/formations/{slug}, 2 segments).
 */
#[IsGranted('ROLE_USER')]
class CourseProposalController extends AbstractController
{
    public function __construct(
        private readonly CourseProposalService $proposalService,
    ) {}

    #[Route('/formations/propositions/nouvelle', name: 'app_course_proposal_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            // ── CSRF ───────────────────────────────────────────────────────────
            if (!$this->isCsrfTokenValid('course_proposal_new', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_course_proposal_new');
            }

            /** @var User $user */
            $user = $this->getUser();

            $result = $this->proposalService->createProposal($user, $request->request->all());

            if (is_string($result)) {
                // Erreur de validation → on réaffiche le formulaire avec les données saisies
                $this->addFlash('error', $result);
                return $this->render('course/proposal_new.html.twig', [
                    'levels'   => CourseLevel::cases(),
                    'formData' => $request->request->all(),
                ]);
            }

            $this->addFlash('success', 'Merci ! Ta proposition a été envoyée à l\'équipe Bazaart. Tu seras notifié·e de la suite.');
            return $this->redirectToRoute('app_dashboard');
        }

        // GET : formulaire vierge
        return $this->render('course/proposal_new.html.twig', [
            'levels'   => CourseLevel::cases(),
            'formData' => [],
        ]);
    }
}

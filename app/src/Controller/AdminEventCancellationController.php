<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CourseEnrollmentRepository;
use App\Repository\CourseRepository;
use App\Service\EventCancellationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminEventCancellationController — Annulation d'événements par l'admin.
 *
 * Ce controller gère :
 *   - POST /admin/formations/{courseId}/inscrits/{enrollmentId}/annuler
 *       → annule une inscription individuelle (avec remboursement si payant)
 *   - POST /admin/formations/{courseId}/annuler-evenement
 *       → annule l'événement entier + rembourse tous les inscrits payants actifs
 *
 * Toutes les routes sont réservées à ROLE_ADMIN.
 * La logique métier est entièrement dans EventCancellationService (thin controller).
 *
 * CSRF obligatoire sur toutes les routes POST :
 *   - 'admin_cancel_enrollment_{enrollmentId}' pour l'annulation individuelle
 *   - 'admin_cancel_event_{courseId}' pour l'annulation de l'événement entier
 *
 * Principe : l'admin peut rembourser à TOUT moment (pas de condition de délai).
 */
#[Route('/admin/formations', name: 'app_admin_course_')]
#[IsGranted('ROLE_ADMIN')]
class AdminEventCancellationController extends AbstractController
{
    public function __construct(
        private readonly EventCancellationService   $cancellationService,
        private readonly CourseEnrollmentRepository $enrollmentRepository,
        private readonly CourseRepository           $courseRepository,
    ) {}

    // ─── Annulation d'une inscription individuelle ────────────────────────────

    /**
     * POST /admin/formations/{courseId}/inscrits/{enrollmentId}/annuler
     * Annule une inscription individuelle et rembourse si l'événement était payant.
     *
     * Le paramètre {courseId} permet de rediriger vers la bonne page après l'action
     * et de vérifier que l'enrollment appartient bien à ce cours (protection IDOR).
     *
     * Token CSRF : 'admin_cancel_enrollment_{enrollmentId}'
     */
    #[Route('/{courseId}/inscrits/{enrollmentId}/annuler', name: 'enrollment_cancel', methods: ['POST'])]
    public function cancelEnrollment(int $courseId, int $enrollmentId, Request $request): Response
    {
        // ── Récupération et validation ─────────────────────────────────────────
        $enrollment = $this->enrollmentRepository->find($enrollmentId);
        if ($enrollment === null) {
            throw $this->createNotFoundException(sprintf('Inscription #%d introuvable.', $enrollmentId));
        }

        // Vérification IDOR : l'inscription appartient bien au cours demandé
        // Sans cette vérification, un admin pourrait "accidentellement" annuler
        // une inscription d'un autre cours en manipulant l'URL.
        if ($enrollment->getCourse()->getId() !== $courseId) {
            throw $this->createNotFoundException(
                sprintf(
                    'L\'inscription #%d n\'appartient pas à la formation #%d.',
                    $enrollmentId,
                    $courseId,
                )
            );
        }

        // ── Validation CSRF ───────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('admin_cancel_enrollment_' . $enrollmentId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $courseId]);
        }

        // ── Délégation au service ─────────────────────────────────────────────
        // forceRefund: true → rembourser si paiement complété (comportement par défaut admin)
        try {
            $this->cancellationService->cancelByAdmin($enrollment, forceRefund: true);

            $this->addFlash(
                'success',
                sprintf(
                    'L\'inscription de %s à « %s » a été annulée%s.',
                    $enrollment->getUser()->getEmail(),
                    $enrollment->getCourse()->getTitle(),
                    ' et le paiement remboursé si applicable',
                )
            );
        } catch (\LogicException $e) {
            // Inscription déjà annulée
            $this->addFlash('warning', $e->getMessage());
        } catch (\RuntimeException $e) {
            // Erreur Stripe
            $this->addFlash(
                'error',
                'Erreur lors du remboursement Stripe : ' . $e->getMessage()
                . '. L\'inscription n\'a pas été annulée — vérifiez le dashboard Stripe.'
            );
        }

        return $this->redirectToRoute('app_admin_course_edit', ['id' => $courseId]);
    }

    // ─── Annulation d'un événement entier ────────────────────────────────────

    /**
     * POST /admin/formations/{courseId}/annuler-evenement
     * Annule un événement entier : rembourse tous les inscrits payants actifs
     * et envoie un email à chaque inscrit.
     *
     * C'est une opération lourde et irréversible — une confirmation JS est affichée
     * (voir template edit.html.twig). La protection CSRF reste obligatoire côté serveur.
     *
     * Token CSRF : 'admin_cancel_event_{courseId}'
     */
    #[Route('/{courseId}/annuler-evenement', name: 'event_cancel', methods: ['POST'])]
    public function cancelEvent(int $courseId, Request $request): Response
    {
        // ── Récupération de l'événement ───────────────────────────────────────
        $course = $this->courseRepository->find($courseId);
        if ($course === null) {
            throw $this->createNotFoundException(sprintf('Formation #%d introuvable.', $courseId));
        }

        // Garde : seulement les formations de type EVENEMENT peuvent être "annulées"
        // (une formation CONTENU ne se "annule" pas — on la dépublie simplement)
        if (!$course->isEvent()) {
            $this->addFlash('error', 'Cette action est réservée aux formations de type Événement.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $courseId]);
        }

        // ── Validation CSRF ───────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('admin_cancel_event_' . $courseId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_course_edit', ['id' => $courseId]);
        }

        // ── Délégation au service ─────────────────────────────────────────────
        try {
            $result = $this->cancellationService->cancelEventByAdmin($course);

            $this->addFlash(
                'success',
                sprintf(
                    'Événement « %s » annulé : %d inscription(s) annulée(s), %d paiement(s) remboursé(s). '
                    . 'Tous les inscrits ont reçu un email.',
                    $course->getTitle(),
                    $result['cancelled'],
                    $result['refunded'],
                )
            );
        } catch (\Throwable $e) {
            $this->addFlash(
                'error',
                'Une erreur est survenue lors de l\'annulation de l\'événement : ' . $e->getMessage()
                . '. Des inscriptions peuvent être dans un état intermédiaire — vérifiez les logs.'
            );
        }

        return $this->redirectToRoute('app_admin_course_edit', ['id' => $courseId]);
    }
}

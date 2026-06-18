<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\RefundNotEligibleException;
use App\Repository\CourseEnrollmentRepository;
use App\Repository\CoursePaymentRepository;
use App\Service\EventCancellationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * EventCancellationController — Annulation d'une inscription par le membre.
 *
 * Ce controller gère :
 *   - POST /dashboard/inscriptions/{id}/annuler → annulation par le membre lui-même
 *
 * IMPORTANT : l'annulation ADMIN est gérée dans AdminEventCancellationController.
 *
 * Principe thin controller :
 *   - Validation CSRF
 *   - Vérification que l'inscription appartient à l'utilisateur connecté
 *   - Délégation à EventCancellationService
 *   - Flash message + redirection
 *
 * Sécurité :
 *   - #[IsGranted('IS_AUTHENTICATED_FULLY')] → utilisateur connecté obligatoire
 *   - Vérification que enrollment.user === $this->getUser() (protection appartenance)
 *     → un utilisateur ne peut annuler que SES PROPRES inscriptions
 *   - CSRF token obligatoire (identifiant 'cancel_enrollment_{id}')
 *
 * Pas de Voter dédié ici car la règle est simple (appartenance directe).
 * En V2 avec des règles plus complexes, migrer vers un CancellationVoter.
 */
#[Route('/dashboard', name: 'app_dashboard_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class EventCancellationController extends AbstractController
{
    public function __construct(
        private readonly EventCancellationService   $cancellationService,
        private readonly CourseEnrollmentRepository $enrollmentRepository,
        private readonly CoursePaymentRepository    $paymentRepository,
    ) {}

    /**
     * POST /dashboard/inscriptions/{id}/annuler — Annulation d'une inscription par le membre.
     *
     * Flux :
     *   1. Récupération de l'inscription par ID
     *   2. Vérification CSRF
     *   3. Vérification appartenance (l'inscription appartient à l'utilisateur connecté)
     *   4. Délégation à EventCancellationService::cancelByMember()
     *   5. Flash message + redirect dashboard
     *
     * Le bouton correspondant dans le dashboard n'est affiché que si la fenêtre
     * est ouverte (vérification côté template Twig). Cette route est la protection
     * côté serveur (TOUJOURS valider côté serveur, jamais se fier au frontend seul).
     */
    #[Route('/inscriptions/{id}/annuler', name: 'enrollment_cancel', methods: ['POST'])]
    public function cancelEnrollment(int $id, Request $request): Response
    {
        // ── Récupération de l'inscription ─────────────────────────────────────
        $enrollment = $this->enrollmentRepository->find($id);
        if ($enrollment === null) {
            throw $this->createNotFoundException(sprintf('Inscription #%d introuvable.', $id));
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // ── Vérification CSRF ─────────────────────────────────────────────────
        // Token unique par inscription : 'cancel_enrollment_{id}'
        // Généré dans le template dashboard : {{ csrf_token('cancel_enrollment_' ~ enrollment.id) }}
        if (!$this->isCsrfTokenValid('cancel_enrollment_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_dashboard');
        }

        // ── Vérification appartenance ─────────────────────────────────────────
        // On vérifie que l'inscription appartient BIEN à l'utilisateur connecté.
        // Sans cette vérification, n'importe quel utilisateur connecté pourrait
        // annuler l'inscription de quelqu'un d'autre en devinant l'ID.
        // C'est une erreur de sécurité IDOR (Insecure Direct Object Reference).
        if ($enrollment->getUser()->getId() !== $user->getId()) {
            // On lève un 403 (interdit) et non un 404 pour ne pas "confirmer"
            // l'existence d'une inscription dont l'utilisateur ne devrait pas savoir.
            throw $this->createAccessDeniedException(
                'Vous ne pouvez annuler que vos propres inscriptions.'
            );
        }

        // ── Délégation au service ─────────────────────────────────────────────
        // On pré-lit le paiement AVANT d'appeler cancelByMember() car après l'appel,
        // findCompletedPaymentForRefund() filtre WHERE status='completed' — or processRefund()
        // aura déjà passé le paiement en 'refunded'. La requête retournerait NULL et
        // $wasRefunded serait faussement false (le flash "remboursé" ne s'afficherait jamais).
        // Solution : capturer la présence d'un paiement éligible AVANT l'opération.
        $course              = $enrollment->getCourse();
        $hadCompletedPayment = $this->paymentRepository->findCompletedPaymentForRefund($user, $course) !== null;

        try {
            $this->cancellationService->cancelByMember($enrollment);

            // Si un paiement complété existait avant l'appel, il a forcément été remboursé
            // (cancelByMember() lève une exception si le remboursement échoue, on ne serait
            // pas ici dans ce cas). Si l'événement était gratuit, $hadCompletedPayment = false.
            $wasRefunded = $hadCompletedPayment;

            if ($wasRefunded) {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Votre inscription à « %s » a été annulée et votre paiement sera remboursé '
                        . 'sous 5 à 10 jours ouvrés.',
                        $course->getTitle(),
                    )
                );
            } else {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Votre inscription à « %s » a bien été annulée.',
                        $course->getTitle(),
                    )
                );
            }
        } catch (RefundNotEligibleException $e) {
            // Hors fenêtre de remboursement → message explicatif (pas une erreur technique)
            // On utilise 'warning' (orange) plutôt que 'error' (rouge) car c'est une règle
            // métier, pas une erreur.
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('app_dashboard');
        } catch (\LogicException $e) {
            // Inscription déjà annulée (double-clic, rafraîchissement, etc.)
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_dashboard');
        } catch (\RuntimeException $e) {
            // Erreur Stripe inattendue
            $this->addFlash(
                'error',
                'Une erreur est survenue lors du remboursement. '
                . 'Votre inscription n\'a pas été annulée. '
                . 'Veuillez contacter l\'équipe Bazaart.'
            );
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->redirectToRoute('app_dashboard');
    }
}

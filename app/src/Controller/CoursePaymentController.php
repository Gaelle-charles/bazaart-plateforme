<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CoursePaymentRepository;
use App\Repository\CourseRepository;
use App\Service\EventRegistrationService;
use App\Service\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Stripe\Checkout\Session as StripeSession;

/**
 * CoursePaymentController — Gestion des paiements uniques pour les formations.
 *
 * Ce controller gère le parcours d'achat d'une formation :
 *   1. L'utilisateur clique "Acheter" sur la fiche formation
 *   2. Ce controller crée une session Checkout Stripe (mode=payment)
 *   3. Stripe traite le paiement et envoie le webhook checkout.session.completed
 *   4. StripeWebhookController crée CoursePayment + CourseEnrollment en BDD
 *
 * Rappel métier important (CDC V3) :
 *   Les formations sont TOUJOURS payées séparément, même pour les abonnés.
 *   Un abonnement actif ne donne PAS accès aux formations payantes.
 *
 * Ce controller ne touche PAS à la BDD (pas de flush()) :
 *   Toute la persistance est dans StripeWebhookController.
 */
class CoursePaymentController extends AbstractController
{
    public function __construct(
        private readonly StripeService             $stripeService,
        private readonly CourseRepository          $courseRepository,
        private readonly CoursePaymentRepository   $coursePaymentRepository,
        private readonly EventRegistrationService  $eventRegistrationService,
    ) {}

    // ─── Initiation du paiement d'une formation ───────────────────────────────

    /**
     * POST /formations/{slug}/payer — Lance l'achat d'une formation via Stripe Checkout.
     *
     * Route réservée aux utilisateurs connectés (ROLE_USER).
     * Le slug permet de retrouver la formation en base.
     *
     * Flow :
     *   1. Validation CSRF
     *   2. Récupération de la formation par slug
     *   3. Vérification que la formation est publiée et a un prix configuré
     *   4. Création de la session Checkout Stripe (mode=payment)
     *   5. Redirection vers la page Stripe
     *
     * @param string $slug Le slug URL-friendly de la formation (ex : "initiation-afrobeats")
     */
    #[Route('/formations/{slug}/payer', name: 'app_course_pay', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function pay(string $slug, Request $request): Response
    {
        // ── Validation CSRF ───────────────────────────────────────────────────
        // Le token est généré dans le template de la fiche formation :
        // {{ csrf_token('course_pay_' ~ course.slug) }}
        if (!$this->isCsrfTokenValid('course_pay_' . $slug, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_course_show', ['slug' => $slug]);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // ── Récupération de la formation ──────────────────────────────────────
        $course = $this->courseRepository->findOneBy(['slug' => $slug]);
        if ($course === null) {
            throw $this->createNotFoundException(sprintf('Formation "%s" introuvable.', $slug));
        }

        // ── Vérification que la formation est publiée ─────────────────────────
        if (!$course->isPublished()) {
            throw $this->createNotFoundException('Cette formation n\'est pas encore disponible.');
        }

        // ── Garde anti-double-achat / anti-double-inscription ────────────────
        // Si l'utilisateur a déjà un paiement confirmé pour cette formation,
        // on le redirige directement sans créer une nouvelle session Checkout.
        // Cela évite les doubles débits (deux onglets, double-clic, retour arrière).
        $existingPayment = $this->coursePaymentRepository->findCompletedByUserAndCourse($user, $course);
        if ($existingPayment !== null) {
            $this->addFlash('info', 'Tu as déjà accès à cette formation.');
            // Pour un événement payant : le lien/adresse est sur le dashboard
            // Pour une formation CONTENU : rediriger vers l'espace apprenant
            if ($course->isEvent()) {
                return $this->redirectToRoute('app_dashboard');
            }
            return $this->redirectToRoute('app_course_learn', ['slug' => $slug]);
        }

        // ── Contrôle de capacité pour les événements payants ─────────────────
        // On vérifie la capacité AVANT de lancer le checkout Stripe pour éviter
        // qu'un utilisateur paye une place qui n'existe plus.
        //
        // Note sur la concurrence : dans le cas très rare où deux utilisateurs
        // arrivent simultanément sur la dernière place, les deux sessions Checkout
        // pourraient être créées. Le webhook Stripe gérera la situation en ne créant
        // qu'une seule inscription (contrainte UNIQUE en base). La gestion du
        // remboursement de l'inscription en surplus est reportée en Phase 3.
        if ($course->isEvent() && !$this->eventRegistrationService->hasAvailableSeats($course)) {
            $this->addFlash('error', sprintf(
                'L\'événement "%s" est complet. Il ne reste plus de place disponible.',
                $course->getTitle()
            ));
            return $this->redirectToRoute('app_course_show', ['slug' => $slug]);
        }

        // ── Vérification que la formation a un prix Stripe configuré ──────────
        // Si le prix n'est pas configuré, on ne peut pas initier le paiement.
        if ($course->getStripePriceId() === null || $course->getPriceInCents() === null || $course->getPriceInCents() === 0) {
            $this->addFlash('error', 'Cette formation n\'a pas encore de tarif configuré. Contactez l\'équipe Bazaart.');
            return $this->redirectToRoute('app_course_show', ['slug' => $slug]);
        }

        // ── Création de la session Checkout Stripe ────────────────────────────
        try {
            $successUrl = $this->generateUrl(
                'app_course_payment_success',
                ['slug' => $slug],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $cancelUrl = $this->generateUrl(
                'app_course_payment_cancel',
                ['slug' => $slug],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $checkoutUrl = $this->stripeService->createCourseCheckoutSession(
                user:       $user,
                course:     $course,
                successUrl: $successUrl,
                cancelUrl:  $cancelUrl,
            );
        } catch (\RuntimeException $e) {
            // Formation sans prix Stripe configuré (normalement attrapé plus haut)
            $this->addFlash('error', 'Impossible d\'initier le paiement. Contactez l\'équipe Bazaart.');
            return $this->redirectToRoute('app_course_show', ['slug' => $slug]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Erreur API Stripe
            $this->addFlash('error', 'Une erreur est survenue lors de la connexion au système de paiement. Veuillez réessayer dans quelques instants.');
            return $this->redirectToRoute('app_course_show', ['slug' => $slug]);
        }

        // ── Redirection vers la page de paiement Stripe ───────────────────────
        return new RedirectResponse($checkoutUrl, Response::HTTP_SEE_OTHER);
    }

    // ─── Pages de retour après paiement ──────────────────────────────────────

    /**
     * GET /formations/paiement-succes — Page de confirmation après achat d'une formation.
     *
     * Stripe redirige ici après un paiement réussi en ajoutant ?session_id=cs_xxx.
     * On récupère la session Stripe pour lire le course_slug dans les metadata et
     * afficher le titre de la formation à l'utilisateur.
     *
     * Note : le CourseEnrollment est créé de manière asynchrone par le webhook
     * (checkout.session.completed) — un court délai est possible avant que l'accès
     * soit effectif. On le signale dans le template.
     */
    #[Route('/formations/paiement-succes', name: 'app_course_payment_success', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function paymentSuccess(Request $request): Response
    {
        $course = null;

        // Stripe ajoute ?session_id=cs_xxx dans l'URL de retour.
        // On l'utilise pour récupérer les metadata de la session et afficher la formation.
        $sessionId = $request->query->get('session_id');
        if ($sessionId !== null) {
            try {
                $stripeSession = StripeSession::retrieve($sessionId);
                /** @var string|null $courseSlug */
                $courseSlug = $stripeSession->metadata?->offsetGet('course_slug') ?? null;
                if ($courseSlug !== null) {
                    $course = $this->courseRepository->findOneBy(['slug' => $courseSlug]);
                }
            } catch (\Exception $e) {
                // Si la récupération Stripe échoue, on affiche quand même la page sans le titre
                // (cas rare : session_id invalide ou expirée)
            }
        }

        return $this->render('course_payment/success.html.twig', [
            'course' => $course, // null si session_id absent ou invalide
        ]);
    }

    /**
     * GET /formations/paiement-annule — Page affichée si l'utilisateur annule le paiement.
     *
     * Stripe redirige ici si l'utilisateur clique "Retour" sans compléter le paiement.
     * Aucun CoursePayment ni CourseEnrollment n'est créé.
     * On récupère la formation depuis ?session_id pour afficher son titre.
     */
    #[Route('/formations/paiement-annule', name: 'app_course_payment_cancel', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function paymentCancel(Request $request): Response
    {
        $course = null;

        $sessionId = $request->query->get('session_id');
        if ($sessionId !== null) {
            try {
                $stripeSession = StripeSession::retrieve($sessionId);
                /** @var string|null $courseSlug */
                $courseSlug = $stripeSession->metadata?->offsetGet('course_slug') ?? null;
                if ($courseSlug !== null) {
                    $course = $this->courseRepository->findOneBy(['slug' => $courseSlug]);
                }
            } catch (\Exception $e) {
                // Affichage dégradé sans le titre si la session est invalide
            }
        }

        return $this->render('course_payment/cancel.html.twig', [
            'course' => $course,
        ]);
    }
}

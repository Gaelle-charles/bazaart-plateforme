<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\CourseEnrollmentRepository;
use App\Repository\CoursePaymentRepository;
use App\Repository\PostRepository;
use App\Repository\ResourceRepository;
use App\Repository\SubscriptionRepository;
use App\Service\EventCancellationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord principal — page d'accueil après connexion.
 * Affiche des statistiques globales + des widgets avec les données récentes.
 *
 * Contenu V1 :
 *   - Profile banner (nom, rôle, location)
 *   - Stats strip (soumissions, ressources récentes, posts, notifications)
 *   - Bandeau abonnement
 *   - Widget événements à venir (ajouté Phase 2 Formation ADR-0014)
 *   - Grille principale (ressources + communauté | accès rapides + profil)
 */
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ResourceRepository         $resourceRepository,
        private readonly PostRepository             $postRepository,
        // Pour afficher l'état de l'abonnement de l'utilisateur dans son tableau de bord
        private readonly SubscriptionRepository     $subscriptionRepository,
        // Pour afficher les événements à venir auxquels l'utilisateur est inscrit
        // (ADR-0014 Phase 2 — les inscriptions événements sont visible dans le dashboard)
        private readonly CourseEnrollmentRepository $enrollmentRepository,
        // Pour récupérer les paiements des événements (Phase 3 — vérification éligibilité remboursement)
        private readonly CoursePaymentRepository    $paymentRepository,
        // Pour vérifier si la fenêtre de remboursement est ouverte (Phase 3)
        private readonly EventCancellationService   $cancellationService,
        // Note : CourseProposalRepository retiré (ADR-0012 révisé juin 2026 — widget membre supprimé)
    ) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        // Les admins ont leur propre tableau de bord dédié — on les redirige
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Les 5 dernières ressources publiées (widget "Ressources récentes")
        $recentResources = array_slice($this->resourceRepository->findPublished(), 0, 5);

        // Les 3 derniers posts du fil communauté (widget "Communauté")
        $recentPosts = $this->postRepository->findFeed(3);

        // Nombre total de soumissions de cet utilisateur (stat personnelle)
        $submissions = count($this->resourceRepository->findByUser($user));

        // Abonnement actif (ou null) — alimente le widget « Mon abonnement »
        $activeSubscription = $this->subscriptionRepository->findActiveByUser($user);

        // Note (ADR-0012 révisé, juin 2026) : le widget « Mes formations » membres
        // a été retiré. La variable de propositions n'est plus passée au template.

        // Événements à venir auxquels l'utilisateur est inscrit (ADR-0014 Phase 2).
        // findUpcomingEventsByUser() retourne uniquement les inscriptions à des
        // formations de type EVENEMENT dont eventStartAt est dans le futur.
        // Triées par date la plus proche. [] si aucun événement.
        // Phase 3 : ne retourne que les inscriptions ACTIVES (les CANCELLED sont filtrées).
        $upcomingEvents = $this->enrollmentRepository->findUpcomingEventsByUser($user);

        // ── Phase 3 : calcul des métadonnées d'annulation par inscription ─────
        //
        // Pour chaque inscription à venir, on calcule côté PHP (controller) si
        // le membre peut annuler avec remboursement, annuler sans remboursement,
        // ou pas du tout. Le template Twig n'a qu'à lire ces flags sans logique.
        //
        // Convention : $enrollmentsWithCancelInfo est un tableau de tableaux :
        //   ['enrollment' => CourseEnrollment, 'can_cancel' => bool, 'refund_eligible' => bool]
        //
        // Pourquoi pré-calculer ici et pas dans Twig ?
        //   Twig n'a pas accès aux services. Appeler $cancellationService dans Twig
        //   nécessiterait une Twig Extension — trop lourd pour ce cas.
        //   Pré-calculer dans le controller respecte le principe "thin template".
        $enrollmentsWithCancelInfo = [];
        foreach ($upcomingEvents as $enrollment) {
            $course  = $enrollment->getCourse();
            $payment = $this->paymentRepository->findCompletedPaymentForRefund($user, $course);

            // Un membre peut toujours "annuler" son inscription ACTIVE (libère la place).
            // La question est : peut-il être REMBOURSÉ ?
            $refundEligible = false;
            if ($payment !== null && !$payment->isRefunded()) {
                // Événement payant non encore remboursé → vérifier la fenêtre
                $refundEligible = $this->cancellationService->isEligibleForRefund($payment, $course);
            }

            $enrollmentsWithCancelInfo[] = [
                'enrollment'       => $enrollment,
                // can_cancel : toujours true pour les événements à venir actifs
                // (on pourrait bloquer si eventStartAt < 24h par exemple — V2)
                'can_cancel'       => true,
                // refund_eligible : true si dans la fenêtre ET avant le début
                // false si gratuit ou hors fenêtre (annulation sans remboursement)
                'refund_eligible'  => $refundEligible,
                // is_paid : utile pour afficher "annulation sans remboursement" vs "gratuit"
                'is_paid'          => $payment !== null,
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'user'               => $user,
            'recent_resources'   => $recentResources,
            'recent_posts'       => $recentPosts,
            'submissions'        => $submissions,
            'active_subscription' => $activeSubscription,
            // Liste des inscriptions aux événements à venir (CourseEnrollment[])
            // Chaque élément a getCourse() qui retourne la formation-événement.
            'upcoming_events'    => $upcomingEvents,
            // Phase 3 : enrichi avec les métadonnées d'annulation (can_cancel, refund_eligible, is_paid)
            'enrollments_cancel_info' => $enrollmentsWithCancelInfo,
        ]);
    }
}

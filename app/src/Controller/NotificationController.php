<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * NotificationController — pages et API du système de notifications.
 *
 * Convention du projet : controller "fin".
 *   - Gère HTTP : request, response, CSRF, redirects, flash messages
 *   - Délègue la logique métier à NotificationService
 *   - Délègue les requêtes BDD à NotificationRepository
 *
 * Routes déclarées dans ce controller :
 *   GET  /notifications                     → index (liste des notifs)
 *   POST /notifications/mark-all-read       → marque tout comme lu
 *   POST /notifications/{id}/read           → marque une notif comme lue (AJAX)
 *   GET  /api/notifications/unread-count    → endpoint polling Stimulus
 *
 * ⚠️ Ordre des routes CRITIQUE :
 *   "mark-all-read" DOIT être déclaré AVANT "/{id}/read" dans le fichier.
 *   Sinon Symfony essaierait d'interpréter "mark-all-read" comme un {id} entier
 *   et retournerait une erreur de conversion de type (ParamConverter).
 *
 * ⚠️ La route unreadCount est HORS du préfixe /notifications :
 *   Elle est déclarée directement sur la méthode avec son propre chemin complet.
 *   Le préfixe de classe (#[Route('/notifications')]) ne s'applique PAS aux routes
 *   qui surcharge leur chemin avec un slash en préfixe absolu… mais en Symfony 7,
 *   le préfixe s'additionne toujours. Donc la route API est dans son propre controller.
 *   → Voir ApiNotificationController.php pour l'endpoint /api/notifications/unread-count.
 *
 * Toutes les méthodes de ce controller sont protégées par #[IsGranted('ROLE_USER')]
 * déclaré au niveau de la classe (s'applique à toutes les routes du controller).
 */
#[IsGranted('ROLE_USER')]
#[Route('/notifications', name: 'app_notification_')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationService $notificationService,
    ) {}

    // ─── Page principale ──────────────────────────────────────────────────────

    /**
     * GET /notifications — affiche les 50 dernières notifications de l'utilisateur.
     *
     * En V1, on marque toutes les notifications comme lues dès que l'utilisateur
     * ouvre cette page. C'est simple et suffisant pour le planning serré.
     * (En V2, on pourrait ne marquer comme lues que celles visibles à l'écran.)
     *
     * Workflow :
     *   1. Charge les 50 dernières notifs (lues + non lues, pour affichage)
     *   2. Marque toutes les non-lues comme lues (UPDATE groupé via repository)
     *   3. Rend le template avec la liste
     *
     * Note sur l'ordre des opérations :
     *   On charge les notifs AVANT de les marquer comme lues, pour que le template
     *   puisse encore distinguer les "fraîchement non lues" (isRead=false en mémoire)
     *   des "déjà lues" → style visuel différent lors de cette visite.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Récupère les 50 dernières notifications de l'utilisateur
        // (lues + non lues, triées par date DESC)
        $notifications = $this->notificationRepository->findRecentByUser($user, 50);

        // Marque toutes comme lues APRÈS avoir chargé la liste
        // (UPDATE DQL groupé = une seule requête SQL, efficace)
        $this->notificationService->markAllAsRead($user);

        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
        ]);
    }

    // ─── Action "Tout marquer comme lu" ───────────────────────────────────────

    /**
     * POST /notifications/mark-all-read — marque toutes les notifications comme lues.
     *
     * ⚠️ DOIT être déclarée AVANT /{id}/read dans ce fichier.
     * Symfony compile les routes dans l'ordre de déclaration dans le controller.
     * Si /{id}/read était en premier, "mark-all-read" matcherait le pattern {id}
     * (en tant que string), puis le ParamConverter échouerait à convertir en entité.
     *
     * Sécurité :
     *   1. CSRF d'abord (jeton dans le formulaire Twig)
     *   2. Puis action métier
     */
    #[Route('/mark-all-read', name: 'mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request): Response
    {
        // ── Vérification CSRF ─────────────────────────────────────────────────
        // Le jeton CSRF protège contre les requêtes forgées depuis un site tiers.
        // Convention projet : vérifier CSRF avant toute action de modification.
        if (!$this->isCsrfTokenValid('notification_mark_all_read', $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_notification_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Délègue au service qui fait un UPDATE DQL groupé (efficace)
        $this->notificationService->markAllAsRead($user);

        $this->addFlash('success', 'Toutes vos notifications ont été marquées comme lues.');
        return $this->redirectToRoute('app_notification_index');
    }

    // ─── Action "Marquer une notif comme lue" (AJAX) ─────────────────────────

    /**
     * POST /notifications/{id}/read — marque une notification individuelle comme lue.
     *
     * Cette route est appelée en AJAX depuis les templates Twig (via fetch ou form).
     * Elle retourne toujours du JSON.
     *
     * Symfony ParamConverter : {id} dans l'URL est automatiquement converti en entité
     * Notification par Doctrine (via #[MapEntity] implicite en Symfony 7.x).
     * Si l'ID n'existe pas → 404 automatique.
     *
     * Sécurité :
     *   1. CSRF d'abord (jeton unique par notification : 'notification_read_{id}')
     *   2. Vérification d'appartenance dans NotificationService::markAsRead()
     */
    #[Route('/{id}/read', name: 'mark_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request): JsonResponse
    {
        // ── Vérification CSRF ─────────────────────────────────────────────────
        // Le jeton est unique par notification ID : 'notification_read_42'
        // Cela empêche de réutiliser un jeton d'une notif pour une autre.
        if (!$this->isCsrfTokenValid(
            'notification_read_' . $notification->getId(),
            $request->request->get('_token')
        )) {
            return new JsonResponse(['success' => false, 'error' => 'Jeton CSRF invalide'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Le service vérifie que la notification appartient bien à cet user.
        // Si non → false → on retourne un 403 explicite.
        // Avant ce correctif, on retournait {"success": true} même en cas d'accès
        // non autorisé (IDOR masqué : fail-silent trompeur pour le monitoring).
        if (!$this->notificationService->markAsRead($notification, $user)) {
            return new JsonResponse(['success' => false, 'error' => 'Accès interdit.'], Response::HTTP_FORBIDDEN);
        }

        // Retourne JSON pour que le JS puisse mettre à jour l'UI sans reload
        return new JsonResponse(['success' => true]);
    }
}

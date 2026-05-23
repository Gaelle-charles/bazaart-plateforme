<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ApiNotificationController — endpoint JSON pour le polling du badge sidebar.
 *
 * Pourquoi un controller séparé ?
 *   La route /api/notifications/unread-count ne peut pas être dans NotificationController
 *   (qui a le préfixe /notifications sur la classe). En Symfony, le préfixe de classe
 *   est TOUJOURS ajouté, donc #[Route('/api/notifications/...')] dans un controller avec
 *   préfixe /notifications donnerait /notifications/api/notifications/... → incorrect.
 *
 *   Solution propre : un mini-controller dédié à l'API sans préfixe de classe.
 *
 * Cette route est appelée par notification_badge_controller.js (Stimulus) toutes les 60s.
 *
 * Sécurité :
 *   - #[IsGranted('ROLE_USER')] → seuls les utilisateurs connectés peuvent accéder
 *   - Pas de CSRF : c'est un GET (lecture seule, idempotent)
 *
 * Format de réponse : {"count": 3}
 */
#[IsGranted('ROLE_USER')]
class ApiNotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
    ) {}

    /**
     * GET /api/notifications/unread-count
     *
     * Retourne le nombre de notifications non lues pour l'utilisateur connecté.
     * Appelé en AJAX par le contrôleur Stimulus (polling toutes les 60 secondes).
     *
     * Optimisé : utilise countUnreadByUser() qui fait un SELECT COUNT(*)
     * (pas de chargement des entités complètes → très léger).
     */
    #[Route('/api/notifications/unread-count', name: 'app_api_notification_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // COUNT(*) SQL direct via le repository — efficace même à grande échelle
        $count = $this->notificationRepository->countUnreadByUser($user);

        // Format JSON attendu par notification_badge_controller.js :
        //   {"count": 3}
        return new JsonResponse(['count' => $count]);
    }
}

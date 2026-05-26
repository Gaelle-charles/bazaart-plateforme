<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * NotificationService — logique métier du système de notifications Bazaart.
 *
 * Ce service centralise toute la création et la gestion des notifications.
 * Il est appelé par les autres services (MessagingService, ForumService)
 * après leurs opérations métier pour déclencher les notifications appropriées.
 *
 * Principe de séparation des responsabilités :
 *   - NotificationController : gère HTTP (pages, API polling)
 *   - NotificationRepository : gère les requêtes BDD (lecture, comptage, UPDATE groupé)
 *   - NotificationService    : gère la logique (création, marquage lu, anti-doublons)
 *
 * Règle importante : une notification n'est JAMAIS envoyée à l'auteur de l'action.
 * Ex: si Marie répond à son propre thread, elle ne reçoit pas de notification "NewReply".
 * Cette vérification (recipient !== expéditeur) est faite dans create().
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notificationRepository,
    ) {}

    // ─── Création ─────────────────────────────────────────────────────────────

    /**
     * Crée et persiste une nouvelle notification pour un destinataire.
     *
     * Cette méthode est le point d'entrée unique pour créer des notifications.
     * Tous les autres services (MessagingService, ForumService) l'appellent.
     *
     * Règle anti-auto-notification :
     *   Si l'appelant passe un $sender, on vérifie que recipient !== sender.
     *   Même id() = même utilisateur (Doctrine garantit l'unicité dans son Unit of Work).
     *   Exemple : si Jean répond à son propre thread, il ne reçoit pas de notif.
     *
     * Le flush() est appelé à l'intérieur → une seule transaction par notification.
     * Pour des créations groupées (ex: alerte ressource vers N users), prévoir
     * une méthode createBatch() sans flush() individuel (à ajouter si besoin).
     *
     * @param array<string, mixed> $data Données complémentaires (titre, excerpt, email expéditeur…)
     * @param User|null $sender Si fourni, empêche l'auto-notification (recipient === sender)
     */
    public function create(
        User $recipient,
        NotificationType $type,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
        array $data = [],
        ?User $sender = null,
    ): void {
        // ── Règle anti-auto-notification ─────────────────────────────────────
        // Si l'expéditeur est passé et qu'il est le même que le destinataire,
        // on n'envoie rien. Comparer les IDs (int) est plus sûr que l'identité
        // d'objet (===) car $sender et $recipient peuvent venir de contextes
        // Doctrine différents (sessions, requêtes séparées).
        if ($sender !== null && $sender->getId() === $recipient->getId()) {
            // L'utilisateur s'est envoyé une notification à lui-même → on ignore
            return;
        }

        // ── Construction de la notification ──────────────────────────────────

        $notification = new Notification();
        $notification->setRecipient($recipient);
        $notification->setType($type);
        $notification->setRelatedEntityType($relatedEntityType);
        $notification->setRelatedEntityId($relatedEntityId);

        // On ne stocke les données complémentaires que si elles ne sont pas vides
        // (évite une colonne JSON à null inutile en BDD)
        if (!empty($data)) {
            $notification->setData($data);
        }

        // ── Persistance ───────────────────────────────────────────────────────

        // persist() enregistre l'entité dans le Unit of Work de Doctrine
        $this->em->persist($notification);
        // flush() déclenche l'INSERT SQL réel
        $this->em->flush();
    }

    /**
     * Crée et persiste une notification SANS flush immédiat.
     *
     * À utiliser dans les boucles de création groupée (ex: NewLive pour N utilisateurs).
     * Évite N transactions SQL individuelles — l'appelant gère un seul flush() après la boucle.
     *
     * Voir create() pour la version avec flush immédiat (notifications unitaires).
     *
     * @param array<string, mixed> $data
     */
    public function createBatch(
        User $recipient,
        NotificationType $type,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
        array $data = [],
        ?User $sender = null,
    ): void {
        // Règle anti-auto-notification — identique à create()
        if ($sender !== null && $sender->getId() === $recipient->getId()) {
            return;
        }

        $notification = new Notification();
        $notification->setRecipient($recipient);
        $notification->setType($type);
        $notification->setRelatedEntityType($relatedEntityType);
        $notification->setRelatedEntityId($relatedEntityId);

        if (!empty($data)) {
            $notification->setData($data);
        }

        // persist() uniquement — PAS de flush(). L'appelant est responsable du flush groupé.
        $this->em->persist($notification);
    }

    // ─── Marquage comme lu ────────────────────────────────────────────────────

    /**
     * Marque une notification individuelle comme lue.
     *
     * Vérification de propriété : on s'assure que la notification appartient
     * bien à l'utilisateur qui la marque.
     *
     * Retourne true si le marquage a réussi, false si la notification n'appartient
     * pas à l'utilisateur. Le controller utilise ce booléen pour retourner un 403
     * explicite — évite le "fail-silent" qui répondait toujours {"success": true}
     * même en cas d'accès non autorisé (IDOR masqué).
     */
    public function markAsRead(Notification $notification, User $user): bool
    {
        // Vérification que la notification appartient bien à cet utilisateur.
        // Comparaison par ID pour les mêmes raisons que dans create() :
        // deux objets User du même utilisateur peuvent ne pas être identiques (===)
        // s'ils viennent de contextes Doctrine différents.
        if ($notification->getRecipient()->getId() !== $user->getId()) {
            // La notification n'appartient pas à cet utilisateur.
            // On retourne false pour que le controller puisse renvoyer un 403.
            return false;
        }

        // Marque comme lue (modifie l'entité en mémoire)
        $notification->markAsRead();
        // Sauvegarde le changement en BDD
        $this->em->flush();

        return true;
    }

    /**
     * Marque TOUTES les notifications non lues d'un utilisateur comme lues.
     *
     * Délègue au repository qui fait un UPDATE DQL groupé (une seule requête SQL)
     * au lieu d'une boucle PHP qui ferait N UPDATE séparés.
     *
     * Appelé par :
     *   - NotificationController::index() : en V1, on marque tout comme lu
     *     dès que l'utilisateur ouvre la page /notifications.
     *   - NotificationController::markAllRead() : action "Tout marquer comme lu".
     */
    public function markAllAsRead(User $user): void
    {
        // L'UPDATE DQL dans le repository est efficace même pour des centaines de notifs.
        // Il met à jour isRead et readAt en une seule requête SQL.
        $this->notificationRepository->markAllAsReadForUser($user);

        // ⚠️ Après un UPDATE DQL, le Unit of Work de Doctrine ne sait pas que les entités
        // Notification ont changé en base. Si des instances de Notification sont déjà chargées
        // en mémoire (dans l'Identity Map de Doctrine), elles gardent leur ancien état :
        //   $notification->isRead() → false  (alors que la BDD dit true)
        //
        // em->clear(Notification::class) vide le cache de premier niveau pour cette entité.
        // La prochaine fois qu'on charge une Notification, Doctrine la relit depuis la BDD.
        //
        // Note : cela invalide TOUTES les instances Notification du Unit of Work courant.
        // C'est acceptable ici car markAllAsRead() est appelé en fin de requête
        // (page /notifications ou action "Tout marquer comme lu").
        $this->em->clear(Notification::class);
    }
}

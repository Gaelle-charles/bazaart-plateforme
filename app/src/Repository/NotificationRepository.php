<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * NotificationRepository — requêtes Doctrine pour l'entité Notification.
 *
 * Convention projet : TOUTE la logique de requête BDD vit dans les repositories.
 * Les services et controllers ne font jamais de createQueryBuilder() directement.
 *
 * Ce repository expose 4 méthodes publiques :
 *   - findUnreadByUser()     : notifs non lues (pour la page /notifications ouverte)
 *   - findRecentByUser()     : toutes les notifs récentes (liste paginée)
 *   - countUnreadByUser()    : compteur pour le badge sidebar et l'API polling
 *   - markAllAsReadForUser() : UPDATE groupé pour "tout marquer comme lu"
 *
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    // ─── Lecture ──────────────────────────────────────────────────────────────

    /**
     * Retourne toutes les notifications NON LUES d'un utilisateur, triées par date DESC.
     *
     * Utilisé pour afficher les nouvelles notifs dans l'interface
     * (par exemple dans un dropdown ou lors de l'ouverture de /notifications).
     *
     * Tri DESC (plus récentes en premier) : l'utilisateur voit les événements
     * les plus récents sans avoir à scroller.
     *
     * @return Notification[]
     */
    public function findUnreadByUser(User $user): array
    {
        return $this->createQueryBuilder('n')
            // Filtre sur le destinataire
            ->andWhere('n.recipient = :user')
            // Filtre sur les non lues uniquement
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            // Tri chronologique inverse (plus récente en premier)
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les N dernières notifications d'un utilisateur (lues + non lues).
     *
     * Utilisé sur la page /notifications pour afficher l'historique complet.
     * La limite évite de charger des milliers de lignes pour un utilisateur actif.
     *
     * @return Notification[]
     */
    public function findRecentByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            // setMaxResults() correspond au LIMIT SQL
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // ─── Compteur ─────────────────────────────────────────────────────────────

    /**
     * Compte les notifications non lues d'un utilisateur.
     *
     * Retourne un int directement (pas une collection).
     * Utilisé par :
     *   - NotificationExtension::getUnreadCount() → badge dans la sidebar (chaque page)
     *   - NotificationController::unreadCount()   → endpoint API polling Stimulus (60s)
     *
     * Optimisation : getSingleScalarResult() exécute un SELECT COUNT(*)
     * sans charger les entités → très rapide, même pour des milliers de notifs.
     */
    public function countUnreadByUser(User $user): int
    {
        // getSingleScalarResult retourne directement la valeur scalaire (le count)
        // intval() assure que le retour est bien un int (Doctrine retourne une string en PG)
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ─── Mise à jour groupée ──────────────────────────────────────────────────

    /**
     * Marque TOUTES les notifications non lues d'un utilisateur comme lues.
     *
     * Pourquoi un UPDATE DQL plutôt qu'une boucle PHP ?
     *   - Pattern boucle PHP : charge TOUTES les entités en mémoire (N objets),
     *     puis fait N UPDATE SQL séparés. Très lent et gourmand en mémoire.
     *   - Pattern UPDATE DQL : un seul UPDATE SQL qui touche toutes les lignes
     *     en une seule requête → O(1) quelle que soit la quantité de notifs.
     *
     * ⚠️ Important : un UPDATE DQL bypass le Unit of Work de Doctrine.
     * Les entités déjà chargées en mémoire ne sont PAS automatiquement mises à jour.
     * Pour garantir la cohérence, on appelle clear() sur l'EntityManager après
     * (si nécessaire dans NotificationService).
     *
     * @throws \Doctrine\ORM\Exception\ORMException
     */
    public function markAllAsReadForUser(User $user): void
    {
        // DQL UPDATE : syntaxe proche du SQL mais orienté objet (on cible l'entité, pas la table)
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', 'true')
            ->andWhere('n.recipient = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}

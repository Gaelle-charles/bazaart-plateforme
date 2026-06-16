<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumReaction;
use App\Entity\ForumReply;
use App\Entity\ForumThread;
use App\Entity\User;
use App\Enum\ForumReactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ForumReactionRepository — requêtes sur les réactions forum.
 *
 * @extends ServiceEntityRepository<ForumReaction>
 *
 * Stratégie anti-N+1 :
 *   Sur une page de thread avec 30 réponses, charger les réactions réponse
 *   par réponse en boucle (N+1 requêtes) serait catastrophique pour les performances.
 *   Ce repository expose des méthodes "batch" qui chargent les comptages en une
 *   seule requête SQL GROUP BY, puis le code PHP restructure le résultat.
 */
class ForumReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumReaction::class);
    }

    // ─── Méthodes pour UNE cible (thread ou reply) ────────────────────────────

    /**
     * Compte les réactions par type sur un thread.
     *
     * Retourne un tableau associatif de toutes les valeurs d'enum initialisées à 0,
     * puis rempli avec les vrais comptes.
     *
     * Exemple de retour :
     *   ['like' => 5, 'fire' => 2, 'bravo' => 0, 'heart' => 1, 'idea' => 0]
     *
     * Initialiser à 0 : évite les undefined key dans Twig/JS même pour les types sans réaction.
     *
     * @return array<string, int> clé = valeur backed de l'enum, valeur = nombre de réactions
     */
    public function countByThread(ForumThread $thread): array
    {
        // On initialise tous les types à 0 pour avoir une structure complète
        $counts = $this->initEmptyCounts();

        // Requête SQL : GROUP BY type pour compter chaque type en une seule requête
        $rows = $this->createQueryBuilder('r')
            ->select('r.type AS type, COUNT(r.id) AS cnt')
            ->where('r.thread = :thread')
            ->setParameter('thread', $thread)
            ->groupBy('r.type')
            ->getQuery()
            ->getResult();

        // Fusion avec le tableau initialisé à 0.
        // Doctrine ORM 3 hydrate déjà r.type en ForumReactionType (colonne enumType),
        // y compris en hydratation scalaire — pas besoin de re-tester le type.
        foreach ($rows as $row) {
            $counts[$row['type']->value] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Compte les réactions par type sur une réponse.
     *
     * Même structure de retour que countByThread().
     *
     * @return array<string, int>
     */
    public function countByReply(ForumReply $reply): array
    {
        $counts = $this->initEmptyCounts();

        $rows = $this->createQueryBuilder('r')
            ->select('r.type AS type, COUNT(r.id) AS cnt')
            ->where('r.reply = :reply')
            ->setParameter('reply', $reply)
            ->groupBy('r.type')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $counts[$row['type']->value] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Retourne les types de réactions d'un utilisateur sur un thread.
     *
     * Utilisé pour surligner les boutons de réaction déjà posés par l'utilisateur.
     * Exemple : si le user a posé 'like' et 'fire', retourne ['like', 'fire'].
     *
     * @return string[] tableau de valeurs backed (ex: ['like', 'fire'])
     */
    public function findUserReactionsOnThread(User $user, ForumThread $thread): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.type AS type')
            ->where('r.user = :user')
            ->andWhere('r.thread = :thread')
            ->setParameter('user', $user)
            ->setParameter('thread', $thread)
            ->getQuery()
            ->getResult();

        // On extrait la valeur backed (string) pour faciliter la sérialisation JSON
        return array_map(
            static fn (array $row): string => $row['type']->value,
            $rows
        );
    }

    /**
     * Retourne les types de réactions d'un utilisateur sur une réponse.
     *
     * @return string[]
     */
    public function findUserReactionsOnReply(User $user, ForumReply $reply): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.type AS type')
            ->where('r.user = :user')
            ->andWhere('r.reply = :reply')
            ->setParameter('user', $user)
            ->setParameter('reply', $reply)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): string => $row['type']->value,
            $rows
        );
    }

    /**
     * Cherche une réaction existante d'un utilisateur sur un thread avec un type donné.
     * Utilisé par ForumService::toggleReaction() pour le mécanisme d'ajout/suppression.
     */
    public function findByUserThreadAndType(
        User $user,
        ForumThread $thread,
        ForumReactionType $type
    ): ?ForumReaction {
        return $this->findOneBy([
            'user'   => $user,
            'thread' => $thread,
            'type'   => $type,
        ]);
    }

    /**
     * Cherche une réaction existante d'un utilisateur sur une réponse avec un type donné.
     */
    public function findByUserReplyAndType(
        User $user,
        ForumReply $reply,
        ForumReactionType $type
    ): ?ForumReaction {
        return $this->findOneBy([
            'user'  => $user,
            'reply' => $reply,
            'type'  => $type,
        ]);
    }

    // ─── Méthodes "batch" anti-N+1 pour une page de thread ────────────────────

    /**
     * Charge les comptages de réactions pour un thread ET toutes ses réponses en une seule requête.
     *
     * Problème N+1 évité :
     *   Sans cette méthode, on ferait 1 requête par entité (thread + N réponses).
     *   Sur un thread avec 30 réponses : 31 requêtes SQL pour afficher les compteurs.
     *   Avec cette méthode : 1 seule requête SQL, résultat restructuré en PHP.
     *
     * Utilisation côté controller / Twig :
     *   $batchCounts = $reactionRepo->countForThreadAndReplies($thread, $replies);
     *   $batchCounts['thread'][$thread->getId()] retourne ['like' => 5, 'fire' => 2, ...]
     *   $batchCounts['reply'][42]                 retourne ['like' => 1, 'bravo' => 3, ...]
     *
     * @param ForumReply[] $replies Toutes les réponses du thread
     * @return array{thread: array<int, array<string, int>>, reply: array<int, array<string, int>>}
     */
    public function countForThreadAndReplies(ForumThread $thread, array $replies): array
    {
        // Structure de retour initialisée
        $result = [
            'thread' => [$thread->getId() => $this->initEmptyCounts()],
            'reply'  => [],
        ];

        // Initialiser à 0 pour chaque réponse
        foreach ($replies as $reply) {
            $result['reply'][$reply->getId()] = $this->initEmptyCounts();
        }

        $threadId = $thread->getId();
        if (empty($threadId)) {
            return $result;
        }

        // On ne retient que les IDs de réponses appartenant RÉELLEMENT à ce thread.
        // Robustesse : la méthode est publique ; on ne dépend pas de l'appelant pour
        // garantir que $replies appartient bien à $thread (sinon on pourrait compter
        // des réactions d'un autre thread).
        $replyIds = $this->collectReplyIds($replies, $threadId);

        // Requête unique : réactions sur CE thread OU sur l'une de SES réponses.
        $qb = $this->createQueryBuilder('r')
            ->select(
                'r.type AS type',
                'COUNT(r.id) AS cnt',
                // IDENTITY() retourne l'ID de la FK (thread_id ou reply_id)
                'IDENTITY(r.thread) AS thread_id',
                'IDENTITY(r.reply) AS reply_id'
            )
            ->groupBy('r.type, r.thread, r.reply')
            ->setParameter('thread', $thread);

        if (!empty($replyIds)) {
            // (r.thread = :thread) OR (r.reply IN (:replyIds)) — groupé explicitement
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('r.thread', ':thread'),
                    $qb->expr()->in('r.reply', ':replyIds')
                )
            )->setParameter('replyIds', $replyIds);
        } else {
            $qb->where('r.thread = :thread');
        }

        $rows = $qb->getQuery()->getResult();

        // Restructuration du résultat en PHP
        foreach ($rows as $row) {
            $typeKey = $row['type']->value;
            $count   = (int) $row['cnt'];

            if ($row['thread_id'] !== null && (int) $row['thread_id'] === $thread->getId()) {
                // C'est une réaction sur le thread principal
                $result['thread'][$thread->getId()][$typeKey] = $count;
            } elseif ($row['reply_id'] !== null) {
                // C'est une réaction sur une réponse
                $replyId = (int) $row['reply_id'];
                if (isset($result['reply'][$replyId])) {
                    $result['reply'][$replyId][$typeKey] = $count;
                }
            }
        }

        return $result;
    }

    /**
     * Charge toutes les réactions d'un utilisateur pour un thread et ses réponses.
     *
     * Pendant que countForThreadAndReplies() donne les comptages GLOBAUX,
     * cette méthode donne les réactions du USER CONNECTÉ pour surligner ses propres réactions.
     *
     * Retourne :
     *   ['thread' => [threadId => ['like', 'fire']], 'reply' => [replyId => ['bravo']]]
     *
     * @param ForumReply[] $replies
     * @return array{thread: array<int, string[]>, reply: array<int, string[]>}
     */
    public function findUserReactionsForThreadAndReplies(
        User $user,
        ForumThread $thread,
        array $replies
    ): array {
        $result = [
            'thread' => [$thread->getId() => []],
            'reply'  => [],
        ];

        foreach ($replies as $reply) {
            $result['reply'][$reply->getId()] = [];
        }

        $threadId = $thread->getId();
        $replyIds = $threadId === null ? [] : $this->collectReplyIds($replies, $threadId);

        // Réactions de CET utilisateur sur le thread OU sur ses réponses.
        // On borne d'abord sur l'utilisateur (andWhere), puis sur la cible (orX).
        $qb = $this->createQueryBuilder('r')
            ->select(
                'r.type AS type',
                'IDENTITY(r.thread) AS thread_id',
                'IDENTITY(r.reply) AS reply_id'
            )
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->setParameter('thread', $thread);

        if (!empty($replyIds)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->eq('r.thread', ':thread'),
                    $qb->expr()->in('r.reply', ':replyIds')
                )
            )->setParameter('replyIds', $replyIds);
        } else {
            $qb->andWhere('r.thread = :thread');
        }

        $rows = $qb->getQuery()->getResult();

        foreach ($rows as $row) {
            $typeValue = $row['type']->value;

            if ($row['thread_id'] !== null && (int) $row['thread_id'] === $thread->getId()) {
                $result['thread'][$thread->getId()][] = $typeValue;
            } elseif ($row['reply_id'] !== null) {
                $replyId = (int) $row['reply_id'];
                if (isset($result['reply'][$replyId])) {
                    $result['reply'][$replyId][] = $typeValue;
                }
            }
        }

        return $result;
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    /**
     * Extrait les IDs des réponses appartenant réellement au thread donné.
     *
     * Filtre de robustesse : on ignore toute réponse dont l'ID est null (non
     * persistée) ou dont le thread parent ne correspond pas à $threadId. Cela
     * garantit qu'on ne mélange jamais les réactions de threads différents, même
     * si l'appelant fournit une liste de réponses incohérente.
     *
     * @param ForumReply[] $replies
     * @return int[]
     */
    private function collectReplyIds(array $replies, int $threadId): array
    {
        $ids = [];
        foreach ($replies as $reply) {
            $id = $reply->getId();
            if ($id !== null && $reply->getThread()->getId() === $threadId) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Initialise un tableau de comptages à 0 pour tous les types de réaction.
     *
     * Cela garantit que tous les types sont présents dans le retour,
     * même si aucune réaction de ce type n'existe encore — évite les
     * undefined key dans Twig et dans le JSON retourné au front.
     *
     * @return array<string, int>
     */
    private function initEmptyCounts(): array
    {
        $counts = [];
        foreach (ForumReactionType::cases() as $case) {
            $counts[$case->value] = 0;
        }
        return $counts;
    }
}

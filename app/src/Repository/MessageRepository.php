<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * MessageRepository — requêtes liées aux messages privés.
 *
 * Ce repository gère :
 *   - La récupération des messages d'une conversation
 *   - Le calcul des compteurs de messages non lus
 *
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Retourne tous les messages d'une conversation en ordre chronologique.
     *
     * L'ordre ASC (du plus ancien au plus récent) correspond à la lecture naturelle
     * d'une conversation : on lit du haut vers le bas.
     *
     * JOIN FETCH sur l'auteur : évite N+1 lors de l'affichage du fil
     * (chaque message affiche l'email/nom de l'auteur sans requête supplémentaire).
     *
     * @return Message[]
     */
    public function findByConversation(Conversation $conversation): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.author', 'a')
            ->addSelect('a')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le total de messages non lus pour un utilisateur dans TOUTES ses conversations.
     *
     * Utilisé pour le badge global "X messages non lus" (prévu pour la sidebar en V2).
     *
     * La requête joint :
     *   - messages (m)
     *   - participants (cp) → pour trouver le participant courant et son lastReadAt
     *   - conversation (c) → pour lier messages et participants
     *
     * Filtre composite :
     *   1. cp.user = l'utilisateur courant (on ne compte que ses conversations)
     *   2. m.author != l'utilisateur courant (pas ses propres messages)
     *   3. m.createdAt > cp.lastReadAt OU cp.lastReadAt IS NULL (non lus)
     *
     * TODO V2 : brancher ce compteur sur la sidebar via un EventListener ou Twig Extension.
     */
    public function countAllUnreadForUser(User $user): int
    {
        $qb = $this->createQueryBuilder('m');

        return (int) $qb
            ->select('COUNT(m.id)')
            ->innerJoin('m.conversation', 'c')
            ->innerJoin('c.participants', 'cp')
            ->where('cp.user = :user')
            ->setParameter('user', $user)
            ->andWhere('m.author != :user')
            // Expr::orX() est plus sûr qu'une chaîne brute : composable, typé, sans risque
            // d'ambiguïté si d'autres andWhere() sont ajoutés ultérieurement.
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('cp.lastReadAt'),
                    $qb->expr()->gt('m.createdAt', 'cp.lastReadAt')
                )
            )
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les messages non lus par conversation pour un utilisateur — en UNE seule requête.
     *
     * Retourne un tableau associatif [conversationId => count].
     * Utilisé dans MessagingController::index() pour éviter le N+1
     * (une requête COUNT par conversation serait problématique à l'échelle).
     *
     * Exemple de retour : [42 => 3, 17 => 0] si la conv 42 a 3 non lus et la 17 aucun.
     * Les conversations avec 0 non lu n'apparaissent PAS dans le tableau
     * (elles ne matchent aucun message non lu) — le controller remplace les clés absentes par 0.
     *
     * @return array<int, int> [conversationId => unreadCount]
     */
    public function countUnreadGroupedByConversation(User $user): array
    {
        $qb = $this->createQueryBuilder('m');

        // IDENTITY(m.conversation) : retourne l'ID de la conversation sans JOIN supplémentaire.
        // C'est une fonction Doctrine DQL qui extrait la clé étrangère directement.
        $rows = $qb
            ->select('IDENTITY(m.conversation) AS conv_id, COUNT(m.id) AS cnt')
            ->innerJoin('m.conversation', 'c')
            ->innerJoin('c.participants', 'cp')
            ->where('cp.user = :user')
            ->setParameter('user', $user)
            ->andWhere('m.author != :user')
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('cp.lastReadAt'),
                    $qb->expr()->gt('m.createdAt', 'cp.lastReadAt')
                )
            )
            // GROUP BY conversation : une ligne par conversation
            ->groupBy('m.conversation')
            ->getQuery()
            ->getArrayResult();

        // Transforme [['conv_id' => 42, 'cnt' => 3], ...] en [42 => 3, ...]
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['conv_id']] = (int) $row['cnt'];
        }
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ConversationRepository — requêtes BDD liées aux conversations.
 *
 * Convention du projet : toute la logique de requête Doctrine vit dans les repositories.
 * Les controllers et services ne font jamais de DQL ou QueryBuilder directement.
 *
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Retourne toutes les conversations auxquelles l'utilisateur participe.
     *
     * Tri : les conversations avec l'activité la plus récente en premier.
     *   - lastMessageAt DESC NULLS LAST → conversations avec messages récents en tête
     *   - createdAt DESC → conversations sans message : les plus récentes d'abord
     *
     * Optimisation N+1 avec JOIN FETCH :
     *   Sans JOIN FETCH, Doctrine ferait une requête SQL supplémentaire pour chaque
     *   conversation afin de charger ses participants (problème N+1 = N conversations
     *   = N requêtes supplémentaires). Avec JOIN FETCH, tout est chargé en une seule requête.
     *
     *   On charge :
     *   - 'cp' → les ConversationParticipant de chaque conversation
     *   - 'u'  → le User de chaque participant (pour afficher le nom/email)
     *
     * @return Conversation[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            // Jointure sur les participants pour filtrer les conversations de cet user
            // Note : on utilise deux aliases différents pour les participants :
            //   - 'mycp' pour le participant qui est l'user courant (pour le filtre WHERE)
            //   - 'cp' pour tous les participants (pour le SELECT, y compris l'autre)
            ->innerJoin('c.participants', 'mycp')
            ->innerJoin('mycp.user', 'myuser')
            ->where('myuser = :user')
            ->setParameter('user', $user)
            // On charge TOUS les participants (y compris l'autre) pour l'affichage
            ->leftJoin('c.participants', 'cp')
            ->addSelect('cp')
            ->leftJoin('cp.user', 'u')
            ->addSelect('u')
            // Tri par dernière activité.
            // ⚠️ PostgreSQL spécifique : ORDER BY lastMessageAt DESC place les NULL EN TÊTE
            // (comportement opposé à MySQL). On utilise COALESCE pour substituer les NULL
            // par une date ancienne, ce qui les renvoie en fin de liste.
            // FORMAT : COALESCE(lastMessageAt, '1970-01-01') DESC
            // — conversations sans message → date fictive 1970 → classées en dernier.
            // 'HIDDEN' dans addSelect indique à Doctrine de ne pas inclure ce champ
            // dans les résultats hydratés (c'est uniquement une clé de tri).
            ->addSelect("COALESCE(c.lastMessageAt, '1970-01-01 00:00:00') AS HIDDEN sortKey")
            ->orderBy('sortKey', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve la conversation privée entre deux utilisateurs.
     *
     * Utilisé dans MessagingService::initiateConversation() pour éviter les doublons :
     * si une conversation entre userA et userB existe déjà, on la retourne
     * plutôt que d'en créer une nouvelle.
     *
     * Requête DQL expliquée :
     *   On cherche une conversation 'c' telle que :
     *     - userA est participant (sous-requête : c est dans les convs de userA)
     *     - userB est aussi participant (sous-requête : c est dans les convs de userB)
     *
     * Alternative : on utilise deux INNER JOIN sur les participants
     * avec des conditions WHERE différentes.
     *
     * On charge également les participants pour éviter du lazy loading ensuite.
     */
    public function findBetweenUsers(User $userA, User $userB): ?Conversation
    {
        return $this->createQueryBuilder('c')
            // Premier participant = userA
            ->innerJoin('c.participants', 'cpA')
            ->innerJoin('cpA.user', 'uA')
            ->andWhere('uA = :userA')
            ->setParameter('userA', $userA)
            // Deuxième participant = userB (dans la même conversation)
            ->innerJoin('c.participants', 'cpB')
            ->innerJoin('cpB.user', 'uB')
            ->andWhere('uB = :userB')
            ->setParameter('userB', $userB)
            // On pré-charge les participants pour le service appelant
            ->leftJoin('c.participants', 'cp')
            ->addSelect('cp')
            ->leftJoin('cp.user', 'u')
            ->addSelect('u')
            ->getQuery()
            ->getOneOrNullResult();
    }
}

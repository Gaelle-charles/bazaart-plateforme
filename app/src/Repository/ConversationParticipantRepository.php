<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConversationParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ConversationParticipantRepository — requêtes sur les participants de conversations.
 *
 * Utilisé principalement par MessagingService pour retrouver le participant
 * correspondant à un utilisateur dans une conversation donnée.
 *
 * @extends ServiceEntityRepository<ConversationParticipant>
 */
class ConversationParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationParticipant::class);
    }
}

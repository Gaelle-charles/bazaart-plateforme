<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumReply;
use App\Entity\ForumThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les réponses du forum.
 *
 * @extends ServiceEntityRepository<ForumReply>
 */
class ForumReplyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumReply::class);
    }

    /**
     * Retourne toutes les réponses d'un thread, dans l'ordre chronologique.
     *
     * L'ordre ASC (du plus ancien au plus récent) est la convention standard
     * pour les forums : on lit le fil de discussion de haut en bas dans l'ordre
     * où les messages ont été postés.
     *
     * Jointures FETCH :
     *   - author : pour afficher le nom/email de l'auteur sans requête lazy
     *   - parentReply : pour les replies imbriquées (V2) — LEFT JOIN car nullable
     *
     * @return ForumReply[]
     */
    public function findByThread(ForumThread $thread): array
    {
        return $this->createQueryBuilder('r')
            // Charge l'auteur de chaque réponse en une seule requête
            ->leftJoin('r.author', 'a')
            ->addSelect('a')
            // Charge la réponse parente (nullable — LEFT JOIN important)
            ->leftJoin('r.parentReply', 'p')
            ->addSelect('p')
            // Filtre : réponses de ce thread uniquement
            ->where('r.thread = :thread')
            ->setParameter('thread', $thread)
            // Tri chronologique : la première réponse en haut, la dernière en bas
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de réponses d'un thread.
     *
     * Utile pour valider la cohérence entre repliesCount (dénormalisé sur ForumThread)
     * et le vrai compte en base — peut servir dans une commande de maintenance.
     */
    public function countByThread(ForumThread $thread): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.thread = :thread')
            ->setParameter('thread', $thread)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

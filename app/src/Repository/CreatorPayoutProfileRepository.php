<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CreatorPayoutProfile;
use App\Entity\User;
use App\Enum\CreatorVerificationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * CreatorPayoutProfileRepository — requêtes Doctrine sur les profils de versement créateur.
 *
 * @extends ServiceEntityRepository<CreatorPayoutProfile>
 *
 * Convention du projet : toute la logique de requête est ici, jamais dans les contrôleurs.
 */
class CreatorPayoutProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreatorPayoutProfile::class);
    }

    /**
     * Retourne le profil de versement d'un utilisateur, ou null s'il n'en a pas encore.
     *
     * Utilisé dans CreatorPayoutController pour pré-remplir le formulaire.
     */
    public function findByUser(User $user): ?CreatorPayoutProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Profils en attente de vérification admin (priorité = les plus anciens d'abord).
     *
     * Affiché en tête de liste dans AdminCreatorPayoutController#index()
     * pour que l'admin traite les dossiers dans l'ordre FIFO.
     *
     * @return CreatorPayoutProfile[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', CreatorVerificationStatus::Pending)
            ->orderBy('p.submittedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Profils déjà traités (vérifiés ou refusés), récents d'abord.
     *
     * Limite à 100 pour éviter de charger toute la table sur une longue durée d'utilisation.
     *
     * @return CreatorPayoutProfile[]
     */
    public function findReviewed(int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status != :pending')
            ->setParameter('pending', CreatorVerificationStatus::Pending)
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de profils en attente de vérification (badge sidebar admin).
     *
     * Utilisation : AdminBadgeExtension (si on y ajoute un badge pour les versements).
     */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.status = :status')
            ->setParameter('status', CreatorVerificationStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

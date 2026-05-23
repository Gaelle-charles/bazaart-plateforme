<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationProfile>
 */
class OrganizationProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationProfile::class);
    }

    /**
     * Trouve le profil organisation d'un utilisateur donné.
     */
    public function findByUser(User $user): ?OrganizationProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Retourne toutes les organisations vérifiées (pour l'annuaire public).
     */
    public function findVerified(): array
    {
        return $this->findBy(['isVerified' => true], ['name' => 'ASC']);
    }

    /**
     * Retourne les organisations qui ont candidaté au statut Structure
     * mais dont la candidature n'a pas encore été traitée par un admin.
     *
     * Critère : structureApplicationAt IS NOT NULL AND isStructurePartner = false.
     *
     * Triées par date de candidature croissante (les plus anciennes d'abord)
     * pour que l'admin traite les dossiers par ordre d'arrivée — fair-play.
     *
     * @return OrganizationProfile[]
     */
    public function findPendingStructureApplications(): array
    {
        return $this->createQueryBuilder('op')
            // Charge l'utilisateur associé en même temps (évite le N+1 en Twig)
            ->leftJoin('op.user', 'u')->addSelect('u')
            // Condition : a candidaté mais pas encore validée
            ->where('op.structureApplicationAt IS NOT NULL')
            ->andWhere('op.isStructurePartner = false')
            // Les dossiers les plus anciens en premier (FIFO)
            ->orderBy('op.structureApplicationAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les structures partenaires actives (isStructurePartner = true).
     *
     * Triées alphabétiquement pour l'annuaire et les listes admin.
     *
     * @return OrganizationProfile[]
     */
    public function findActiveStructures(): array
    {
        return $this->createQueryBuilder('op')
            ->leftJoin('op.user', 'u')->addSelect('u')
            ->where('op.isStructurePartner = true')
            ->orderBy('op.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

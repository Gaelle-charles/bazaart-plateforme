<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtistProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les requêtes spécifiques à ArtistProfile.
 * Les méthodes de base (find, findBy, etc.) sont héritées de ServiceEntityRepository.
 *
 * @extends ServiceEntityRepository<ArtistProfile>
 */
class ArtistProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtistProfile::class);
    }

    /**
     * Trouve le profil artiste d'un utilisateur donné.
     * Retourne null si l'utilisateur n'a pas encore créé de profil artiste.
     */
    public function findByUser(User $user): ?ArtistProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Retourne tous les profils artistes pour l'annuaire public.
     * Triés alphabétiquement par nom d'affichage.
     * Le JOIN sur 'user' évite les requêtes N+1 lors de l'affichage de la liste.
     *
     * @return ArtistProfile[]
     */
    public function findAllForDirectory(): array
    {
        return $this->createQueryBuilder('ap')
            ->leftJoin('ap.user', 'u')->addSelect('u')
            ->orderBy('ap.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

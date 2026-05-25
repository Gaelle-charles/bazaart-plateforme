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
     *
     * Optimisation N+1 via FETCH JOIN :
     *   - JOIN 'ap.user'        → évite 1 requête par profil pour charger l'utilisateur
     *   - JOIN 'ap.disciplines' → évite 1 requête par profil pour charger les disciplines
     *
     * Sans ces JOINs, afficher 50 profils avec leurs disciplines = 51+ requêtes SQL.
     * Avec les JOINs = 1 seule requête (jointure).
     *
     * Note Doctrine : on ne peut faire qu'un seul JOIN de collection (ManyToMany)
     * par requête pour éviter les doublons de résultats. Ici on en a un seul (disciplines),
     * donc pas de risque de produit cartésien incontrôlé.
     *
     * @return ArtistProfile[]
     */
    public function findAllForDirectory(): array
    {
        return $this->createQueryBuilder('ap')
            ->leftJoin('ap.user', 'u')->addSelect('u')
            // Fetch join sur la collection disciplines — charge toutes les disciplines
            // en une seule requête plutôt qu'une requête par profil (problème N+1)
            ->leftJoin('ap.disciplines', 'd')->addSelect('d')
            ->orderBy('ap.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

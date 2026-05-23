<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResourceAlert;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité ResourceAlert (préférences d'alertes email).
 *
 * @extends ServiceEntityRepository<ResourceAlert>
 */
class ResourceAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResourceAlert::class);
    }

    /**
     * Trouve le profil d'alertes d'un utilisateur donné.
     * Retourne null si l'utilisateur n'a jamais configuré ses alertes.
     *
     * Utilisé dans ResourceController::alerts() pour pré-remplir le formulaire.
     * On charge les relations filterDisciplines et filterResourceTypes en JOIN
     * pour éviter le problème N+1 lors du rendu du formulaire (checkboxes pré-cochées).
     */
    public function findByUser(User $user): ?ResourceAlert
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.filterDisciplines', 'd')->addSelect('d')
            ->leftJoin('a.filterResourceTypes', 'rt')->addSelect('rt')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne tous les profils d'alertes actifs (notifyOnNewResource = true).
     *
     * Utilisé par le job d'envoi d'alertes (n8n ou Messenger) pour savoir
     * quels utilisateurs ont activé les notifications.
     * On charge aussi l'utilisateur (pour l'email) et les filtres (disciplines, types)
     * en une seule requête pour éviter le N+1 lors du traitement batch.
     *
     * @return ResourceAlert[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->leftJoin('a.filterDisciplines', 'd')->addSelect('d')
            ->leftJoin('a.filterResourceTypes', 'rt')->addSelect('rt')
            ->where('a.notifyOnNewResource = true')
            // Pas d'orderBy ici : un CASE WHEN SQL dans orderBy() n'est pas du DQL valide
            // et son comportement varie selon les versions de Doctrine.
            // Le tri par fréquence (immediate → daily → weekly) est effectué
            // côté PHP dans SendResourceAlertsCommand via usort(), ce qui est
            // plus sûr et plus lisible.
            ->getQuery()
            ->getResult();
    }
}

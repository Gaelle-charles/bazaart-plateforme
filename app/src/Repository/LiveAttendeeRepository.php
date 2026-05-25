<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Live;
use App\Entity\LiveAttendee;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les inscriptions aux lives (LiveAttendee).
 *
 * Centralise les requêtes liées aux inscriptions :
 *   - Vérification de doublon (un utilisateur ne peut s'inscrire qu'une fois)
 *   - Récupération des inscrits à notifier par email (rappels 24h)
 *
 * @extends ServiceEntityRepository<LiveAttendee>
 */
class LiveAttendeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LiveAttendee::class);
    }

    /**
     * Vérifie si un utilisateur est déjà inscrit à un live.
     *
     * Utilisé par LiveService::registerAttendee pour éviter les doublons
     * avant même de tenter l'insertion (qui échouerait sur la contrainte unique).
     *
     * On utilise findOneBy ici plutôt que count() car on récupère souvent
     * l'entité existante juste après (pour affichage ou unregister).
     */
    public function findByLiveAndUser(Live $live, User $user): ?LiveAttendee
    {
        return $this->findOneBy([
            'live' => $live,
            'user' => $user,
        ]);
    }

    /**
     * Retourne les inscrits d'un live qui n'ont pas encore reçu leur rappel email.
     *
     * Cette méthode est le cœur de la commande app:live:send-reminders.
     * Elle filtre sur reminderSent = false pour ne pas re-notifier les utilisateurs
     * déjà contactés lors d'une exécution précédente de la commande.
     *
     * JOIN FETCH sur user pour éviter le N+1 lors de la boucle d'envoi email
     * (chaque envoi accède à attendee->getUser()->getEmail()).
     *
     * @return LiveAttendee[]
     */
    public function findPendingRemindersForLive(Live $live): array
    {
        return $this->createQueryBuilder('la')
            // JOIN FETCH : charge l'utilisateur en même temps (évite N+1)
            ->leftJoin('la.user', 'u')
            ->addSelect('u')
            // Filtre : uniquement les inscrits de CE live
            ->andWhere('la.live = :live')
            ->setParameter('live', $live)
            // Filtre : uniquement ceux qui n'ont pas encore reçu le rappel
            ->andWhere('la.reminderSent = false')
            ->getQuery()
            ->getResult();
    }
}

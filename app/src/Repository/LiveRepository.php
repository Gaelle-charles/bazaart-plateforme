<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Live;
use App\Enum\LiveStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les lives planifiés.
 *
 * Centralise toutes les requêtes Doctrine liées à l'entité Live.
 * Le service LiveService utilise ce repository pour accéder aux données
 * sans avoir à écrire de DQL dans les controllers.
 *
 * Convention projet :
 *   - Les requêtes retournent des entités hydratées (pas de tableaux nus)
 *   - Les JOIN FETCH évitent les requêtes N+1 (chargement des relations en une passe)
 *   - Les paramètres de type DateTime utilisent DateTimeInterface pour la flexibilité
 *
 * @extends ServiceEntityRepository<Live>
 */
class LiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Live::class);
    }

    /**
     * Retourne les prochains lives planifiés (statut SCHEDULED), triés par date ASC.
     *
     * On charge également l'hôte (hostedBy) en JOIN FETCH pour éviter une requête
     * supplémentaire lors de l'affichage du nom de l'hôte dans le calendrier.
     *
     * @param int $limit Nombre maximum de lives à retourner
     * @return Live[]
     */
    public function findUpcoming(int $limit = 10): array
    {
        return $this->createQueryBuilder('l')
            // JOIN FETCH : charge l'hôte en même temps que le live (évite N+1)
            ->leftJoin('l.hostedBy', 'u')
            ->addSelect('u')
            // Filtre : uniquement les lives planifiés
            ->andWhere('l.status = :status')
            ->setParameter('status', LiveStatus::SCHEDULED)
            // Filtre : uniquement les lives dont la date n'est pas dépassée
            ->andWhere('l.scheduledAt > :now')
            ->setParameter('now', new \DateTime())
            // Tri croissant : le prochain live en premier
            ->orderBy('l.scheduledAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les lives terminés avec un replay disponible, triés par date DESC.
     *
     * On ne retourne que les lives qui ont un replayUrl — ceux sans replay
     * ne sont pas intéressants à afficher dans la section "Replays".
     *
     * @param int $limit Nombre maximum de lives à retourner
     * @return Live[]
     */
    public function findPastWithReplay(int $limit = 10): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.hostedBy', 'u')
            ->addSelect('u')
            // Filtre : lives terminés uniquement
            ->andWhere('l.status = :status')
            ->setParameter('status', LiveStatus::ENDED)
            // Filtre : uniquement ceux qui ont un lien replay
            ->andWhere('l.replayUrl IS NOT NULL')
            // Tri décroissant : le plus récent en premier
            ->orderBy('l.scheduledAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne tous les lives pour le dashboard admin, triés par date DESC.
     *
     * Pas de filtre sur le statut — l'admin voit tout.
     * On charge l'hôte en JOIN FETCH pour éviter le N+1.
     *
     * @return Live[]
     */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.hostedBy', 'u')
            ->addSelect('u')
            // Les admins voient tous les statuts (y compris annulés et terminés)
            ->orderBy('l.scheduledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les lives SCHEDULED qui démarrent dans les prochaines 24 heures.
     *
     * Cette méthode est utilisée par la commande app:live:send-reminders pour
     * identifier les lives qui nécessitent un envoi de rappel email.
     *
     * Fenêtre temporelle : entre maintenant et maintenant + 24h.
     * Cela évite d'envoyer des rappels pour des lives déjà commencés (passé)
     * ou trop lointains (plus de 24h).
     *
     * @return Live[]
     */
    public function findScheduledInNext24Hours(): array
    {
        $now   = new \DateTime();
        $in24h = (new \DateTime())->modify('+24 hours');

        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :status')
            ->setParameter('status', LiveStatus::SCHEDULED)
            // Filtre : commence APRÈS maintenant (pas encore démarré)
            ->andWhere('l.scheduledAt >= :now')
            ->setParameter('now', $now)
            // Filtre : commence AVANT now+24h (dans la fenêtre de rappel)
            ->andWhere('l.scheduledAt <= :in24h')
            ->setParameter('in24h', $in24h)
            ->orderBy('l.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

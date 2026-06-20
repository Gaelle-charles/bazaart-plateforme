<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MatchConsultation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les consultations de matchs (MatchConsultation).
 *
 * Utilisé par SubscriptionChecker pour compter les consultations hebdomadaires
 * d'un utilisateur non abonné (paywall freemium ADR-0022, Lot D).
 *
 * @extends ServiceEntityRepository<MatchConsultation>
 */
class MatchConsultationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatchConsultation::class);
    }

    /**
     * Compte le nombre de consultations de l'utilisateur pour la semaine ISO en cours.
     *
     * FENÊTRE HEBDOMADAIRE :
     *   Semaine ISO = lundi 00:00:00 UTC → dimanche 23:59:59 UTC.
     *   On calcule le début de la semaine en cours à partir de 'now' :
     *     - date('N') retourne 1=lundi ... 7=dimanche
     *     - On soustrait (jourDeLaSemaine - 1) jours pour remonter au lundi
     *   Exemple : si on est mercredi 18/06/2026, on remonte au lundi 16/06/2026 00:00:00.
     *
     * Cette méthode fait une requête SELECT COUNT → aucune entité n'est chargée en mémoire.
     * Elle est rapide grâce à l'index composite (user_id, viewed_at).
     *
     * @param User $user L'utilisateur dont on compte les consultations
     * @return int       Nombre de consultations depuis le début de la semaine ISO
     */
    public function countForUserThisWeek(User $user): int
    {
        // Calcule le début de la semaine ISO (lundi) en UTC.
        // DateTimeImmutable::modify() retourne un NOUVEL objet (immuabilité).
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // date('N') retourne le jour de la semaine ISO : 1=lundi, 7=dimanche.
        // On soustrait (N - 1) jours pour revenir au lundi, puis on remet l'heure à 00:00:00.
        $dayOfWeek = (int) $now->format('N'); // 1=lundi, 7=dimanche
        $weekStart = $now->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);

        // Requête COUNT : on compte les consultations dont viewedAt >= lundi 00:00:00 UTC.
        // Le résultat est un scalaire entier, pas d'entités chargées en mémoire.
        $result = $this->createQueryBuilder('mc')
            ->select('COUNT(mc.id)')
            ->where('mc.user = :user')
            ->andWhere('mc.viewedAt >= :weekStart')
            ->setParameter('user', $user)
            ->setParameter('weekStart', $weekStart)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Enregistre une consultation pour l'utilisateur et la ressource donnée,
     * puis flush immédiatement.
     *
     * Ce helper est utilisé par le SwipeController::recordView() pour incrémenter
     * le compteur en un seul appel. On ne dé-duplique pas : chaque appel crée un
     * enregistrement (voir commentaire dans MatchConsultation sur la définition d'une consultation).
     *
     * @param User          $user     L'utilisateur qui a consulté
     * @param \App\Entity\Resource|null $resource La ressource consultée (null si introuvable)
     */
    public function record(User $user, ?\App\Entity\Resource $resource): void
    {
        $consultation = new MatchConsultation($user, $resource);

        // On utilise getEntityManager() disponible sur ServiceEntityRepository.
        $this->getEntityManager()->persist($consultation);
        $this->getEntityManager()->flush();
    }
}

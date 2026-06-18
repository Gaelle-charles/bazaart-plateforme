<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Course;
use App\Entity\CourseEnrollment;
use App\Entity\User;
use App\Enum\CourseType;
use App\Enum\EnrollmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les inscriptions aux formations (CourseEnrollment).
 *
 * @extends ServiceEntityRepository<CourseEnrollment>
 */
class CourseEnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseEnrollment::class);
    }

    /**
     * Vérifie si un utilisateur est déjà inscrit à une formation.
     * Appelé par EventRegistrationService::registerForEvent() avant de créer une inscription.
     *
     * ATTENTION : retourne l'inscription qu'elle soit ACTIVE ou CANCELLED.
     * Pourquoi ? La contrainte UNIQUE SQL porte sur (user_id, course_id) sans condition
     * sur le statut. Si on veut permettre la réinscription après annulation, il faudra
     * soit changer cette méthode (filtrer sur ACTIVE seulement), soit changer la
     * contrainte UNIQUE en base pour inclure le statut.
     *
     * En V1, on ne réinscrit PAS après annulation → le comportement existant est correct.
     *
     * Retourne l'inscription existante (ACTIVE ou CANCELLED) ou null si absent.
     */
    public function findByUserAndCourse(User $user, Course $course): ?CourseEnrollment
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.course = :course')
            ->setParameter('user', $user)
            ->setParameter('course', $course)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Vérifie si un utilisateur a une inscription ACTIVE à une formation.
     *
     * Différence avec findByUserAndCourse() : on filtre sur status=ACTIVE.
     * Utilisé par la logique d'annulation pour vérifier qu'il y a bien quelque chose
     * à annuler avant de tenter l'opération.
     */
    public function findActiveByUserAndCourse(User $user, Course $course): ?CourseEnrollment
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.course = :course')
            ->andWhere('e.status = :status')
            ->setParameter('user', $user)
            ->setParameter('course', $course)
            ->setParameter('status', EnrollmentStatus::ACTIVE->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne toutes les inscriptions d'un utilisateur, avec la formation préchargée.
     * Utilisé pour le tableau de bord apprenant (liste "Mes formations").
     *
     * FETCH JOIN : évite les requêtes N+1 en chargeant Course en même temps.
     *
     * @return CourseEnrollment[]
     */
    public function findByUserWithCourse(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.course', 'c')
            ->addSelect('c')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.enrolledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre d'inscrits actifs pour une formation donnée.
     *
     * Cette méthode remplace le comptage en mémoire via $course->getEnrollments()->count()
     * qui déclenchait le chargement complet de la collection (problème N+1 signalé
     * en Phase 1 : si 200 inscrits, Doctrine chargeait 200 entités CourseEnrollment
     * juste pour compter).
     *
     * Une requête SQL COUNT est O(1) en coût réseau et très efficace en base.
     * C'est la méthode recommandée pour les vérifications de capacité critiques
     * (anti-survente) où on doit être sûr d'avoir un chiffre exact en temps réel.
     *
     * Pourquoi "actifs" uniquement ?
     *   En Phase 2, une inscription annulée (statut 'cancelled') ne doit pas
     *   compter comme une place prise. Pour l'instant l'entité n'a pas de
     *   champ status — toute inscription compte donc comme "active".
     *   Quand le statut sera ajouté, filtrer sur status='active' ici.
     *
     * @param Course $course La formation dont on compte les inscrits
     * @return int           Nombre d'inscrits actifs
     */
    public function countActiveByCourse(Course $course): int
    {
        // getSingleScalarResult() retourne une valeur unique (le COUNT)
        // et lève une exception si la requête retourne autre chose — on cast en int.
        //
        // Phase 3 : on filtre sur status = 'active' pour que les inscriptions
        // annulées libèrent réellement leur place dans le décompte de capacité.
        // Avant Phase 3, toute inscription comptait (pas de champ status).
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.course = :course')
            ->andWhere('e.status = :status')
            ->setParameter('course', $course)
            ->setParameter('status', EnrollmentStatus::ACTIVE->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne les inscriptions d'un utilisateur aux événements à venir.
     *
     * Utilisé par le dashboard membre pour afficher la section
     * « Mes formations & événements à venir ».
     *
     * Critères :
     *   - L'inscription appartient à cet utilisateur
     *   - La formation est de type EVENEMENT
     *   - La date de début (eventStartAt) est dans le futur
     *   - La formation est publiée (paranoia : une formation dépubliée ne devrait
     *     pas apparaître, même si l'utilisateur est inscrit)
     *
     * Tri : date de début la plus proche d'abord (ASC), pour une liste chronologique.
     *
     * FETCH JOIN sur c (Course) : on a besoin des champs cours (titre, slug, mode,
     * dates, lieu/lien) — mieux vaut les charger en une seule requête.
     *
     * @return CourseEnrollment[]
     */
    public function findUpcomingEventsByUser(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.course', 'c')
            ->addSelect('c')
            ->where('e.user = :user')
            // On filtre uniquement les formations de type EVENEMENT
            ->andWhere('c.type = :eventType')
            // On ne veut que les événements dont la date de début est dans le futur
            // :now est l'instant courant (injecté en paramètre pour testabilité)
            ->andWhere('c.eventStartAt > :now')
            // Paranoia : cacher les événements dépubliés
            ->andWhere('c.isPublished = true')
            // Phase 3 : on n'affiche que les inscriptions ACTIVES dans le dashboard.
            // Une inscription annulée ne doit plus apparaître dans "Mes événements à venir".
            ->andWhere('e.status = :enrollStatus')
            ->setParameter('user', $user)
            // On passe la valeur backing via l'enum pour éviter un littéral fragile :
            // si CourseType::EVENEMENT change de backing string, ce code s'adapte
            // automatiquement. Sans cette précaution, la requête retournerait
            // silencieusement zéro résultat sans lever d'erreur.
            ->setParameter('eventType', CourseType::EVENEMENT->value)
            ->setParameter('enrollStatus', EnrollmentStatus::ACTIVE->value)
            ->setParameter('now', new \DateTime())
            ->orderBy('c.eventStartAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les inscriptions ACTIVES et PAYANTES d'un cours (événement).
     *
     * Utilisé par EventCancellationService::cancelEventByAdmin() pour rembourser
     * en masse tous les inscrits payants actifs quand l'admin annule un événement entier.
     *
     * On fait un FETCH JOIN sur l'entité User pour avoir les emails sans lazy-load
     * supplémentaire dans la boucle de remboursement.
     *
     * @return CourseEnrollment[]
     */
    public function findActiveEnrollmentsByCourse(Course $course): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.user', 'u')
            ->addSelect('u')
            ->where('e.course = :course')
            ->andWhere('e.status = :status')
            ->setParameter('course', $course)
            ->setParameter('status', EnrollmentStatus::ACTIVE->value)
            ->orderBy('e.enrolledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

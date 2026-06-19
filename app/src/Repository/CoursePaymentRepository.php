<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Course;
use App\Entity\CoursePayment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les paiements de formation (CoursePayment).
 *
 * Toute la logique de requête liée aux paiements de formations est centralisée ici.
 * Utilisé par StripeWebhookController et CoursePaymentController.
 *
 * @extends ServiceEntityRepository<CoursePayment>
 */
class CoursePaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoursePayment::class);
    }

    /**
     * Vérifie si un utilisateur a acheté une formation (paiement confirmé).
     *
     * Retourne le CoursePayment confirmé si l'utilisateur a accès à la formation,
     * null sinon. La vérification d'accès dans le CourseController s'appuie sur
     * cette méthode pour décider d'afficher ou non le contenu des leçons.
     *
     * On filtre sur status='completed' car un paiement 'pending' ou 'refunded'
     * ne donne pas accès à la formation.
     *
     * @param User   $user   L'utilisateur dont on vérifie l'achat
     * @param Course $course La formation concernée
     */
    public function findCompletedByUserAndCourse(User $user, Course $course): ?CoursePayment
    {
        return $this->createQueryBuilder('cp')
            ->where('cp.user = :user')
            ->andWhere('cp.course = :course')
            ->andWhere('cp.status = :status')
            ->setParameter('user', $user)
            ->setParameter('course', $course)
            ->setParameter('status', 'completed')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve un paiement par son PaymentIntent Stripe (ex : "pi_xxx").
     *
     * Utilisé par le webhook charge.refunded pour retrouver le paiement
     * local à marquer comme 'refunded'.
     * Aussi utilisé par EventCancellationService pour lancer un remboursement Stripe.
     *
     * Retourne null si aucun paiement ne correspond à cet ID Stripe.
     *
     * @param string $stripePaymentIntentId L'ID du PaymentIntent Stripe ("pi_xxx")
     */
    public function findByStripePaymentIntentId(string $stripePaymentIntentId): ?CoursePayment
    {
        return $this->findOneBy(['stripePaymentIntentId' => $stripePaymentIntentId]);
    }

    /**
     * Retourne le paiement COMPLÉTÉ d'un utilisateur pour une formation donnée.
     *
     * Utilisé par EventCancellationService pour retrouver le paiement à rembourser
     * lors d'une annulation. On ne recherche que les paiements 'completed' car :
     *   - 'pending' → paiement pas encore confirmé, pas de remboursement possible
     *   - 'refunded' → déjà remboursé (idempotence : ne pas rembourser deux fois)
     *
     * Alias sémantique de findCompletedByUserAndCourse() pour la clarté d'usage
     * dans le contexte d'annulation.
     */
    public function findCompletedPaymentForRefund(User $user, Course $course): ?CoursePayment
    {
        return $this->findCompletedByUserAndCourse($user, $course);
    }

    /**
     * Retourne tous les paiements COMPLÉTÉS pour un cours (événement entier).
     *
     * Utilisé par EventCancellationService::cancelEventByAdmin() pour rembourser
     * en masse tous les inscrits payants lors de l'annulation d'un événement complet.
     *
     * On filtre sur 'completed' uniquement — les paiements déjà 'refunded' sont
     * exclus pour l'idempotence (si la route d'annulation admin est appelée deux fois).
     *
     * @return CoursePayment[]
     */
    public function findCompletedPaymentsByCourse(Course $course): array
    {
        return $this->createQueryBuilder('cp')
            ->join('cp.user', 'u')
            ->addSelect('u')
            ->where('cp.course = :course')
            ->andWhere('cp.status = :status')
            ->setParameter('course', $course)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getResult();
    }
}

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
     * Utilisé par le webhook charge.refunded (V2) pour retrouver le paiement
     * local à marquer comme 'refunded'.
     *
     * Retourne null si aucun paiement ne correspond à cet ID Stripe.
     *
     * @param string $stripePaymentIntentId L'ID du PaymentIntent Stripe ("pi_xxx")
     */
    public function findByStripePaymentIntentId(string $stripePaymentIntentId): ?CoursePayment
    {
        return $this->findOneBy(['stripePaymentIntentId' => $stripePaymentIntentId]);
    }
}

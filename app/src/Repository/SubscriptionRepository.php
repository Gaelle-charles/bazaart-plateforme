<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les abonnements Stripe (Subscription).
 *
 * Toute la logique de requête liée aux abonnements est centralisée ici.
 * Utilisé principalement par StripeWebhookController et SubscriptionController.
 *
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Trouve l'abonnement actif d'un utilisateur.
     *
     * "Actif" au sens Stripe = status IN ('active', 'trialing').
     * On cherche aussi que currentPeriodEnd est dans le futur (filet de sécurité
     * en cas de webhook Stripe retardé).
     *
     * Retourne null si l'utilisateur n'a pas d'abonnement actif.
     * Retourne le premier trouvé (en pratique il ne devrait y en avoir qu'un seul
     * actif à la fois — contrainte portée par la logique métier, pas par la BDD).
     *
     * @param User $user L'utilisateur dont on cherche l'abonnement actif
     */
    public function findActiveByUser(User $user): ?Subscription
    {
        return $this->createQueryBuilder('s')
            // Filtre sur l'utilisateur
            ->where('s.user = :user')
            // Statuts considérés comme "actifs" (identiques à Subscription::isActive())
            ->andWhere('s.status IN (:activeStatuses)')
            // Filet de sécurité : période en cours pas encore expirée
            ->andWhere('s.currentPeriodEnd > :now')
            ->setParameter('user', $user)
            ->setParameter('activeStatuses', ['active', 'trialing'])
            ->setParameter('now', new \DateTime())
            // Si plusieurs abonnements actifs existent (ne devrait pas arriver),
            // on retourne le plus récent (createdAt DESC).
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve un abonnement par son identifiant Stripe (ex : "sub_xxx").
     *
     * Utilisé par les webhooks Stripe pour retrouver l'enregistrement local
     * à mettre à jour (customer.subscription.updated, customer.subscription.deleted).
     *
     * Retourne null si aucun abonnement ne correspond à cet ID Stripe
     * (cas d'un abonnement créé hors de la plateforme ou d'un ID invalide).
     *
     * @param string $stripeSubscriptionId L'ID de l'abonnement Stripe ("sub_xxx")
     */
    public function findByStripeId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }
}

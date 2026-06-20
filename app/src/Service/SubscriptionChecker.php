<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\MatchConsultationRepository;
use App\Repository\SubscriptionRepository;

/**
 * SubscriptionChecker — Service central du paywall freemium (ADR-0022, Lot D).
 *
 * Ce service répond à deux questions :
 *   1. L'utilisateur est-il abonné ? (isSubscribed)
 *   2. Combien de consultations de matchs lui reste-t-il cette semaine ? (getRemainingMatchViews)
 *
 * RÈGLES MÉTIER (ADR-0022) :
 *   - ROLE_ADMIN : accès illimité à tout, isSubscribed() = true par convention
 *     (les admins ne sont JAMAIS bloqués, même sans abonnement Stripe)
 *   - Abonné (Subscription::isActive()) : accès illimité à tout
 *   - Gratuit (ni admin ni abonné) : 3 consultations de matchs par semaine ISO
 *
 * CE SERVICE NE CONTIENT PAS DE LOGIQUE HTTP :
 *   Il ne redirige pas, ne throw pas d'exceptions de sécurité.
 *   C'est aux controllers et aux voters de décider quoi faire du résultat.
 *
 * UTILISATION dans un controller :
 *   if (!$this->subscriptionChecker->isSubscribed($user)) {
 *       $this->addFlash('info', 'Cette fonctionnalité est réservée aux abonnés.');
 *       return $this->redirectToRoute('app_pricing');
 *   }
 *
 * UTILISATION dans un voter :
 *   return $this->subscriptionChecker->isSubscribed($user);
 */
final class SubscriptionChecker
{
    /**
     * Nombre maximum de consultations de matchs par semaine pour les utilisateurs gratuits.
     * Défini dans l'ADR-0022 : "3 consultations / semaine puis → /tarifs".
     */
    public const int FREE_WEEKLY_MATCH_LIMIT = 3;

    /**
     * Injection des dépendances via le constructeur (autowiring).
     *
     * SubscriptionRepository : pour vérifier si l'utilisateur a un abonnement actif.
     * MatchConsultationRepository : pour compter les consultations hebdomadaires.
     * Security : pour vérifier les rôles en respectant la hiérarchie de rôles.
     */
    public function __construct(
        private readonly SubscriptionRepository          $subscriptionRepository,
        private readonly MatchConsultationRepository     $consultationRepository,
        // On injecte le composant Security Symfony pour respecter la role_hierarchy.
        // isGranted('ROLE_ADMIN') retourne true si l'utilisateur hérite de ROLE_ADMIN,
        // contrairement à in_array('ROLE_ADMIN', $user->getRoles()) qui ne respecte pas
        // la hiérarchie de rôles définie dans security.yaml.
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
    ) {}

    /**
     * Retourne true si l'utilisateur a accès aux fonctionnalités premium.
     *
     * LOGIQUE :
     *   1. Si l'utilisateur est ROLE_ADMIN → true (accès illimité, jamais bloqué)
     *   2. Si l'utilisateur a un abonnement Stripe actif → true
     *   3. Sinon → false (utilisateur gratuit)
     *
     * Cette méthode est le point d'entrée unique pour la vérification d'abonnement.
     * Tous les voters et controllers du paywall passent par ici.
     *
     * @param User $user L'utilisateur connecté (non null car on appelle depuis des routes protégées)
     * @return bool true si accès premium, false si utilisateur gratuit
     */
    public function isSubscribed(User $user): bool
    {
        // Règle admin : jamais bloqué, toujours accès premium.
        // On utilise $this->security->isGranted() qui respecte la role_hierarchy de security.yaml.
        // ROLE_ADMIN hérite de ROLE_ARTIST, ROLE_STRUCTURE, etc. selon la config.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Vérifie l'abonnement Stripe actif via SubscriptionRepository.
        // findActiveByUser() retourne null si pas d'abonnement actif.
        $subscription = $this->subscriptionRepository->findActiveByUser($user);

        // On vérifie aussi isActive() par sécurité (double vérification statut + date).
        // findActiveByUser() fait déjà cette vérification en DQL, mais une vérification
        // supplémentaire côté PHP garantit la cohérence si le repository change.
        return $subscription !== null && $subscription->isActive();
    }

    /**
     * Retourne le nombre de consultations de matchs restantes pour la semaine en cours.
     *
     * Pour les abonnés et admins : retourne PHP_INT_MAX (illimité par convention).
     * Pour les utilisateurs gratuits : retourne max(0, limite - consultations_cette_semaine).
     *
     * Exemple pour un utilisateur gratuit qui a déjà vu 2 matchs cette semaine :
     *   getRemainingMatchViews($user) → 1 (3 - 2 = 1 consultation restante)
     *
     * @param User $user L'utilisateur connecté
     * @return int Nombre de consultations restantes (PHP_INT_MAX = illimité)
     */
    public function getRemainingMatchViews(User $user): int
    {
        // Abonnés et admins : aucune limite
        if ($this->isSubscribed($user)) {
            return PHP_INT_MAX;
        }

        // Compte les consultations de la semaine ISO en cours
        $usedThisWeek = $this->consultationRepository->countForUserThisWeek($user);

        // max(0, ...) garantit qu'on ne retourne jamais un nombre négatif
        // (au cas où un bug aurait créé plus d'entrées que la limite)
        return max(0, self::FREE_WEEKLY_MATCH_LIMIT - $usedThisWeek);
    }

    /**
     * Retourne true si l'utilisateur gratuit peut encore voir des matchs cette semaine.
     *
     * Raccourci booléen pour les conditions dans les controllers et le Twig.
     *   canViewMoreMatches($user) → true : on affiche les cartes
     *   canViewMoreMatches($user) → false : on affiche l'écran tarifs
     *
     * Pour les abonnés et admins : toujours true.
     *
     * @param User $user L'utilisateur connecté
     * @return bool true si l'utilisateur peut voir un match de plus
     */
    public function canViewMoreMatches(User $user): bool
    {
        return $this->getRemainingMatchViews($user) > 0;
    }
}

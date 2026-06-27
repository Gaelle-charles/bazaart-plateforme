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
 *   1. L'utilisateur a-t-il un accès premium ? (isSubscribed)
 *   2. Combien de consultations de matchs lui reste-t-il aujourd'hui ? (getRemainingMatchViews)
 *
 * RÈGLES MÉTIER (ADR-0022 mis à jour + ADR-0028 — juin 2026) :
 *   - ROLE_ADMIN    : accès illimité à tout, isSubscribed() = true par convention
 *                     (les admins ne sont JAMAIS bloqués, même sans abonnement Stripe)
 *   - Essai gratuit : tout compte inscrit bénéficie d'1 mois de premium automatique
 *                     (User::$trialEndsAt initialisé à createdAt + 1 mois — ADR-0028)
 *   - Abonné Stripe : abonnement actif (Subscription::isActive()) → accès illimité
 *   - Gratuit       : ni admin, ni essai en cours, ni abonné → 3 consultations/jour
 *
 * isSubscribed() signifie donc désormais « a un accès premium » :
 *   admin  OU  essai en cours  OU  abonnement Stripe actif.
 *
 * PASSAGE HEBDO → QUOTIDIEN (juin 2026) :
 *   La limite était initialement par semaine ISO (lundi → dimanche).
 *   Elle est désormais quotidienne : minuit UTC → 23:59:59 UTC du même jour.
 *   Cela permet une rotation plus fréquente et incite davantage à consulter chaque jour.
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
     * Nombre maximum de consultations de matchs par jour pour les utilisateurs gratuits.
     *
     * Défini dans l'ADR-0022 — mis à jour juin 2026 : "3 consultations / jour puis → /tarifs".
     * La fenêtre quotidienne court de minuit UTC à 23:59:59 UTC (réinitialisée chaque jour à minuit).
     */
    public const int FREE_DAILY_MATCH_LIMIT = 3;

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
     * LOGIQUE (ADR-0022 + ADR-0028) :
     *   1. Si l'utilisateur est ROLE_ADMIN → true (accès illimité, jamais bloqué)
     *   2. Si l'utilisateur est dans son essai gratuit d'1 mois → true (ADR-0028)
     *   3. Si l'utilisateur a un abonnement Stripe actif → true
     *   4. Sinon → false (utilisateur gratuit, 3 matchings/jour)
     *
     * Cette méthode est le point d'entrée unique pour la vérification d'abonnement.
     * Tous les voters et controllers du paywall passent par ici.
     * getRemainingMatchViews() et canViewMoreMatches() héritent automatiquement
     * de cette logique (ils appellent isSubscribed() en premier).
     *
     * @param User $user L'utilisateur connecté (non null car on appelle depuis des routes protégées)
     * @return bool true si accès premium (admin, essai ou abonnement), false si utilisateur gratuit
     */
    public function isSubscribed(User $user): bool
    {
        // ── 1. Règle admin : jamais bloqué, toujours accès premium ──────────
        // On utilise $this->security->isGranted() qui respecte la role_hierarchy de security.yaml.
        // ROLE_ADMIN hérite de ROLE_ARTIST, ROLE_STRUCTURE, etc. selon la config.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // ── 2. Essai gratuit d'1 mois (ADR-0028) ────────────────────────────
        // Tout compte inscrit depuis la V1 part avec trialEndsAt = createdAt + 1 mois.
        // User::isInTrial() compare trialEndsAt avec "maintenant" (DateTimeImmutable) :
        //   → retourne true si le mois n'est pas encore écoulé.
        // Si l'essai est en cours, on court-circuite ici : pas besoin d'interroger Stripe.
        if ($user->isInTrial()) {
            return true;
        }

        // ── 3. Abonnement Stripe actif ───────────────────────────────────────
        // Vérifie l'abonnement Stripe via SubscriptionRepository.
        // findActiveByUser() retourne null si pas d'abonnement actif.
        $subscription = $this->subscriptionRepository->findActiveByUser($user);

        // On vérifie aussi isActive() par sécurité (double vérification statut + date).
        // findActiveByUser() fait déjà cette vérification en DQL, mais une vérification
        // supplémentaire côté PHP garantit la cohérence si le repository change.
        return $subscription !== null && $subscription->isActive();
    }

    /**
     * Retourne le nombre de consultations de matchs restantes pour le jour en cours.
     *
     * Pour les abonnés et admins : retourne PHP_INT_MAX (illimité par convention).
     * Pour les utilisateurs gratuits : retourne max(0, limite - consultations_aujourd_hui).
     *
     * La fenêtre quotidienne est calculée en UTC : de minuit (00:00:00) à 23:59:59 du même jour.
     * Elle se réinitialise automatiquement à chaque minuit UTC.
     *
     * Exemple pour un utilisateur gratuit qui a déjà vu 2 matchs aujourd'hui :
     *   getRemainingMatchViews($user) → 1 (3 - 2 = 1 consultation restante aujourd'hui)
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

        // Compte les consultations du jour en cours (depuis minuit UTC)
        $usedToday = $this->consultationRepository->countForUserToday($user);

        // max(0, ...) garantit qu'on ne retourne jamais un nombre négatif
        // (au cas où un bug aurait créé plus d'entrées que la limite quotidienne)
        return max(0, self::FREE_DAILY_MATCH_LIMIT - $usedToday);
    }

    /**
     * Retourne true si l'utilisateur gratuit peut encore voir des matchs aujourd'hui.
     *
     * Raccourci booléen pour les conditions dans les controllers et le Twig.
     *   canViewMoreMatches($user) → true : on affiche les cartes
     *   canViewMoreMatches($user) → false : on affiche l'écran tarifs
     *
     * La limite se réinitialise automatiquement à minuit UTC chaque jour.
     * Pour les abonnés et admins : toujours true.
     *
     * @param User $user L'utilisateur connecté
     * @return bool true si l'utilisateur peut voir un match de plus aujourd'hui
     */
    public function canViewMoreMatches(User $user): bool
    {
        return $this->getRemainingMatchViews($user) > 0;
    }
}

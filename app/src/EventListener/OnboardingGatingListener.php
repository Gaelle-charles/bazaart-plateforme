<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * OnboardingGatingListener — Anciennement forçait les nouveaux utilisateurs vers l'onboarding.
 *
 * LOT A (ADR-0021/0022) — GATING DÉSACTIVÉ :
 *   L'onboarding n'est plus obligatoire pour naviguer le site.
 *   Un utilisateur connecté non-onboardé peut accéder librement au dashboard,
 *   à la ressourcerie, au forum, etc.
 *
 * COMPORTEMENT DÉSORMAIS :
 *   - Ce listener existe toujours mais NE REDIRIGE PLUS.
 *   - La vérification de l'email reste gérée par UserChecker (security.yaml),
 *     qui bloque la connexion si isVerified = false. C'est un mécanisme distinct
 *     du gating onboarding, et il reste actif.
 *
 * QUAND LE GATING REVIENDRA :
 *   Le gating de profil complet sera réintroduit à l'entrée du MODULE MATCHING (Lot C).
 *   Il vérifiera que l'artiste a : discipline + localisation + lookingFor + legalStatus.
 *   Ce contrôle se fera dans le MatchingController (Lot C), pas ici de façon globale.
 *
 * NOTE POUR LES FUTURS DÉVELOPPEMENTS :
 *   Si un gating partiel est nécessaire avant Lot C (ex: accès aux cours réservés
 *   aux artistes), utiliser un Voter Symfony plutôt que ce listener global.
 *   Un listener global est un marteau pour un écrou — préférer la granularité des Voters.
 *
 * Convention Symfony 7.x : #[AsEventListener] remplace l'enregistrement dans services.yaml.
 * Priorité 7 : s'exécute APRÈS le firewall Symfony (priorité 8) pour avoir accès
 * à l'utilisateur authentifié via TokenStorage.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
class OnboardingGatingListener
{
    /**
     * Méthode principale exécutée à chaque requête.
     *
     * LOT A : cette méthode ne fait rien. Elle retourne immédiatement.
     *
     * On conserve la classe et le listener enregistré pour deux raisons :
     *   1. Faciliter la réintroduction d'un gating partiel si besoin avant Lot C.
     *   2. Documenter l'intention architecturale (le gating appartient au Lot C).
     *
     * Le listener est léger (return immédiat) et n'impacte pas les performances.
     */
    public function __invoke(RequestEvent $event): void
    {
        // LOT A : gating global désactivé.
        //
        // La vérification d'email (isVerified) est gérée par UserChecker dans security.yaml
        // et reste active. Elle bloque la connexion si l'email n'est pas confirmé.
        //
        // La vérification de profil complet pour le matching sera implémentée
        // dans MatchingController (Lot C) avec une logique contextuelle, pas globale.
        return;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Matching\MatchResult;
use App\Entity\User;
use App\Security\Voter\MatchingVoter;
use App\Service\MatchingProfileChecker;
use App\Service\MatchingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MatchingController — Accès au moteur de matching pour les artistes connectés (ADR-0021 Lot B).
 *
 * Ce controller est intentionnellement "fin" : il ne contient aucune logique métier.
 * Toute la logique de scoring est déléguée à MatchingService.
 *
 * CHOIX TECHNIQUE : JSON ou Twig ?
 *   On retourne du JSON (JsonResponse) pour deux raisons :
 *     1. L'UI swipe (Lot C) sera très probablement construite en Stimulus/JS qui consomme
 *        du JSON. Anticiper ce format évite de réécrire le controller au Lot C.
 *     2. Le JSON est plus facile à tester et à explorer en dev (curl, Postman).
 *   Il n'y a PAS de page Twig de debug car le format JSON est déjà lisible dans le navigateur
 *   (avec l'extension JSON Formatter par exemple) et dans les logs Symfony.
 *
 * ROUTES :
 *   GET /api/matching/my-matches  → liste complète des matchs + compteur
 *   GET /api/matching/count       → uniquement le compteur (plus léger pour le hero home)
 *
 * SÉCURITÉ :
 *   - MatchingVoter::MATCHING_VIEW protège les deux routes.
 *   - denyAccessUnlessGranted() retourne une 403 si le voter refuse l'accès.
 *   - L'artiste doit être connecté ET avoir ROLE_ARTIST.
 *
 * CAS "PROFIL INCOMPLET" (Option B — refactoring du 20 juin 2026) :
 *   Si MatchingProfileChecker::isComplete() retourne false, on court-circuite AVANT
 *   d'appeler MatchingService : on retourne { matches: [], count: 0, profile_complete: false }.
 *   Cela garantit qu'un profil incomplet ne peut PAS récupérer de matchs via l'API,
 *   même si la validation côté front est contournée (ex: appel curl direct).
 *
 *   Les critères de complétude sont définis dans MatchingProfileChecker.
 *   C'est la SOURCE DE VÉRITÉ UNIQUE — on ne répète pas la logique ici.
 */
#[Route('/api/matching', name: 'api_matching_')]
final class MatchingController extends AbstractController
{
    public function __construct(
        private readonly MatchingService        $matchingService,
        // MatchingProfileChecker : source de vérité unique pour la complétude du profil
        // (remplace l'ancienne méthode privée isProfileComplete() de ce controller)
        private readonly MatchingProfileChecker $profileChecker,
    ) {}

    /**
     * Retourne la liste complète des matchs de l'artiste connecté.
     *
     * Format de réponse JSON :
     * {
     *   "count": 12,
     *   "matches": [
     *     {
     *       "resource_id": 42,
     *       "title": "Résidence de création 2026",
     *       "resource_type": "Résidence artistique",
     *       "score": 65,
     *       "breakdown": { "disciplines": 30, "looking_for": 20, "territory": 10, "experience": 5 },
     *       "deadline": "2026-09-30",
     *       "city": "Paris",
     *       "country": "France",
     *       "logo_url": "https://...",
     *       "external_url": "https://..."
     *     },
     *     ...
     *   ],
     *   "profile_complete": true
     * }
     *
     * Champ "profile_complete" :
     *   false → le profil artiste n'existe pas ou est vide → le front peut afficher
     *            une invitation à compléter le profil.
     *   true  → profil existant (même si le nombre de matchs est 0).
     */
    #[Route('/my-matches', name: 'my_matches', methods: ['GET'])]
    public function myMatches(): JsonResponse
    {
        // Vérifie l'autorisation via le MatchingVoter (ROLE_ARTIST requis).
        // denyAccessUnlessGranted() lance une AccessDeniedException (→ HTTP 403) si refusé.
        $this->denyAccessUnlessGranted(MatchingVoter::MATCHING_VIEW);

        // Récupère l'utilisateur connecté.
        // getUser() ne retourne jamais null ici car denyAccessUnlessGranted() garantit
        // que l'utilisateur est connecté. Le cast est nécessaire pour PHPStan (typage).
        $user = $this->getUser();

        // Garde de sécurité PHPStan : getUser() peut théoriquement retourner null
        // ou une instance non-User si l'app est mal configurée. On le vérifie explicitement.
        if (!$user instanceof User) {
            // Ce cas ne devrait jamais arriver si le Voter est correctement configuré,
            // mais on le gère proprement plutôt que de laisser une erreur 500 partir.
            return $this->json([
                'error'   => 'Utilisateur non authentifié.',
                'matches' => [],
                'count'   => 0,
            ], Response::HTTP_UNAUTHORIZED);
        }

        // ── Vérification de la complétude du profil ─────────────────────────────
        // On délègue ENTIÈREMENT à MatchingProfileChecker : c'est lui la source
        // de vérité (DRY). Si le profil est incomplet, on court-circuite ici
        // sans appeler MatchingService — cela garantit qu'un profil incomplet
        // ne peut jamais récupérer de matchs via cette API, même en appelant
        // l'endpoint directement (contournement front impossible).
        $profileComplete = $this->profileChecker->isComplete($user);

        if (!$profileComplete) {
            // Profil incomplet : on retourne une réponse vide immédiatement.
            // HTTP 200 (et non 403 ni 422) car ce n'est pas une erreur d'autorisation
            // ni de validation — c'est un état métier valide ("profil en cours").
            // Le front lit profile_complete = false et affiche l'invitation à compléter.
            return $this->json([
                'count'            => 0,
                'matches'          => [],
                // false signale explicitement au front que le profil doit être complété
                'profile_complete' => false,
            ]);
        }

        // Profil complet : on délègue le calcul de scoring à MatchingService.
        // getMatchesForUser() retourne TOUS les matchs triés par score décroissant.
        $matches = $this->matchingService->getMatchesForUser($user);

        // On filtre les matchs avec score > 0 : un score nul = aucun critère commun
        // → pas pertinent à montrer à l'utilisateur.
        $positiveMatches = array_values(
            array_filter($matches, fn(MatchResult $r) => $r->score > 0)
        );

        return $this->json([
            // Nombre de matchs pertinents (score > 0) — affiché dans le hero home
            'count'            => count($positiveMatches),
            // Liste des matchs classés du meilleur au moins bon (score décroissant)
            // On n'inclut QUE les matchs avec score > 0 dans la liste retournée.
            'matches'          => array_map(
                fn(MatchResult $r) => $r->toArray(),
                $positiveMatches
            ),
            // true = profil complet et matchs calculés
            'profile_complete' => true,
        ]);
    }

    /**
     * Retourne uniquement le NOMBRE de matchs (requête légère pour le hero home).
     *
     * Utilisé par le front (home page, section hero) pour afficher :
     *   "Tu as 12 opportunités qui correspondent à ton profil"
     * sans avoir besoin de charger toute la liste.
     *
     * En V1 cette route est implémentée simplement (appelle getMatchesForUser en interne).
     * En V2, si la performance est critique, on pourra ajouter un cache Redis ici.
     *
     * Format de réponse :
     * { "count": 12 }
     */
    #[Route('/count', name: 'count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        // Vérification autorisation (même voter que myMatches)
        $this->denyAccessUnlessGranted(MatchingVoter::MATCHING_VIEW);

        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['count' => 0], Response::HTTP_UNAUTHORIZED);
        }

        // ── Garde "profil incomplet" — cohérente avec myMatches() ───────────────
        // Si le profil n'est pas complet, on retourne 0 sans calculer les matchs.
        // Cela évite d'exposer des compteurs de matchs à des utilisateurs dont
        // le profil n'est pas encore exploitable (peut induire une fausse confiance).
        if (!$this->profileChecker->isComplete($user)) {
            return $this->json(['count' => 0]);
        }

        // Profil complet : countMatchesForUser() appelle getMatchesForUser() en interne
        // et ne retourne que les scores > 0 (cf. MatchingService::countMatchesForUser).
        $count = $this->matchingService->countMatchesForUser($user);

        return $this->json(['count' => $count]);
    }

}


<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Matching\MatchResult;
use App\Entity\User;
use App\Security\Voter\MatchingVoter;
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
 * CAS "PROFIL INCOMPLET" :
 *   Si l'artiste n'a pas de profil (ArtistProfile null) ou n'a pas rempli son profil
 *   de matching, MatchingService retourne [] (liste vide).
 *   On retourne alors un JSON avec status 200 et un message clair, PAS une erreur 500.
 */
#[Route('/api/matching', name: 'api_matching_')]
final class MatchingController extends AbstractController
{
    public function __construct(
        private readonly MatchingService $matchingService,
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

        // Calcule la complétude réelle du profil (pour adapter le message front).
        // On distingue "profil créé mais vide" (profile_complete = false) de "profil utilisable"
        // (profile_complete = true) afin que le front puisse afficher une invitation ciblée.
        $profileComplete = $this->isProfileComplete($user);

        // Délègue tout le scoring au MatchingService
        // Si le profil est null ou incomplet, MatchingService retourne [].
        $matches = $this->matchingService->getMatchesForUser($user);

        // Construit le tableau de réponse JSON.
        // On filtre les matchs avec score > 0 pour le compteur affiché à l'utilisateur
        // (un match avec score 0 = aucun critère commun → pas pertinent).
        $positiveMatches = array_values(
            array_filter($matches, fn(MatchResult $r) => $r->score > 0)
        );

        return $this->json([
            // Nombre de matchs pertinents (score > 0) — affiché dans le hero home
            'count'            => count($positiveMatches),
            // Liste des matchs classés du meilleur au moins bon (score décroissant)
            // On n'inclut QUE les matchs avec score > 0 dans la liste retournée
            // (les ressources sans aucun critère commun ne servent pas à l'utilisateur)
            'matches'          => array_map(
                fn(MatchResult $r) => $r->toArray(),
                $positiveMatches
            ),
            // Indicateur de profil utilisable pour le matching.
            // false → "profil créé mais vide" ou "pas de profil" → le front affiche
            //         une invitation à compléter le profil artiste.
            // true  → au moins une discipline ET un objectif lookingFor renseignés.
            'profile_complete' => $profileComplete,
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

        // countMatchesForUser() appelle getMatchesForUser() en interne.
        // On ne filtre que les scores > 0 (cf. MatchingService::countMatchesForUser).
        $count = $this->matchingService->countMatchesForUser($user);

        return $this->json(['count' => $count]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Détermine si le profil artiste est suffisamment rempli pour que le matching soit utile.
     *
     * CRITÈRES DE COMPLÉTUDE :
     *   1. L'utilisateur doit avoir un ArtistProfile (non null).
     *   2. Le profil doit avoir au moins une discipline renseignée.
     *      Pourquoi : sans discipline, la composante "disciplines communes" (40 pts max)
     *      sera toujours 0, ce qui dégrade fortement la pertinence des matchs.
     *   3. L'utilisateur doit avoir renseigné au moins un objectif lookingFor.
     *      Pourquoi : sans lookingFor, la composante "ce que cherche l'artiste" (30 pts max)
     *      sera toujours 0. L'artiste verra des ressources classées quasi aléatoirement.
     *
     * NOTE : le lookingFor est sur User, pas sur ArtistProfile.
     * C'est cohérent avec l'onboarding (User::$lookingFor est renseigné à l'étape 3).
     *
     * Ce helper vit dans le controller (et non dans MatchingService) car c'est
     * une préoccupation d'affichage (savoir quoi dire dans la réponse JSON),
     * pas une préoccupation de scoring. MatchingService gère le calcul de score ;
     * c'est au controller de décider quoi indiquer au front sur l'état du profil.
     *
     * @param User $user L'utilisateur connecté (ROLE_ARTIST garanti par le voter)
     * @return bool true si le profil est utilisable pour le matching, false sinon
     */
    private function isProfileComplete(User $user): bool
    {
        // Critère 1 : l'ArtistProfile doit exister
        $profile = $user->getArtistProfile();
        if ($profile === null) {
            return false;
        }

        // Critère 2 : au moins une discipline renseignée sur le profil artiste
        if ($profile->getDisciplines()->isEmpty()) {
            return false;
        }

        // Critère 3 : au moins un objectif lookingFor renseigné sur l'utilisateur
        // getLookingFor() retourne null si jamais renseigné, ou un tableau (potentiellement vide).
        // On traite null et tableau vide comme "non renseigné" avec empty().
        $lookingFor = $user->getLookingFor();
        if (empty($lookingFor)) {
            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\Matching;

use App\Entity\Resource;

/**
 * MatchResult — Résultat du scoring d'UNE ressource (opportunité) par rapport au profil d'un artiste.
 *
 * Ce DTO est retourné par MatchingService::scoreResource() et encapsule :
 *   - la ressource évaluée ($resource)
 *   - son score numérique total ($score, 0 à 100)
 *   - le détail de chaque contribution au score ($breakdown), utile pour le debug
 *     et pour afficher ultérieurement une explication à l'utilisateur ("Pourquoi ce match ?")
 *
 * Pourquoi un DTO et non un tableau brut ?
 *   - Typage strict : PHPStan peut vérifier que tout le code appelant manipule
 *     les bonnes propriétés avec les bons types.
 *   - Autocomplétion IDE : $result->getScore() plutôt que $result['score'].
 *   - Évolutivité : ajouter un champ (ex: "motif de rejet") ne casse aucun appelant.
 *
 * Le DTO est en "readonly" (propriétés en lecture seule après construction) car
 * un résultat de scoring ne doit JAMAIS être modifié après calcul — c'est une
 * valeur immuable produite par le service, consommée par le controller/template.
 *
 * Sérialisation JSON :
 *   Le MatchingController utilise ce DTO directement pour construire la JsonResponse.
 *   La méthode toArray() expose les données sous forme de tableau PHP prêt pour json_encode.
 */
final class MatchResult
{
    /**
     * @param Resource              $resource  La ressource (opportunité) qui a été scorée
     * @param int                   $score     Score total de 0 à 100 (plus c'est haut = meilleur match)
     * @param array<string, int>    $breakdown Détail du score par critère, ex :
     *                                          ['disciplines' => 30, 'looking_for' => 20, 'territory' => 10, 'experience' => 5]
     *                                          Permet le debug et l'explication future ("Pourquoi ce match ?")
     */
    public function __construct(
        public readonly Resource $resource,
        public readonly int $score,
        public readonly array $breakdown,
    ) {}

    /**
     * Convertit le résultat en tableau PHP pour la sérialisation JSON.
     *
     * Appelé par MatchingController pour construire la JsonResponse sans avoir
     * à connaître la structure interne de ce DTO.
     *
     * Format de sortie :
     * {
     *   "resource_id": 42,
     *   "title": "Résidence de création 2026",
     *   "resource_type": "Résidence artistique",
     *   "score": 65,
     *   "breakdown": { "disciplines": 30, "looking_for": 20, "territory": 10, "experience": 5 },
     *   "deadline": "2026-09-30",
     *   "city": "Paris",
     *   "country": "France",
     *   "logo_url": "https://..."
     * }
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // On récupère la deadline formatée en ISO 8601 si elle existe.
        // null → on passe null au JSON (le front saura qu'il n'y a pas de date limite).
        $deadline = $this->resource->getDeadline();

        return [
            'resource_id'   => $this->resource->getId(),
            'title'         => $this->resource->getTitle(),
            // Le type de ressource (Résidence, Formation, Bourse...) est utile
            // pour le front qui veut afficher un label ou un badge de catégorie.
            'resource_type' => $this->resource->getResourceType()->getName(),
            'score'         => $this->score,
            // Le breakdown est exposé pour faciliter le debug et la future UI
            // "Pourquoi ce match ?" (Lot C). En production on pourrait l'omettre.
            'breakdown'     => $this->breakdown,
            // La deadline en format lisible ISO (YYYY-MM-DD) ou null
            'deadline'      => $deadline !== null ? $deadline->format('Y-m-d') : null,
            // Localisation géographique — utile pour le front (badge "Paris", etc.)
            'city'          => $this->resource->getCity(),
            'country'       => $this->resource->getCountry(),
            // URL du logo de l'organisme — le front affichera le badge "B" si null
            'logo_url'      => $this->resource->getLogoUrl(),
            // URL externe normalisée pour le bouton "Voir" sur les cartes de swipe (Lot C)
            'external_url'  => $this->resource->getExternalUrlNormalized(),
        ];
    }
}

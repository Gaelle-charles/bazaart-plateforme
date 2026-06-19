---
name: feedback_matching_lot_b_patterns
description: Module Matching Lot B (ADR-0021) — patterns et anti-patterns identifiés — juin 2026
metadata:
  type: feedback
---

# Module Matching Lot B — patterns et anti-patterns (juin 2026)

## Architecture globale
PHPStan 0 erreur, 21/21 tests PASS. Voter correctement écrit avec Security::isGranted().
Pas de logique métier dans le controller. DTO readonly propre.

## Faux positif à ne PAS signaler
- Le fetch-join disciplines dans findPublishedForMatching() sans LIMIT est intentionnel et documenté.
  Le commentaire explique pourquoi on n'utilise pas le Paginator ici.
- La double invocation findPublished() dans /count est documentée et acceptable en V1
  (commentaire mentionne une optimisation Redis V2).

## Anti-patterns résiduels

### Double exécution du pipeline complet dans /count
`countMatchesForUser()` appelle `getMatchesForUser()` en interne, qui appelle `findPublishedForMatching()`.
Sur la route `/api/matching/count`, le catalogue complet est chargé puis scoré pour ne retourner qu'un entier.
En V1 acceptable, mais à noter pour V2 (cache Redis ou COUNT SQL dédié).

### `profile_complete` sémantiquement trop faible
Le champ `profile_complete` dans la réponse JSON vaut `true` dès que `ArtistProfile` existe,
même si l'artiste n'a aucune discipline ni localisation. Le front ne peut pas distinguer
"profil vide" de "profil complet" pour afficher la bonne incitation.

### Mapping lookingFor → ResourceType : couverture incomplète des types réels
Types en base : Appel à projets / Résidence artistique / Bourse & Financement / Formation / Prix & Concours.

- `RESSOURCES_AIDES` matche "prix" → matche "Prix & Concours" (type à vocation concours, pas aide financière).
  Faux positif possible : un artiste cherchant une aide financière obtient des concours.
  **Why:** le mot-clé 'prix' est dans RESSOURCES_AIDES mais "Prix & Concours" est plutôt RESSOURCES_APPELS.
- `FORMATIONS` contient le mot-clé 'stage' : inoffensif aujourd'hui (aucun type ne contient "stage"),
  mais si un type "Emploi & stage" est ajouté en V2, ce mot-clé générerait des faux positifs.
- Les types Diffusion/Documentation/Autre mentionnés dans le cahier des charges ne sont PAS en base
  (uniquement 5 types). La couverture est donc exhaustive avec les types existants.

**How to apply:** lors de tout ajout de ResourceType, revérifier le mapping LOOKING_FOR_TO_TYPE_KEYWORDS
et notamment retirer 'prix' de RESSOURCES_AIDES ou le déplacer vers RESSOURCES_APPELS.

### scoreTerritory : faux positif si country très court
Si `resourceCountry = "an"` (valeur fictive), `str_contains("plan, France", "an")` retourne true.
Risque négligeable avec les pays réels (noms > 4 caractères), acceptable en V1.

### Score 0 dans /my-matches — triage silencieux
Les ressources avec score 0 sont filtrées dans la réponse JSON de `/my-matches` mais pas dans
`/count`. `countMatchesForUser()` filtre score > 0. Il n'y a pas d'incohérence fonctionnelle
mais le commentaire dans le controller pourrait induire en erreur (il dit que /count est "plus léger").

## Bonne pratique confirmée
- Voter avec `Security::isGranted()` : pattern correct, ne pas modifier.
- `scoreResource()` public pour les tests unitaires directs : pattern correct.
- `getScoreWeights()` exposé publiquement pour les tests : pattern correct.
- `usort()` avec tri secondaire par ID décroissant : déterminisme garanti.

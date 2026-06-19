---
name: project-matching-lot-b
description: ADR-0021 Lot B livré : MatchingService (moteur scoring), DTO MatchResult, MatchingVoter, MatchingController (JSON), 21 tests unitaires
metadata:
  type: project
---

Lot B du programme matching (ADR-0021) livré le 2026-06-19.

**Fichiers créés :**
- `src/DTO/Matching/MatchResult.php` : DTO immutable (readonly), score + breakdown + toArray() pour JSON
- `src/Service/MatchingService.php` : moteur de scoring ; 4 composantes
- `src/Security/Voter/MatchingVoter.php` : MATCHING_VIEW, réservé ROLE_ARTIST
- `src/Controller/MatchingController.php` : GET /api/matching/my-matches + GET /api/matching/count (JSON)
- `tests/Unit/Service/MatchingServiceTest.php` : 21 tests unitaires, 32 assertions, 0 erreur

**Méthode ajoutée dans ResourceRepository :**
- `findPublishedForMatching()` : filtre Published + deadline non expirée (minuit Paris), fetch-join resourceType + disciplines, sans pagination

**Modèle de scoring (total = 100 pts) :**
- Disciplines communes : max 40 pts (ratio commun/total ressource)
- LookingFor vs type ressource : max 30 pts (matching par mots-clés dans ResourceType::name)
- Territoire : max 20 pts (10 pays + 10 ville, via str_contains sur location artiste)
- Experience : max 10 pts (toujours 0 en V1 car champ absent de ArtistProfile)

**Hypothèses de mapping LookingFor → mots-clés ResourceType (à valider par Gaëlle) :**
- FORMATIONS : formation, atelier, workshop, master, stage, cours
- RESSOURCES_AIDES : bourse, financement, aide, subvention, prix, fonds, grant
- RESSOURCES_APPELS : appel, résidence, residence, concours, commission, projet

**Open questions transmises :**
- Le mapping mots-clés est une hypothèse -- valider avec Gaëlle.
- ArtistProfile::$location est un texte libre, pas structuré ville/pays.
- experienceLevel manque sur ArtistProfile (score experience = 0 en V1).

**Lots suivants :**
- Lot C : UI swipe home + action intéressé/passer + alerte consentement
- Lot D : paywall freemium (compteur 3/semaine)
- Lot E : annuaire structures

**Why:** Gaëlle veut afficher les matchs en hero de la home + données prêtes pour le swipe (Lot C).
**How to apply:** Le controller est JSON-first. Le Lot C consommera /api/matching/my-matches depuis Stimulus.

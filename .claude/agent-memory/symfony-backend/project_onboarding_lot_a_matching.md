---
name: project-onboarding-lot-a-matching
description: Lot A ADR-0021/0022 : refonte onboarding pour le matching artiste, gating désactivé, enum LegalStatus, nouvelle étape 4
metadata:
  type: project
---

Lot A matching (ADR-0021/0022) livré sur branche `demo`.

**Changements principaux :**

1. **Enum `LegalStatus`** créé (`src/Enum/LegalStatus.php`) : 6 cas (ARTISTE_AUTEUR, AUTOENTREPRENEUR, ASSOCIATION, SOCIETE, EN_STRUCTURATION, AUTRE) avec `label()` FR.

2. **Champ `legalStatus`** ajouté sur `ArtistProfile` : nullable, `enumType: LegalStatus::class`, VARCHAR(30). Migration `Version20260619183629`.

3. **Onboarding reformulé** : 4 étapes maintenues mais l'ancienne étape 4 "alertes" est SUPPRIMÉE.
   - Étape 2 : profil (nom + discipline + localisation) — inchangée
   - Étape 3 : lookingFor ("que recherches-tu") — inchangée
   - Nouvelle étape 4 : statut juridique (optionnel) + finalisation
   - DTO `OnboardingStep4MatchingDTO` remplace `OnboardingStep4DTO` dans l'onboarding
   - L'ancien `OnboardingStep4DTO` (alertes) reste dans le codebase pour usage futur

4. **Gating désactivé** : `OnboardingGatingListener::__invoke()` retourne immédiatement. La vérification email (UserChecker) reste active. Le gating de profil complet sera dans MatchingController (Lot C).

5. **AuthController** : après confirmation email, redirige vers `/dashboard` (plus vers `/onboarding/etape-1`).

**Why:** L'onboarding couplé à un gating global bloquait l'accès au site pour les nouveaux utilisateurs avant même d'avoir exploré. Le matching (Lot C) devra gérer la vérification de profil de façon contextuelle.

**How to apply:** Pour Lot C, implémenter la logique "profil complet pour le matching" dans le MatchingController, pas dans OnboardingGatingListener. Utiliser `$user->getArtistProfile()->getLegalStatus()` pour filtrer les opportunités selon le statut.

Fichiers clés :
- `src/Enum/LegalStatus.php`
- `src/Entity/ArtistProfile.php` (getter/setter legalStatus)
- `src/DTO/Onboarding/OnboardingStep4MatchingDTO.php`
- `src/Service/OnboardingService.php` (méthode saveStep4AndComplete — sans alertes)
- `src/Controller/OnboardingController.php`
- `src/EventListener/OnboardingGatingListener.php` (gating désactivé)
- `src/Controller/AuthController.php` (redirection post-email vers dashboard)
- `templates/onboarding/step4.html.twig` (nouvelle — statut juridique)
- `migrations/Version20260619183629.php`

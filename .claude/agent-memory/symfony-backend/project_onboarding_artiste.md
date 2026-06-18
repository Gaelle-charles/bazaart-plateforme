---
name: project-onboarding-artiste
description: Onboarding obligatoire artiste (Lot 2) — parcours 4 étapes, gating listener, migration, email bienvenue
metadata:
  type: project
---

# Onboarding artiste — Lot 2

Livré le 18 juin 2026. Parcours obligatoire pour les nouveaux comptes (non-admin/moderator/structure).

## Architecture

**Gating** : `OnboardingGatingListener` sur `KernelEvents::REQUEST` (priority 7 — après firewall priority 8).
Redirige vers `app_onboarding_step1` si `user.onboardingCompleted = false`.
Whitelist : routes `app_onboarding_*`, `app_structure_*`, `app_logout`, `app_verify_email`, `app_check_email`, `app_resend_verify_email`, `app_forgot_password`, `app_reset_password`, `_`.
ATTENTION : les deux routes password se nomment exactement `app_forgot_password` et `app_reset_password` (pas de préfixe `app_password_reset_`).
Exemptés : ROLE_ADMIN, ROLE_MODERATOR, ROLE_STRUCTURE — via `AuthorizationCheckerInterface::isGranted()` (jamais getRoles()).

**Étapes** :
1. `/onboarding/etape-1` — Artiste ou Structure ? (si Structure → `app_structure_register`)
2. `/onboarding/etape-2` — Profil artiste (displayName obligatoire, au moins 1 discipline)
3. `/onboarding/etape-3` — Que recherches-tu ? (ArtistLookingFor enum, case "Autre" + champ libre)
4. `/onboarding/etape-4` — Alertes ressources (disciplines + types + fréquence, pré-sélection intelligente)

**À la fin** : `user.onboardingCompleted = true`, email de bienvenue nominatif envoyé.

## Champs User ajoutés

- `onboarding_completed` : bool, NOT NULL, default false
- `looking_for` : json nullable (valeurs ArtistLookingFor::value)
- `looking_for_other` : varchar(255) nullable

Migration : `Version20260618210427` — inclut `UPDATE users SET onboarding_completed = true` pour les comptes existants.

## Enum

`App\Enum\ArtistLookingFor` : FORMATIONS, RESSOURCES_AIDES, RESSOURCES_APPELS, AUTRE

## Mapping pré-sélection étape 4

Dans `OnboardingService::LOOKING_FOR_TO_RESOURCE_TYPES` :
- RESSOURCES_AIDES → "Bourse & Financement"
- RESSOURCES_APPELS → "Appel à projets" + "Résidence artistique" + "Prix & Concours"
- FORMATIONS → "Formation"

## Fichiers clés

- `src/Enum/ArtistLookingFor.php`
- `src/EventListener/OnboardingGatingListener.php`
- `src/Controller/OnboardingController.php`
- `src/Service/OnboardingService.php`
- `src/DTO/Onboarding/OnboardingStep2DTO.php`, `Step3DTO.php`, `Step4DTO.php`
- `templates/onboarding/step1–4.html.twig`
- `templates/emails/welcome_onboarding.html.twig` + `.txt.twig`

## Fixtures

- `admin@bazaart.fr`, `artiste@bazaart.fr`, `structure@bazaart.fr` : `onboardingCompleted=true`
- `artiste@bazaart.fr` : `lookingFor=["ressources_appels","formations"]` + ResourceAlert daily
- Nouveau compte après cette date : `onboardingCompleted=false` par défaut

## AuthController

Après vérification email (verifyEmail()) : si `!user.isOnboardingCompleted()` → redirect vers `app_onboarding_step1`, sinon `app_dashboard`.

**Re-clic lien email déjà vérifié (correctif lot 3)** :
- Si user déjà connecté → dashboard direct.
- Si non connecté + signature valide → `handleVerification()` (idempotent) + `Security::login()` + dashboard.
- Si non connecté + signature invalide/expirée → flash info + /login. Jamais de connexion sans signature valide.

## Email de bienvenue

L'URL du dashboard est générée dynamiquement via `UrlGeneratorInterface::ABSOLUTE_URL` dans `OnboardingService::sendWelcomeEmail()`. Variable `{{ dashboardUrl }}` dans les deux templates. Plus de hardcode `https://bazaart.fr/dashboard`.

**Why:** Lot 2 CDC ADR-0015, onboarding obligatoire bloquant avant accès au dashboard.
**How to apply:** Si un futur ticket touche l'inscription ou la vérification email, garder ce gating.

# ADR-0029 — ROLE_ARTIST attribué dès l'inscription (suppression du statut "user seul")

- **Date** : 2026-06-27
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Dans le modèle de rôles initial, tout nouveau compte recevait `ROLE_USER` à la
création (`AuthService::register`, `GoogleAuthenticator`). `ROLE_ARTIST` n'était
accordé qu'à la **complétion du profil** (`OnboardingService::saveStep2`, déclenché
par l'onboarding ou la soumission du formulaire de matching). `ROLE_STRUCTURE` est
accordé séparément, à la validation admin d'une candidature structure.

Conséquence observée dans l'admin (investigation du 2026-06-27, prod) : sur 23 comptes,
**19 étaient "user seul"** (ni artiste, ni structure, ni admin), dont 18 avec email
confirmé mais **profil jamais complété** (inscriptions abandonnées avant la fin du
profil). Ce statut "user" intermédiaire est ambigu : Gaëlle attend que chaque compte
soit clairement **artiste** ou **structure**.

Entrées produisant un compte "user seul" :
1. Inscription classique (`/register`).
2. Google OAuth (premier login).
3. Anonymisation RGPD (réinitialise à `ROLE_USER`).

## Options envisagées

1. **Option A — `ROLE_ARTIST` dès l'inscription** : tout inscrit devient artiste
   immédiatement, même profil incomplet. Les structures reçoivent `ROLE_STRUCTURE`
   en plus, via la validation admin.
   - ✅ Plus aucun compte "user seul" ; admin clair (artiste / structure).
   - ✅ Ne casse pas le matching : `MatchingProfileChecker::isComplete()` continue de
     conditionner l'accès réel aux cartes (le gating reste basé sur la complétude,
     pas sur le rôle). La home affiche le formulaire inline tant que le profil
     n'est pas complet.
   - ⚠️ `ROLE_ARTIST` ne signifie plus "profil complété", mais "compte de type artiste".
   - ⚠️ Un opérateur de structure qui s'inscrit puis se fait valider aura
     `ROLE_ARTIST` + `ROLE_STRUCTURE` (double rôle). Acceptable (une personne peut
     être artiste et gérer une structure) ; affinable plus tard si besoin.

2. **Option B — Garder le modèle, renommer le chip admin** "user" → "Inscrit·e
   (profil à compléter)".
   - ✅ Aucun changement de logique.
   - ❌ Ne répond pas au besoin : il reste un statut intermédiaire visible.

3. **Option C — Demander artiste/structure à l'inscription** et poser le rôle en
   conséquence.
   - ✅ Rôle exact dès le départ.
   - ❌ Plus lourd ; l'onboarding propose déjà ce choix (étape 1) en aval.

## Décision

**Option A.** `ROLE_ARTIST` est attribué dès la création du compte
(`AuthService::register` et `GoogleAuthenticator`). L'anonymisation RGPD continue de
réinitialiser à `ROLE_USER` (compte en cours de suppression, ne doit pas rester artiste).

## Conséquences

- **Code** : `setRoles(['ROLE_USER'])` → `setRoles(['ROLE_ARTIST'])` dans
  `AuthService::register` et `GoogleAuthenticator` (création de compte). On ne stocke
  que le rôle supplémentaire ; `ROLE_USER` est ajouté par `User::getRoles()` et par la
  hiérarchie `ROLE_ARTIST: [ROLE_USER]`.
- **Backfill** : les comptes existants "user seul" (hors admin/structure/anonymisés)
  sont passés à `ROLE_ARTIST` par un `UPDATE` SQL ponctuel en prod.
- **Matching inchangé** : le gating réel reste `MatchingProfileChecker::isComplete()`.
  La machine d'états de la home affiche le formulaire inline pour un artiste au profil
  incomplet, et les cartes une fois complet.
- **Structures** : parcours inchangé (`/structure/register` + validation admin ajoute
  `ROLE_STRUCTURE`). Possibilité d'affiner plus tard (retirer `ROLE_ARTIST` quand on
  devient structure) si le double rôle pose problème.
- **À surveiller** : aucune route ne doit supposer que `ROLE_ARTIST` implique un profil
  complet (utiliser `MatchingProfileChecker` pour cela).

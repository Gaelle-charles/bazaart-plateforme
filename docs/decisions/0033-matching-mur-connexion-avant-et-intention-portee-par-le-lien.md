# ADR-0033 — Matching : mur de connexion AVANT le formulaire, intention portée par le lien de confirmation

- **Date** : 2026-07-06
- **Statut** : arbitré
- **Décidé par** : Gaëlle
- **Révise** : ADR-0026 (connexion obligatoire, sans onboarding obligatoire)

## Contexte

L'ADR-0026 avait retenu un parcours « remplir d'abord, se connecter ensuite » : un
visiteur non connecté pouvait remplir les 3 étapes du formulaire matching, ses réponses
étant stockées en session, puis il s'inscrivait pour voir ses matchs. Objectif de l'époque :
réduire la friction avant inscription.

Deux problèmes constatés à l'usage :

1. **Perte de l'intention après inscription cross-device.** La redirection post-confirmation
   (`AuthController::verifyEmail`) repose sur deux signaux **en session** : `SESSION_INTENT_KEY`
   (`?intent=artist`) et les données matching en session. Or le parcours réel d'un nouvel
   inscrit est : register (navigateur) → email → clic sur le lien depuis le **téléphone**
   (autre session) → confirmation. Les deux signaux sont alors absents, et `verifyEmail`
   retombe sur `app_dashboard` : l'utilisateur perd son intention de matching.
   Pire, dans le parcours « remplir d'abord », les **réponses saisies** vivent aussi en
   session et sont donc perdues sur l'autre appareil.

2. **UX** : Gaëlle veut que le visiteur se **connecte avant de commencer** le matching,
   plutôt que de remplir un formulaire qu'il devra reperdre.

## Options envisagées

**Sur le moment du gating (avant/après connexion) :**
1. **Avant** (remplir puis se connecter) — l'existant ADR-0026.
   - ➕ Meilleure conversion (le visiteur voit la valeur avant de s'inscrire).
   - ➖ Trimballe des réponses à travers register → email → login ; fragile et perdu en cross-device.
2. **Après** (se connecter d'abord, puis remplir) — **retenue**.
   - ➕ Ne préserve que l'intention (une destination), pas un payload de réponses.
   - ➕ Simplifie la refonte du formulaire (un seul état « connecté »).
   - ➖ Login demandé « à froid », conversion potentiellement moindre. Jugé acceptable :
     le compte est de toute façon obligatoire sur la plateforme.

**Sur la préservation de l'intention à travers la confirmation d'email :**
- A. **Intention dans le lien signé** (`generateSignature` 4e param `extraParams`) — **retenue**.
  Device-independent (l'intention voyage dans le lien cliqué), aucune migration.
- B. Intention persistée sur l'entité `User` (colonne + migration). Aussi robuste mais plus lourd.

## Décision

1. **Mur de connexion AVANT le matching** : un visiteur non connecté voit un écran
   d'accroche court avec un CTA « Se connecter / S'inscrire pour matcher ». Le formulaire
   multi-étapes n'est rendu que pour les utilisateurs connectés.
2. **Intention portée par le lien de confirmation** (variante A) : à l'inscription via le
   CTA matching, `EmailVerificationService::sendVerificationEmail()` ajoute `intent=matching`
   aux `extraParams` de la signature. `verifyEmail` lit l'intention **depuis l'URL** (plus
   depuis la seule session) et redirige vers le matching, quel que soit l'appareil.
   Fin de l'atterrissage dashboard cross-device.
3. **Connexion simple** (utilisateur existant) : le CTA du mur pose `target_path` = page
   matching. Même session, redirection directe vers le matching (mécanisme déjà en place,
   cf. `LoginSuccessHandler`, commit 511bbdc).
4. **Refonte design** du formulaire multi-étapes et du swipe : Option A (charte actuelle
   conservée), en reprenant la structure du prototype « BazaArt - Matching Swipe »
   (hero, cartes, badge de score, étapes) sans importer ses fonts ni ses couleurs.

## Conséquences

- Le parcours anonyme du formulaire (remplissage libre + stockage session) est **retiré** :
  simplification de `_matching_form.html.twig`, `matching-form.js`, `HomeController` et
  `AuthController` (l'intent `artist` devient `intent=matching` porté par le lien).
- `EmailVerificationService::sendVerificationEmail()` prend un paramètre d'intention
  optionnel. La signature du lien change (extraParams) : la validation dans `verifyEmail`
  doit rester cohérente avec les params signés.
- `MatchingFormSessionService` : son rôle de préservation des réponses anonymes disparaît.
  À conserver uniquement si un autre flux l'utilise encore (à vérifier avant retrait).
- Surveille : ne pas casser le parcours de confirmation d'email existant (rate limiter,
  renvoi d'email, cas « déjà connecté »). Tester le parcours complet inscription → Mailpit
  → confirmation depuis un contexte différent → atterrissage matching.
- Périmètre design limité au **formulaire multi-étapes** et au **swipe** (pas la vue
  `/resources` matching de l'ADR-0031, pas le hero d'accueil hors matching).

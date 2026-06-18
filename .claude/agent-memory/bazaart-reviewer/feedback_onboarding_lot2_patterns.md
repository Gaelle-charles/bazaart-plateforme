---
name: feedback_onboarding_lot2_patterns
description: ADR-0015 Lot 2 — onboarding artiste obligatoire (4 étapes + gating). Patterns et anti-patterns identifiés lors de la relecture juin 2026.
metadata:
  type: feedback
---

## Patterns identifiés — Onboarding Lot 2 (juin 2026)

**Correction priority listener (info) :** priority 7 (après firewall priority 8) est correct et bien documenté. Ne pas signaler comme bogue.

**Bug critique C1 — Whitelist reset-password invalide :**
La WHITELISTED_ROUTES du gating déclare `'app_password_reset_'` (préfixe inexistant). Les vraies routes sont `app_forgot_password` (exact, OK) et `app_reset_password` (exact, manquant). Un user non onboardé ne peut pas réinitialiser son mot de passe.
**Why:** collision de nommage entre le préfixe anticipatoire et la vraie route.
**How to apply:** à chaque relecture d'une whitelist de gating, vérifier chaque entrée contre `debug:router` ou grep sur Controller.

**Anti-pattern récurrent C2 — getRoles() pour l'exemption admin :**
OnboardingGatingListener utilise `in_array('ROLE_ADMIN', $user->getRoles())` au lieu de `AuthorizationCheckerInterface::isGranted()`. Pattern documenté dans [[feedback_getRoles_hierarchy]].
**How to apply:** dans les EventListener sur kernel.request, injecter AuthorizationCheckerInterface (token disponible car on a vérifié qu'il est non null).

**Avertissement A1 — Double id HTML :**
step3.html.twig génère deux attributs `id` sur le même `<input>` pour la case "autre" : `id="lf_autre"` (boucle générique) puis `id="cb_autre"` (override inline). Le JS cherche `cb_autre`. Comportement navigateur-dépendant.

**Avertissement A3 — ROLE_MODERATOR non exempté du gating :**
Seul ROLE_ADMIN est exempté. Un modérateur créé manuellement sera bloqué sur l'onboarding artiste qui n'est pas son parcours.

**Avertissement A4 — Longueur displayName non validée côté serveur :**
Le maxlength HTML est 100 mais saveStep2() ne valide pas mb_strlen(). Contournement possible par curl.

**Avertissement A6 — URL en dur dans email de bienvenue :**
`https://bazaart.fr/dashboard` hardcodé dans welcome_onboarding.html.twig et .txt.twig. Lien pointe prod même depuis dev/Mailpit. Pattern récurrent ([[feedback_notifications_module_patterns]] getLink() URLs en dur).

**Guard step4 :** vérifie `getLookingFor() === null` mais pas `empty()`. Un tableau vide passerait le guard. Risque faible (saveStep3 valide au moins 1 valeur).

**CSS duplication :** classes .ob-progress, .ob-step-dot, .ob-title etc. dupliquées dans les 4 templates onboarding. À consolider dans un onboarding.css.

**Enum labels sans accents :** ArtistLookingFor::label() retourne des chaines sans certains accents ("appels a projets", "precise ci-dessous"). Visible utilisateur.

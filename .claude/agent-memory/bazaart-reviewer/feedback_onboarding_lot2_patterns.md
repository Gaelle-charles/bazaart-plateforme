---
name: feedback_onboarding_lot2_patterns
description: ADR-0015 Lot 2 + ADR-0021/0022 Lot A — onboarding artiste (4 étapes + gating + matching). Patterns et anti-patterns identifiés lors des relectures juin 2026.
metadata:
  type: feedback
---

## Patterns identifiés — Onboarding Lot 2 (juin 2026)

**Correction priority listener (info) :** priority 7 (après firewall priority 8) est correct et bien documenté. Ne pas signaler comme bogue.

**Bug critique C1 — Whitelist reset-password invalide (Lot 2, corrigé en Lot A par désactivation) :**
La WHITELISTED_ROUTES du gating déclarait `'app_password_reset_'` (préfixe inexistant). Les vraies routes sont `app_forgot_password` et `app_reset_password`. En Lot A, le gating global est désactivé → bogue neutralisé mais non corrigé structurellement.
**Why:** collision de nommage entre le préfixe anticipatoire et la vraie route.
**How to apply:** à chaque relecture d'une whitelist de gating, vérifier chaque entrée contre `debug:router`.

**Anti-pattern récurrent C2 — getRoles() pour l'exemption admin (Lot 2, neutralisé en Lot A) :**
OnboardingGatingListener utilisait `in_array('ROLE_ADMIN', $user->getRoles())` au lieu de `AuthorizationCheckerInterface::isGranted()`. Pattern documenté dans [[feedback_getRoles_hierarchy]].
En Lot A : le listener retourne immédiatement sans aucun check → bogue neutralisé.

**Avertissement A1 — Double id HTML :**
step3.html.twig génère deux attributs `id` sur le même `<input>` pour la case "autre" : `id="lf_autre"` (boucle générique) puis `id="cb_autre"` (override inline). Le JS cherche `cb_autre`. Comportement navigateur-dépendant.

**Avertissement A3 — ROLE_MODERATOR non exempté du gating (neutralisé en Lot A) :**
En Lot 2, seul ROLE_ADMIN était exempté. En Lot A, le gating global est désactivé → sans effet actuel. À surveiller si Lot C réintroduit un gating partiel.

**Guard step4 (Lot A) :** vérifie `getLookingFor() === null` mais pas `empty()`. Un tableau vide passerait le guard sans être redirigé vers step3. Risque faible car saveStep3 valide au moins 1 valeur.

---

## Patterns identifiés — Lot A matching (ADR-0021/0022, juin 2026)

**Point de vigilance prioritaire — Confirmation email toujours enforced :**
Le relâchement du gating onboarding (OnboardingGatingListener retourne immédiatement) ne touche PAS la vérification d'email. Le UserChecker (checkPreAuth) bloque la connexion si isVerified=false. Les deux mécanismes sont orthogonaux et correctement séparés.

**OnboardingGatingListener désactivé proprement :**
La classe conserve la structure (AsEventListener, priority: 7) mais le __invoke() retourne sans effet. Inerte et sûr. Le commentaire documentaire est complet.

**OnboardingStep4DTO (alertes) orphelin :**
Le fichier `src/DTO/Onboarding/OnboardingStep4DTO.php` reste dans le dossier mais n'est appelé nulle part en Lot A. Toléré (peut servir pour la config alertes en Lot C) mais signaler comme code mort.

**Email welcome_onboarding.html.twig — mention obsolète :**
La ligne "Des alertes personnalisees selon tes interets" dans le bloc "Tu as acces a" fait référence à une feature (alertes onboarding) qui n'est plus créée automatiquement depuis Lot A. Contenu trompeur pour l'utilisateur.

**Dashboard — widget F label trompeur :**
Le widget nommé "Tes alertes actives" affiche en réalité les objectifs lookingFor, non des alertes email configurées. Confusion entre concept "alerte" (technique, ResourceAlert) et objectif (lookingFor). Cosmétique mais trompeur.

**CSS onboarding dupliqué sur 4 templates :**
Classes .ob-progress, .ob-step-dot, .ob-title, .ob-subtitle, .ob-option-item dupliquées dans step1..4.html.twig. À consolider dans un onboarding.css.

**Sujet email de bienvenue — accent absent :**
`'[Bazaart] Bienvenue dans la communaute !'` — "communaute" sans accent (encodage ASCII forcé dans le code PHP). Visible dans la boîte mail de l'utilisateur.

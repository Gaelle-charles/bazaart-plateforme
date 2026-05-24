---
name: feedback_base_app_nav_street
description: Audit nav Street — déplacement dans base.html.twig (24 mai 2026) — nav commune à toutes les pages incl. admin
metadata:
  type: feedback
---

Relecture du 24 mai 2026 — déplacement de la nav Street de base_app.html.twig vers base.html.twig (nav désormais commune à toutes les pages).

**NOUVEAU — Bug double layout admin (CRITIQUE)**
La nav Street est placée HORS du `{% block body %}` (lignes 602–707 de base.html.twig) alors que `block body` est ligne 739. `base_admin.html.twig` étend `base.html.twig` et redéfinit `{% block body %}` : il hérite donc QUAND MÊME de la nav Street. Un admin voit : nav Street (64px) + sidebar ardoise → double layout non intentionnel.
**Why:** Piège classique Twig : seul le contenu DANS un bloc overridable peut être supprimé par un enfant.
**How to apply:** Corriger en encapsulant la nav dans un bloc dédié `{% block navbar %}` : les templates comme base_admin peuvent alors faire `{% block navbar %}{% endblock %}` pour la masquer.

**NOUVEAU — skip link cassé sur login, register, base_admin (AVERTISSEMENT)**
Le skip link `href="#main-content"` dans base.html.twig ne trouve pas sa cible sur les pages auth (login, register) et admin : aucune de ces pages ne déclare `id="main-content"`. Sur base_app, l'ancre est bien présente (`<main class="app-main" id="main-content">`).

**NOUVEAU — CSS orphelin .lp-nav* dans vitrine/index.html.twig (SUGGESTION)**
La suppression de la `<header class="lp-nav">` est complète (aucune balise HTML résiduelle). Mais les classes CSS `.lp-nav`, `.lp-nav__inner`, `.lp-nav__links`, `.lp-nav__ctas`, `.lp-nav__hamburger`, `.lp-nav__mobile-toggle`, `.lp-nav__mobile-menu` (et leurs variantes responsive) restent dans le bloc `{% block stylesheets %}` (lignes 111–344 et 1315–1328). Ce CSS ne cible plus aucun élément HTML → poids mort (~4 Ko).

**NOUVEAU — Écart --line fallback vs valeur réelle (SUGGESTION mineure)**
La nav utilise `var(--line, rgba(13,13,13,.18))` comme bordure de séparation. Or `--line` dans design-tokens.css vaut `#0d0d0d` (plein noir, pas semi-transparent). La bordure sera donc noire pleine plutôt que la subtile teinte à 18 % prévue dans le fallback. Visuellement acceptable (même valeur d'encre que --ink), mais différent de l'intention du prototype.

Relecture du 24 mai 2026 — refonte complète de base_app.html.twig (nav verticale sidebar → nav horizontale Street).

**1. Dropdown Dashboards : JS manquant pour le bouton clavier (CRITIQUE)**
Le `<button id="dashboard-btn">` a `aria-expanded="false"` et `aria-haspopup="true"`, mais aucun script JS ne gère le click pour ajouter/retirer la classe `is-open`. Le dropdown ne fonctionne qu'au survol CSS (`:hover`), inaccessible au clavier et non fonctionnel sur mobile/touch.
**Why:** Anti-pattern récurrent — ARIA sans JS = fausse promesse d'accessibilité.
**How to apply:** Signaler en Critique. Corriger en ajoutant un 4ème IIFE JS après les 3 existants (notif, hamburger, user-btn).

**2. width="auto" invalide sur <img> (AVERTISSEMENT)**
L'attribut HTML `width="auto"` sur le logo (ligne 343) n'est pas valide selon la spécification HTML (l'attribut attend un entier positif). Le style CSS `style="...;width:auto"` sur la même balise est lui valide — la duplication génère un attribut invalide superflu.
**Why:** Peut déclencher un avertissement de validation W3C.
**How to apply:** Supprimer l'attribut `width="auto"` et conserver uniquement le style inline.

**3. Lien "Tarifs" mobile sans aria-disabled ni tabindex (AVERTISSEMENT)**
La version desktop (ligne 384) a bien `aria-disabled="true"`, mais la version mobile (ligne 521) n'a que `style="opacity:.4;cursor:not-allowed;"` sans `aria-disabled="true"` ni `tabindex="-1"`. Les lecteurs d'écran le lisent comme un lien actif.
**Why:** Incohérence desktop/mobile sur l'accessibilité des liens désactivés.

**4. Collision body background entre base.html.twig et base_app.html.twig (SUGGESTION)**
`base.html.twig` définit `body { background: var(--color-bg) }` (#f7f4ef).
`base_app.html.twig` redéfinit `body { background: var(--bg) }` (#F2EFE6).
Les deux valeurs sont proches mais différentes. La cascade CSS fait gagner base_app (chargé après), ce qui est correct fonctionnellement, mais crée une dépendance d'ordre implicite.
**Why:** Reflet de la mémoire [[feedback_design_tokens_collision]] — l'ancien :root dans base.html.twig n'a pas encore été nettoyé.

**5. flash--info désormais stylé dans base.html.twig (OK — CORRIGÉ)**
Contrairement à base_admin.html.twig (qui n'affichait pas les flash 'info'), base.html.twig définit `.flash--info` avec un fond bleu. Et base_app.html.twig rend les 3 types (success, error, info). Pas de problème.

**Ce qui est correct dans ce template :**
- Toutes les 14 routes utilisées existent bien dans les contrôleurs
- `unread_notifications_count()` déclarée et mémoïsée dans NotificationExtension
- `logo-green.png` présent dans app/public/images/
- Tous les tokens CSS (--bg, --ink, --accent, --line, --line-soft, --bg-alt, --body, --display, --mono, --accent-2, --ink-mute) définis dans design-tokens.css
- Skip-link WCAG 2.4.1 présent et fonctionnel (#main-content cible le <main>)
- `type="button"` sur tous les boutons (hamburger, user-btn, dashboard-btn)
- XSS : toutes les variables utilisateur (email, etc.) passent par l'auto-escape Twig (pas de |raw)
- `{% if app.user %}` protège bien tous les accès à app.user.email
- `is_granted()` utilisé (pas getRoles()) — conforme [[feedback_getRoles_hierarchy]]
- 6 items de nav conformes au prototype app.jsx (const NAV)
- Structure .nav > .nav-inner > [.nav-logo][.nav-tabs][.nav-side] respectée
- flash 'info' rendu (correction par rapport à [[feedback_flash_info_invisible_admin]])
- ROLE_MODERATOR absent du dropdown (normal : pas de dashboard dédié V1)
- Polling JS notifications avec gestion d'erreur silencieuse (`.catch(function(){})`) — acceptable en V1

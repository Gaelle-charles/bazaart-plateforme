---
name: project-design-audit
description: État de conformité design Street de chaque template Twig (audit 24 mai 2026)
metadata:
  type: project
---

Audit réalisé le 24 mai 2026. Référence : prototype JSX dans `app/public/uploads/maquette-vitrine/Bazaart-template/src/`.

## Statuts

| Template | Statut | Priorité |
|---|---|---|
| `base.html.twig` | PARTIEL | ⚠️ Avertissement |
| `home/index.html.twig` | NON CONFORME | 🔴 Critique (à archiver ?) |
| `vitrine/index.html.twig` | CONFORME | 💡 Suggestions mineures |
| `base_admin.html.twig` | PARTIEL | ⚠️ Avertissement |
| `dashboard/index.html.twig` | CONFORME | ⚠️ Avertissement (XSS) |
| `auth/login.html.twig` | CONFORME | 💡 Suggestion |
| `auth/register.html.twig` | CONFORME | 🔴 Critique (CSRF contrôleur) |
| `base_app.html.twig` | CONFORME | ⚠️ Avertissement |

**Pas encore auditées** : resource/*, forum/*, article/*, artist_profile/*, structure/*, organization_profile/*, messaging/*, notifications/, post/

## Points critiques

1. **home/index.html.twig** : ancienne page d'accueil non Street, tokens V1. Si la route `/` sert encore ce template, c'est une régression visuelle grave. À clarifier avec Gaëlle.
2. **RegistrationController CSRF** : le token CSRF est inclus dans `register.html.twig` mais n'est pas validé dans le contrôleur (`isCsrfTokenValid` manquant). Protection ineffective.
3. **XSS dashboard** : `{{ post.content|slice(0, 90) }}` sans `|e` dans `dashboard/index.html.twig`.

## Points avertissement transversaux

4. **Double système de tokens** : `base.html.twig` déclare des tokens V1 (`--color-*`) coexistant avec les tokens V2 de `design-tokens.css`. Migration progressive en cours.
5. **Sidebar mobile** : `base_app.html.twig` fixe 240px sans media query ni hamburger → inutilisable sur mobile.
6. **Lien Espace Structure** absent dans la sidebar de `base_app.html.twig` pour ROLE_STRUCTURE.
7. **minlength="8"** au lieu de 10 dans le formulaire d'inscription (CDC V3 impose 10 + majuscule + chiffre).

## Décision attendue de Gaëlle

- `home/index.html.twig` → archiver ou supprimer ? Quelle route sert `/` ?

**Why:** L'audit identifie les écarts avant de coder page par page, évitant de refaire des pages déjà conformes.
**How to apply:** Avant de refaire un template, vérifier ce tableau. Commencer par les pages NON CONFORMES ou PARTIELLES à haute priorité.

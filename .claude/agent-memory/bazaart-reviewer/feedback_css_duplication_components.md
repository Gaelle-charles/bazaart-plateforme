---
name: feedback_css_duplication_components
description: Anti-pattern — chaque template Twig recrée ses propres classes boutons/chips/eyebrow au lieu d'utiliser des classes globales
metadata:
  type: feedback
---

Le prototype JSX expose des classes utilitaires globales (.h-display, .mono, .chip, .btn, .btn-primary, .btn-dark, .btn-ghost, .eyebrow, .container, .input, .label). Les templates Twig recrée des variantes locales préfixées (.auth-btn-submit, .lp-btn, .opp-btn, .db-btn…) ce qui génère de la duplication massive.

**Why:** Il n'existe pas de fichier CSS de composants globaux (type components.css) dans /app/public/css/. Chaque page doit se définir ses propres boutons.

**How to apply:** Proposer systématiquement la création de components.css quand on touche aux styles. Signaler en avertissement les duplications de .btn, .chip, .eyebrow entre templates.

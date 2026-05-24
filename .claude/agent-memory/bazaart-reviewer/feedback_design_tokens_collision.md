---
name: feedback_design_tokens_collision
description: Anti-pattern récurrent — base.html.twig redéfinit des variables CSS dans un bloc <style> inline qui écrasent design-tokens.css
metadata:
  type: feedback
---

base.html.twig contient un bloc `:root {}` inline qui définit --color-green, --color-red, --color-bg, --color-border, --font-heading, --font-body, --radius-sm, --radius-md, --radius-lg, etc. Ces tokens anciens coexistent avec les nouveaux tokens V2 (--bg, --ink, --accent, --display, --body) de design-tokens.css. Les deux familles sont actives simultanément. Les templates issus de l'ancienne version (home/index.html.twig) utilisent les --color-* ; les nouveaux templates (vitrine/index.html.twig, dashboard, auth) utilisent les tokens V2. base_admin.html.twig utilise encore var(--font-heading) et var(--color-border) et var(--color-text) — mélange des deux systèmes.

**Why:** Le refactoring vers V2 est partiel. Les anciens templates n'ont pas été migrés vers les nouveaux tokens. Le bloc :root inline de base.html.twig a été conservé pour ne pas casser ces anciens templates.

**How to apply:** home/index.html.twig et base_admin.html.twig sont les principaux usagers des anciens tokens. Signaler à chaque relecture tout nouveau fichier qui crée un usage --color-* ou --font-heading/--font-body au lieu des tokens V2.

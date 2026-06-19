---
name: project-title-reveal-hero
description: Mécanisme site-wide d'animation de titres (title-reveal) et refonte hero sans vidéo (juin 2026)
metadata:
  type: project
---

Animation des titres site-wide livré en juin 2026.

**Ce qui a été fait :**
- Nouveau fichier CSS : `app/public/css/title-reveal.css` (variante simple : mots qui s'écartent, variante complexe : fondu + glissement vertical)
- Nouveau fichier JS : `app/public/js/title-reveal.js` (IntersectionObserver, progressive enhancement, unobserve après déclenchement)
- Chargés dans `app/templates/base.html.twig` (CSS via `<link>`, JS via `<script defer>`)
- Hero `vitrine/index.html.twig` simplifié : vidéo hero-clap.mp4 retirée (fichier conservé sur disque), photo hero-bazaart.jpg fixe avec parallaxe CSS, animation CSS d'entrée du titre (deux phases : `.js-hero-armed` puis `.is-loaded` sur `#lp-hero-sticky`)

**Conventions importantes à retenir :**
- Progressive enhancement double classe : `.js-hero-armed` arme les éléments (opacity:0), `.is-loaded` déclenche la transition. Sans JS => rien n'est armé => visibles.
- `data-no-reveal="true"` sur le H1 du hero : exclut du mécanisme générique (title-reveal.js) car le hero a sa propre animation CSS.
- `js-title-reveal-ready` sur `<html>` pour armer les classes CSS de title-reveal.js.
- Parallaxe iOS : `background-attachment:scroll` forcé par `@media (hover:none) and (pointer:coarse)`.

**Why:** Gaëlle voulait l'effet "mots qui s'écartent" du hero sur tous les titres du site, et supprimer la vidéo du hero.
**How to apply:** Si de nouvelles pages publiques ont des titres à animer, ajouter leur classe CSS dans le tableau `SELECTORS` de `title-reveal.js`. Pour le hero, modifier uniquement `app/templates/vitrine/index.html.twig`.

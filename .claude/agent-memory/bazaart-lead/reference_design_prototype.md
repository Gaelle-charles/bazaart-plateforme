---
name: reference-design-prototype
description: Où vit le design Street et comment l'exploiter (le prototype standalone.html est une appli React, pas du HTML lisible)
metadata:
  type: reference
---

Le design « Street » de Bazaart vient du fichier `BazaArt Prototype (Street) - standalone.html`
(présent dans `app/public/` et sur le Bureau de Gaëlle).

**Piège important (vécu le 2026-06-15, beaucoup de churn) :** ce fichier est un **export d'outil de design (type Figma Make) = appli React**. Il charge ~13 **scripts JS externes** (noms en hash, ex. `846a4d6e-…`) qui dessinent les écrans au runtime. **Ces fichiers compagnons ne sont PAS dans le projet** → ouvert tel quel, le `.html` ne rend rien, et un `grep` du contenu d'un écran (ex. « formation ») renvoie 0.

**Ce qui EST exploitable** dans le fichier : son `<style>` inline contient **tout le design system** (tokens `:root` `--bg #F2EFE6`, `--ink #0D0D0D`, `--accent #C6F24E`, `--accent-2 #FF6B2C`, polices Archivo Black / Space Grotesk / JetBrains Mono, et composants `.btn` (hover `translate(-2px,-2px)+box-shadow 4px 4px`), `.card`, `.chip`, `.eyebrow`, `.nav`…). Ces tokens sont **déjà repris** dans la plateforme (`app/public/css/design-tokens.css`, classes `--ink`/`--accent`/`--display` partout).

**Pour reproduire un écran précis du prototype :** ne PAS tenter de lire le `.html` → **demander une CAPTURE D'ÉCRAN** à Gaëlle (elle l'ouvre dans son navigateur où il s'affiche). C'est la voie rapide et fiable. (Alternative : qu'elle copie tout le dossier d'export, JS compagnons inclus, ou colle le JSX.)

Exemple fait ainsi : refonte de `course/index.html.twig` (hero « Se former. / Transmettre. » + bande « Ton savoir a de la valeur ») et `course/show.html.twig` (bloc noir + panneau lime) à partir de 4 captures.

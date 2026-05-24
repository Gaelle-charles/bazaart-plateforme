---
name: project-design-system-v2
description: Design system V2 — deux thèmes (Street app / Editorial Cream vitrine), tokens CSS extraits du prototype JSX mai 2026
metadata:
  type: project
---

Design system V2 extrait du prototype React/JSX de mai 2026.
Fichier central : `/Users/belamour/bazaart/app/public/css/design-tokens.css`

**Why:** Migration du design sobre actuel vers un nouveau design validé par la team. Deux thèmes distincts selon la surface (app vs vitrine publique).

**How to apply:** Toujours utiliser `var(--nom-du-token)` dans le CSS, jamais de valeurs hardcodées. Signaler si un token manque plutôt que d'écrire une valeur en dur.

---

## Attribution des thèmes aux templates

- `app/templates/vitrine/index.html.twig` → **thème Street** (réécriture mai 2026). PAS de `data-theme`. Préfixe CSS : `.lp-`. Composant prototype de référence : `LandingManifesto` dans `src/screens-public.jsx`.
- App connectée (dashboard, ressources, forum…) → **thème Street** (`:root` par défaut).
- Ancienne vitrine Editorial Cream : thème activé via `[data-theme="editorial"]` — plus utilisé sur index.html.twig depuis la réécriture.

---

## Deux thèmes

### Thème "Street" (défaut — application + page d'accueil)
Activé par défaut (`:root`). Pages app connectée ET page d'accueil publique.

- **Polices :** Archivo Black (display), Space Grotesk (body), JetBrains Mono (mono), Instrument Serif (serif)
- **Fond :** `#F2EFE6` (crème ocre) + `#EAE6D9` (alt)
- **Encre :** `#0D0D0D` + `#5b584f` (muted)
- **Accents :** `#C6F24E` (vert acide = --accent) + `#FF6B2C` (orange tangerine = --accent-2)
- **Style :** bords FRANCS (border-radius: 0), ombres décalées "affiche", uppercase intensif
- **Container max :** 1440px, nav height 64px

### Thème "Editorial Cream" (vitrine publique)
Activé par `[data-theme="editorial"]` sur `<html>`. Pages publiques : landing, présentation association.

- **Polices :** Fraunces (serif display), DM Sans (body), JetBrains Mono (mono)
- **Fond :** `#FAF7F1` (--paper) + `#F4EFE6` (--cream)
- **Vert forêt :** `#1F4030` (--fern) — hero et sections foncées
- **Accents :** `#F5E821` (--lemon jaune citron) + `#D8694F` (--coral terracotta)
- **Pills pastel :** rose, mint, cream, bleu, gris
- **Style :** border-radius généreux (18-24px), ombres douces, boutons pilule (999px)
- **Container max :** 1360px, nav height 76px

---

## Polices Google Fonts à charger

**Thème Street :**
```
Archivo Black + Space Grotesk (400;500;600;700) + JetBrains Mono (400;500;700) + Instrument Serif (italic)
```

**Thème Editorial :**
```
Fraunces (variable opsz/wght) + DM Sans (400;500;600;700) + JetBrains Mono (500;600;700)
```

Actuellement `base.html.twig` ne charge que Playfair Display + Inter (ancien design). À mettre à jour lors de la migration des pages.

---

## Tokens manquants à surveiller
- `--color-yellow` (#f0c040) ajouté manuellement dans `base.html.twig` pour le lien admin sidebar — pas dans le prototype original, à confirmer avec la team.

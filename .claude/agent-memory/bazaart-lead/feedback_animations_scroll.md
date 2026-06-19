---
name: feedback-animations-scroll
description: Préférences d'animation de Gaëlle (scroll-linked réversible) + ne pas sur-interpréter une demande de retrait
metadata:
  type: feedback
---

Les animations voulues par Gaëlle sont **pilotées par le scroll et RÉVERSIBLES** (elles se jouent en descendant ET se rejouent à l'envers en remontant), pas des apparitions « une seule fois » à l'entrée dans le viewport.

**Why:** Référence donnée = composant React `ScrollExpandMedia` (framer-motion) où la progression 0->1 est liée à la position de scroll (mots du titre qui s'écartent + fond qui s'estompe). J'ai d'abord livré un reveal-once via IntersectionObserver/unobserve -> recadré ("je veux cette animation sur tout le site en scroll up and down"). Stack = Symfony/Twig -> on REPRODUIT l'effet en vanilla JS (jamais React/framer-motion, cf. [[reference-design-prototype]]).

**How to apply:**
- Pour toute animation de titres/sections : progression continue dérivée de la position de l'élément dans le viewport, transform+opacity uniquement, rAF + listener passif, réversible, progressive enhancement (titres visibles si JS off), `prefers-reduced-motion` respecté, pas de scroll horizontal.
- Quand Gaëlle demande de RETIRER un élément (ex. « retirer la vidéo du hero »), ne retire QUE ça : ne supprime pas l'animation/le comportement autour. J'ai retiré l'animation du hero en plus de la vidéo -> erreur. Périmètre minimal, comme [[feedback-no-em-dash]] / sa règle « jamais de modif hors-périmètre ».

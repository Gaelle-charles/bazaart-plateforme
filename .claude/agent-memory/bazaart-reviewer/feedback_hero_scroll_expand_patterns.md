---
name: feedback_hero_scroll_expand_patterns
description: Hero home + title-reveal site-wide (juin 2026) — patterns et anti-patterns JS/CSS/Twig identifiés
metadata:
  type: feedback
---

## Commit 4a71174 — hero scroll-expand (relecture 1)

**Patterns solides :**
- rAF + passive:true + lastY guard → zéro reflow
- prefers-reduced-motion : CSS (état final) + JS (return immédiat)
- Guard DOM, Turbo cleanup (turbo:before-visit), vidéo poster

**Résiduels signalés :**
- lp-hero__meta padding-top:80px peut chevaucher navbar si hauteur > 80px (corrigé commit 2f2c719 : JS mesure la hauteur réelle de la navbar → --hero-meta-top)
- `<video>` présentation sans poster=

---

## Commit 2f2c719 — title-reveal site-wide + hero photo fixe (relecture 2)

### Patterns solides confirmés
- Progressive enhancement en 2 temps : `js-title-reveal-ready` posée AVANT armement des éléments → sans JS, aucune règle CSS d'opacité/transform ne s'active.
- prefers-reduced-motion : géré CÔTÉ JS (return immédiat avant toute classe) ET côté CSS (état final forcé). Double filet solide.
- IntersectionObserver sans scroll listener, unobserve après déclenchement → pas de fuite.
- IIFE + `'use strict'` + guard `!window.IntersectionObserver` : portée propre.
- `armSimple` utilise DocumentFragment (pas de innerHTML += en boucle) → pas de reflow.
- AMPLITUDE 28px + `.tr-word-wrap { overflow:hidden }` → translateX contenu dans le wrapper, pas de scroll horizontal.
- `.lp-hub__name` avec `<br>` correctement traité en variante complexe (fade) car `el.querySelector('*') !== null`.
- Hero : `data-no-reveal="true"` sur le H1 + exclusion de `.lp-hero__sticky` dans la liste SELECTORS → pas de double-armement.
- `base_admin.html.twig` sans id `main-content` → le `container` restera null → `init()` sort proprement à l'étape B.
- JS hero : calcul réel de la hauteur navbar via `nav.offsetHeight` → --hero-meta-top corrige le problème du padding fixe 80px.
- Double rAF avant `.is-loaded` → garantit que le navigateur a peint l'état armé avant la transition (contournement Safari/Chrome).

### Avertissements

1. **title-reveal.js : chargé avec `defer` dans le bloc `{% block javascripts %}`** (base.html.twig ligne 859).
   Les scripts `defer` s'exécutent dans l'ordre du DOM, juste avant que `DOMContentLoaded` soit déclenché.
   Le script hero (inline) enregistre un listener sur `DOMContentLoaded`. Si le parser HTML arrive au script `defer` title-reveal.js AVANT que DOMContentLoaded soit émis, il sera mis en file `defer` et s'exécutera avant l'événement. Le script inline hero écoute aussi DOMContentLoaded. L'ordre réel dans le rendu HTML est : `<script src defer>` (title-reveal) puis `<script>` (nav hamburger + cookie) puis, via `{{ parent() }}` du template enfant, le script hero inline. Les scripts defer sont garantis s'exécuter AVANT DOMContentLoaded mais APRES le parsing. Les scripts inline en bas de body s'exécutent synchroniquement lors du parsing. Résultat : le script hero inline du template enfant s'exécutera APRÈS title-reveal.js (defer). Pas de bug, mais l'ordre n'est pas celui décrit dans le commentaire (qui dit "AVANT les scripts de la page").

2. **`lp-saas__h2` avec des `<br>` internes** : le H2 "Tout ce dont / tu as besoin / sans le bruit." contient des `<br>`. Il sera traité en variante `fade` (correct). Mais `<br>` comme enfant de `isSimpleTitle` — `el.querySelector('*')` retourne le `<br>` → `hasChildElements = true` → fade. Comportement correct.

3. **Commentaire trompeur ligne 37 du commentaire JS** : "Exclut explicitement le H1 hero (animé par le JS scroll-expand de index.html.twig)". La logique scroll-expand a été supprimée dans ce même commit. Le commentaire devrait dire "animé par le JS hero de index.html.twig". Mineur, cosmétique.

4. **URL en dur `/images/hero-bazaart.jpg`** dans le CSS inline de index.html.twig (`.lp-hero__bg`). Le fichier est bien présent dans `public/images/` mais l'URL n'utilise pas `asset()` (impossible depuis un CSS dans `<style>`). Ce pattern existait déjà — pas une régression.

5. **`<script defer>` dans un bloc `{% block javascripts %}`** : quand un template enfant fait `{{ parent() }}`, il hérite correctement du `<script src defer>`. Mais si un template enfant OUBLIE le `{{ parent() }}` dans son bloc javascripts, title-reveal.js ne sera pas chargé. Pattern fragile à documenter.

### Suggestions

1. Ajouter un paramètre de version au CSS title-reveal.css (ex. `?v=20260620`) si Nginx est configuré avec `expires max` en prod — le commentaire dans base.html.twig le note mais ne l'a pas encore fait.

2. Le `margin-top: 64px` de `.lp-footer` est global (toutes pages). Sur la home, la dernière section (hubs) a déjà un `padding-bottom`. Le commentaire le documente (112px total = acceptable). Pas de regression.

3. `--container-max` vaut 1440px dans `:root` mais 1360px dans `[data-theme="editorial"]` (design-tokens.css). Le footer utilise `var(--container-max)` → sur les pages editorial (si elles existent) le footer sera plus étroit (1360px) que sur les pages Street (1440px). Cohérence à surveiller.

**Why:** Pattern title-reveal site-wide = première animation cross-pages sur le projet. La logique d'armement en 2 temps (classe HTML → CSS → JS) est le motif clé à ne pas casser lors des futures extensions de la liste SELECTORS.

**How to apply:** À chaque nouveau titre animé, vérifier : (1) la classe est dans SELECTORS, (2) elle n'est PAS dans un contexte admin (title-reveal.js se protège seul via container=getElementById('main-content')), (3) si le titre a du markup interne il sera en fade (correct), (4) si la page enfant surcharge {% block javascripts %} sans {{ parent() }}, title-reveal.js sera perdu.

---

## Commit 6001737 — hero RÉTABLI scroll-driven réversible, sans vidéo (relecture 3)

### Patterns solides confirmés

- **Progressive enhancement hero :** `js-hero-scroll-active` posée sur `<html>` AVANT que les CTAs soient armasés en opacity:0. Sans JS, `.js-hero-scroll-active .lp-hero__ctas-reveal` n'existe pas → CTAs visibles par défaut. Solide.
- **Progressive enhancement title-reveal :** `js-title-reveal-ready` même logique. Aucune règle CSS d'armement ne s'active sans JS. Solide.
- **prefers-reduced-motion :** triple couverture — JS hero sort immédiatement, JS title-reveal sort immédiatement, CSS `!important` force les états finaux pour les deux composants. Solide.
- **Scroll horizontal :** `.lp-hero__title-row { overflow:hidden }` + `.tr-word-wrap { overflow:hidden }` + `.lp-hero__sticky { overflow:hidden }` à trois niveaux. Amplitudes 28px (title-reveal) et 70-160px (hero) bornées et contenues. OK.
- **Réversibilité :** les deux JS calculent un `progress` à chaque frame de scroll et appliquent les valeurs directement en inline style. Aucun état mémorisé autre que `lastY`. La remontée donne bien `progress` décroissant → rejoue à l'envers. Mécanique correcte.
- **Un seul listener scroll + rAF pour title-reveal :** `elements[]` + `rafId` partagés entre tous les titres de la page. Pas de listener par titre.
- **Turbo cleanup :** les deux IIFE nettoient scroll+resize listeners + annulent le rAF en cours + retirent leurs classes `<html>` sur `turbo:before-visit`. Pas de fuite.
- **Guard DOM hero :** si `!section || !bg || !titleLeft || !titleRight`, sortie propre avant tout armement.
- **Nettoyage des éléments Turbo title-reveal :** `elements = []` dans le handler `turbo:before-visit`. Aucun vieux sélecteur ne survit à une navigation.
- **`data-no-reveal="true"` sur le H1 hero** → exclut correctement le H1 du mecanism générique.
- **Variante simple vs complexe :** le H2 `.lp-saas__h2` avec `<br>` → `isSimpleTitle` retourne false car `el.querySelector('*')` trouve les `<br>` → variante fade. Correct.

### Avertissements

1. **CTAs héro invisibles pour les visiteurs qui ne scrollent pas (UX/accessibilité)**
   `CTA_FADE_START = 0.75` : les CTAs n'apparaissent qu'à 75% de la piste de scroll (150vh / 180vh mobile). Un visiteur qui arrive, lit le H1, décide d'agir immédiatement mais ne scrolle pas assez loin ne voit jamais les deux boutons ("Explorer les opportunités", "Rejoindre gratuitement").
   Sans JS ou prefers-reduced-motion, les CTAs sont visibles. Mais avec JS actif et scroll inférieur à ~150vh, le visiteur est bloqué.
   Signalé comme question ouverte à Gaëlle dans le commit. Risque UX réel sur mobile où le scroll d'1,5 écran est moins instinctif.

2. **Cas "page sans scroll possible" : état initial des CTAs**
   Si la page est affichée dans une fenêtre dont la hauteur est supérieure ou égale à la hauteur totale de la page (fenêtre très haute, ou navigateur dézoomé), `trackLength <= 0` → le hero JS sort (`if (trackLength <= 0) { return; }`). Dans ce cas, `js-hero-scroll-active` a DÉJÀ été posée sur `<html>` → les CTAs sont en opacity:0 et pointer-events:none définitivement, sans jamais être atteints via scroll.
   La règle CSS `@media (prefers-reduced-motion)` ne couvre pas ce cas (elle conditionne au media query, pas à l'état de scroll). Ce scénario est rare (fenêtre très grande) mais constitue un piège d'accessibilité.
   Correction possible : ajouter un guard avant de poser `js-hero-scroll-active` :
   ```js
   var rect = section.getBoundingClientRect();
   var sectionTop = rect.top + (window.scrollY || 0);
   var trackLength = section.offsetHeight - window.innerHeight;
   if (trackLength <= 0) { return; } // pas d'armement si pas de scroll possible
   document.documentElement.classList.add('js-hero-scroll-active');
   ```

3. **Layout thrash potentiel au premier `update()` du hero**
   `update()` appelle `section.getBoundingClientRect()` à chaque frame. Sur la page d'accueil, la section hero est l'élément #1 du DOM et sa position est stable après le premier rendu. Pas de problème pratique. Mais si une navbar sticky non-fixed avait du JS qui modifiait son height après le premier paint, `sectionTop` pourrait être calculé trop tôt. Ce risque est actuellement nul (la navbar est fixed et son offsetHeight est mesuré une seule fois dans `setNavHeight()`).

4. **`getBoundingClientRect()` en boucle dans `updateAll()` du title-reveal**
   `calcProgress()` appelle `el.getBoundingClientRect()` pour CHAQUE titre à CHAQUE frame scroll. Avec la liste SELECTORS actuelle (~15 sélecteurs, potentiellement 10-20 éléments visibles selon la page), cela représente 10-20 `getBoundingClientRect()` par frame. Cette API force un "reflow forcé" si le layout a été invalidé par la frame précédente. Avec `transform` et `opacity` en inline style, le layout n'est PAS invalidé (ces propriétés ne causent pas de reflow). Donc le `getBoundingClientRect()` du tour suivant lira la position de référence correcte sans forcer un reflow. OK en pratique, mais fragile : si un futur développeur introduit une propriété CSS qui cause un reflow dans `applyState()`, la boucle deviendrait un thrash à ~15 appels/frame.

5. **Incohérence `preload="auto"` dans le commentaire vs `preload="metadata"` dans le HTML**
   La balise `<video>` à la ligne 1911 a `preload="metadata"`. Le commentaire interne (lignes 1906-1909) dit "preload=\"auto\" : pré-charge les données pour un démarrage immédiat". Mais le code dit `preload="metadata"`. Le commentaire juste après (lignes 1927-1935) documente la raison du choix "metadata". Il y a donc un commentaire contradictoire qui subsiste.

### Suggestions

1. **Rendre les CTAs accessibles plus tôt dans le scroll** : abaisser `CTA_FADE_START` de 0.75 à 0.45-0.5 permettrait aux visiteurs de voir les CTAs après ~la moitié de la piste de scroll (environ 1 écran), ce qui est plus naturel.

2. **Ajouter le guard `trackLength <= 0` avant le `classList.add('js-hero-scroll-active')`** (voir avertissement 2 ci-dessus). Coût nul, fiabilité améliorée.

3. **Corriger le commentaire `preload="auto"` contradictoire** (ligne 1906) : il devrait lire `preload="metadata"` pour être cohérent avec le code réel.

4. **`lp-hero__title-row { overflow:hidden }` et `lp-hero__sticky { overflow:hidden }` en double** : les deux conteneurs clipent le titre au scroll. Si le titre sort latéralement au-delà du `.lp-hero__sticky`, le `.lp-hero__title-row` n'aide pas (il est à l'intérieur). C'est `overflow:hidden` sur `.lp-hero__sticky` qui fait réellement le travail. Le `.lp-hero__title-row` est une sécurité supplémentaire utile pour le mobile où la hauteur de ligne peut varier. Pas un bug, mais à surveiller si le HTML évolue.

**Why:** Relecture 3 — version réversible scroll-driven sans vidéo. Le pattern CTAs invisibles à progress < 75% est le risque UX principal documenté. La mécanique de réversibilité elle-même est correcte.

**How to apply:** À chaque modification du `CTA_FADE_START` ou de la durée de la section hero (200vh/180vh), re-vérifier que (a) les CTAs apparaissent dans un délai raisonnable pour un visiteur mobile, et (b) le guard `trackLength <= 0` est en place avant le `classList.add('js-hero-scroll-active')`.

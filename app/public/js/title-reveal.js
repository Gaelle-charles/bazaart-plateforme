/**
 * title-reveal.js — Animation d'apparition des titres (site-wide)
 * ================================================================
 * Vanilla JS pur, zero dépendance, zero framework.
 * Chargé via base.html.twig => actif sur toutes les pages publiques.
 *
 * EFFET VISUEL
 * ------------
 * Quand un titre entre dans le viewport :
 *   - Variante SIMPLE (texte brut, peu de mots, pas de <br>) :
 *     Le 1er mot glisse depuis la gauche, le reste depuis la droite,
 *     tout en fondu. Reproduit le ressenti du hero scroll-expand.
 *   - Variante COMPLEXE (titre long, multi-lignes, markup interne) :
 *     Simple fondu + léger glissement vertical (plus robuste car ne
 *     touche pas aux nœuds enfants du titre).
 *
 * PROGRESSIVE ENHANCEMENT
 * -----------------------
 * 1. Le JS ajoute .js-title-reveal-ready sur <html> EN PREMIER.
 * 2. Seulement ensuite, il arme les titres (transform + opacity 0).
 * Si le JS ne s'exécute pas (JS désactivé, erreur réseau, vieux
 * navigateur sans IntersectionObserver) => aucune classe armée =>
 * les titres restent visibles normalement (CSS n'a rien armé).
 *
 * PERFORMANCE
 * -----------
 * - IntersectionObserver : zero scroll listener, zero rAF superflu.
 * - transform + opacity uniquement (zero reflow, GPU composited).
 * - observer.unobserve() après déclenchement : chaque titre n'est
 *   animé qu'une seule fois, l'observer est libéré ensuite.
 * - will-change posé uniquement sur les éléments qui vont bouger.
 *
 * PÉRIMÈTRE
 * ---------
 * Ne touche qu'aux éléments dans main / #main-content.
 * Exclut explicitement le H1 du hero (animé par le JS scroll-expand
 * de index.html.twig) pour éviter un double-armement.
 */

(function () {
    'use strict';

    /* ── 0. GARDE-FOUS ─────────────────────────────────────────────────────
       On vérifie la présence des APIs nécessaires avant de rien faire.
       Si IntersectionObserver n'existe pas (IE11 non polyfillé), on sort
       proprement et les titres restent visibles normalement. */
    if (!window.IntersectionObserver) { return; }

    /* ── 1. ACCESSIBILITÉ : prefers-reduced-motion ─────────────────────────
       Si l'utilisateur a activé "Réduire les animations" dans son OS,
       on ne pose aucune animation. Les titres sont déjà visibles via CSS
       (la règle @media prefers-reduced-motion dans title-reveal.css gère
       l'état final). On sort immédiatement. */
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

    /* ── 2. SÉLECTEURS DES TITRES À ANIMER ────────────────────────────────
       Liste précise des classes de titres réellement utilisées dans les
       templates publics Twig (repérées par grep sur les templates).

       PÉRIMÈTRE : on limite la portée aux éléments dans main / #main-content
       (voir l'appel querySelectorAll plus bas).

       EXCLUSION EXPLICITE du H1 hero : il est dans .lp-hero__sticky et
       animé par le JS scroll-expand de index.html.twig. L'attribut
       data-no-reveal="true" posé sur ce H1 permet de l'exclure sans
       avoir à connaître sa classe exacte ici.

       Ordre de la liste : du plus "grand" titre vers les plus petits,
       pour une meilleure lisibilité de la configuration. */
    var SELECTORS = [
        /* === PAGE D'ACCUEIL (vitrine/index.html.twig) === */
        '.lp-section-header__title',   /* titres de sections (h2) : 01, 02, 03, 04 */
        '.lp-saas__h2',                /* "Tout ce dont tu as besoin, sans le bruit." */
        '.lp-hub__name',               /* noms des hubs : ÉVÉNEMENT, INGÉNIERIE, FORMATION */

        /* === RESSOURCERIE (resource/index.html.twig, show, alerts, my, favorites) === */
        '.opp-title',                  /* "CE QUI EST OUVERT." — h1 page catalogue */
        '.resource-hero__title',       /* titre de la ressource sur la page détail */
        '.myresources-title',          /* "Mes soumissions" */
        '.alerts-title',               /* "Alertes ressources" */
        '.fav-title',                  /* "Mes favoris" */

        /* === BLOG (article/index, show, form, my) === */
        '.art-header__title',          /* titre du catalogue blog */
        '.art-identity__title',        /* titre d'un article en page détail */
        '.art-form-header__title',     /* "Nouveau / Modifier un article" */
        '.art-my-header__title',       /* "Mes articles" */

        /* === FORUM (forum/index, thread, category, new_thread) === */
        '.fi-title',                   /* "ON SE PARLE." — h1 page forum */
        '.ft-post__title',             /* titre du thread sur la page détail */
        '.fn-page-title',              /* "Nouveau sujet" */

        /* === FORMATIONS (course/index, show, proposal_new) === */
        '.cf-hero__title',             /* titre du catalogue formations */
        '.cf-catalogue-title',         /* "Catalogue" */
        '.cf-show-hero__title',        /* titre d'une formation en page détail */
        '.cp-title'                    /* "Proposer une formation" */
    ].join(', ');

    /* ── 3. DÉTECTION VARIANTE SIMPLE vs COMPLEXE ──────────────────────────
       Un titre est "simple" si :
       - Il n'a PAS d'élément enfant de type balise (span, br, strong, em…).
         (des nœuds TEXT seulement sont OK — c'est du texte brut).
       - Son texte brut fait 6 mots ou moins (seuil arbitraire raisonnable).
         Au-delà, le découpage mot-par-mot risque d'être trop agité visuellement.

       Tous les autres titres (multi-lignes, avec markup interne, longs) =>
       variante COMPLEXE (fondu + glissement vertical).

       @param {Element} el - L'élément titre à examiner.
       @returns {boolean} true = variante simple, false = variante complexe. */
    function isSimpleTitle(el) {
        /* Vérifie la présence d'éléments enfants HTML (PAS les nœuds texte) */
        var hasChildElements = el.querySelector('*') !== null;
        if (hasChildElements) { return false; } /* markup interne => complexe */

        /* Compte les mots (split sur les espaces blancs, filtre les chaînes vides) */
        var wordCount = (el.textContent || '').trim().split(/\s+/).filter(Boolean).length;
        return wordCount <= 6;
    }

    /* ── 4. ARMEMENT VARIANTE SIMPLE (découpage en mots) ───────────────────
       On remplace le contenu textuel du titre par une suite de :
         <span class="tr-word-wrap">
           <span class="tr-word" style="--tr-dir-x: Xpx; --tr-delay: Xs;">mot</span>
         </span>

       Le 1er mot a un translateX négatif (vient de la gauche).
       Les suivants ont un translateX positif (viennent de la droite).
       Chaque mot a un délai légèrement croissant pour la cascade.

       L'amplitude est de 28px max (raisonnable, évite le scroll horizontal
       même sans overflow:hidden sur le parent).

       @param {Element} el - L'élément titre à armer. */
    function armSimple(el) {
        var text  = (el.textContent || '').trim();
        var words = text.split(/\s+/).filter(Boolean);

        /* Amplitude de déplacement horizontal (en pixels).
           Valeur modérée : suffisamment visible pour le ressenti "écartement",
           mais pas au point de créer un scroll horizontal même sur mobile. */
        var AMPLITUDE = 28;

        /* Délai de base entre chaque mot (secondes).
           0.06s = 60ms → cascade naturelle sans être trop lente. */
        var DELAY_STEP = 0.06;

        /* Construction du HTML de remplacement.
           On n'utilise pas innerHTML += en boucle (provoque des reflows).
           On construit une DocumentFragment à la place. */
        var fragment = document.createDocumentFragment();

        words.forEach(function (word, index) {
            /* Wrapper : masque le débordement pendant l'animation */
            var wrap = document.createElement('span');
            wrap.className = 'tr-word-wrap';

            /* Élément animé */
            var span = document.createElement('span');
            span.className = 'tr-word';
            /* Le 1er mot glisse depuis la gauche (translateX négatif),
               les autres depuis la droite (translateX positif). */
            var dirX = index === 0 ? -AMPLITUDE : AMPLITUDE;
            /* --tr-dir-x : position de départ (armement CSS)
               --tr-delay : délai de cascade (chaque mot décalé de DELAY_STEP) */
            span.style.setProperty('--tr-dir-x', dirX + 'px');
            span.style.setProperty('--tr-delay', (index * DELAY_STEP) + 's');
            span.textContent = word;

            wrap.appendChild(span);
            fragment.appendChild(wrap);

            /* On n'ajoute PAS d'espace entre les .tr-word-wrap :
               le CSS (margin-left: 0.28em sur + .tr-word-wrap) gère l'espacement. */
        });

        /* Vide le titre et insère les spans */
        el.textContent = '';
        el.appendChild(fragment);

        /* Stocke une référence aux spans pour le déclenchement via IntersectionObserver */
        el._trWords = el.querySelectorAll('.tr-word');
    }

    /* ── 5. ARMEMENT VARIANTE COMPLEXE (fondu + glissement vertical) ────────
       On ajoute simplement la classe .tr-fade sur le titre.
       Pas de modification du DOM interne => le markup HTML est préservé intact.
       Le CSS gère translateY + opacity via la classe.

       @param {Element} el - L'élément titre à armer. */
    function armFade(el) {
        el.classList.add('tr-fade');
    }

    /* ── 6. DÉCLENCHEMENT D'UN TITRE ───────────────────────────────────────
       Appelé par l'IntersectionObserver quand le titre entre dans le viewport.
       On ajoute .is-revealed sur chaque mot (variante simple) ou sur le titre
       lui-même (variante complexe), ce qui déclenche la transition CSS.

       @param {Element} el - L'élément titre à révéler.
       @param {IntersectionObserver} observer - L'observer à détacher après. */
    function reveal(el, observer) {
        if (el._trWords) {
            /* Variante simple : révèle chaque mot */
            el._trWords.forEach(function (word) {
                word.classList.add('is-revealed');
            });
        } else {
            /* Variante complexe : révèle le titre entier */
            el.classList.add('is-revealed');
        }

        /* PERFORMANCE : unobserve après déclenchement.
           L'animation ne se joue qu'une fois, l'observer n'a plus rien à faire. */
        observer.unobserve(el);
    }

    /* ── 7. POINT D'ENTRÉE PRINCIPAL ───────────────────────────────────────
       Appelé une seule fois au DOMContentLoaded.
       Toute la logique d'initialisation est ici. */
    function init() {

        /* ÉTAPE A : signaler au CSS que le JS est prêt.
           La classe .js-title-reveal-ready sur <html> ACTIVE les règles CSS
           d'armement (opacity:0 + transform sur .tr-word et .tr-fade).
           Si on ne la pose pas, les titres restent visibles normalement.
           IMPORTANT : on la pose AVANT d'armer les titres pour éviter
           que le CSS arme des éléments avant que le JS n'ait fini. */
        document.documentElement.classList.add('js-title-reveal-ready');

        /* ÉTAPE B : trouver tous les titres dans le contenu principal.
           On limite à main / #main-content pour éviter de toucher la nav,
           le footer, la bannière cookies, etc.
           On essaie #main-content d'abord (id utilisé dans base_app et vitrine),
           puis main comme fallback. */
        var container = document.getElementById('main-content') || document.querySelector('main');
        if (!container) {
            /* Pas de conteneur principal => on ne fait rien */
            return;
        }

        /* Collecte des titres correspondant aux sélecteurs définis en ÉTAPE 1.
           querySelectorAll retourne une NodeList (pas un Array) → on la convertit. */
        var rawElements = container.querySelectorAll(SELECTORS);
        if (!rawElements || rawElements.length === 0) { return; }

        /* Conversion NodeList → Array pour pouvoir utiliser filter/forEach */
        var elements = Array.prototype.slice.call(rawElements);

        /* ÉTAPE C : filtrer les exclusions.
           - data-no-reveal="true" : marqueur posé sur les titres à exclure
             (ex : le H1 du hero, animé par son propre JS). */
        elements = elements.filter(function (el) {
            return el.getAttribute('data-no-reveal') !== 'true';
        });

        if (elements.length === 0) { return; }

        /* ÉTAPE D : créer l'IntersectionObserver.
           threshold: 0.15 = le titre doit être visible à 15% avant de se déclencher.
           Evite les déclenchements prématurés sur les titres presque hors-écran.
           rootMargin: '0px 0px -40px 0px' = déclenche légèrement avant d'atteindre
           le bas du viewport (meilleur ressenti visuel). */
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    reveal(entry.target, observer);
                }
            });
        }, {
            threshold:  0.15,
            rootMargin: '0px 0px -40px 0px'
        });

        /* ÉTAPE E : armer chaque titre et l'observer. */
        elements.forEach(function (el) {
            if (isSimpleTitle(el)) {
                armSimple(el);  /* découpe en mots + translateX */
            } else {
                armFade(el);    /* fondu + translateY */
            }
            observer.observe(el);
        });

        /* ÉTAPE F : déclenchement immédiat pour les titres déjà visibles
           à l'arrivée sur la page (above the fold ou rechargement avec ancre).
           IntersectionObserver appelle le callback de façon asynchrone même
           pour les éléments déjà dans le viewport => ils seront révélés
           automatiquement sans qu'on ait besoin de gérer ce cas manuellement. */
    }

    /* Lance l'initialisation dès que le DOM est parsé (DOMContentLoaded).
       Si le script est chargé en bas de page (après le </body>), le DOM est
       déjà prêt et DOMContentLoaded ne se déclenchera jamais.
       On vérifie donc l'état du document pour couvrir les deux cas. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        /* DOM déjà prêt (script chargé en defer ou en bas de page) */
        init();
    }

}()); /* IIFE : portée locale, zero variable globale */

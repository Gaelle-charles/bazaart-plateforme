/**
 * title-reveal.js — Animation des titres pilotée par le scroll (site-wide)
 * =========================================================================
 * Vanilla JS pur, zéro dépendance, zéro framework.
 * Chargé via base.html.twig => actif sur toutes les pages publiques.
 *
 * EFFET VISUEL
 * ------------
 * Quand un titre entre dans le viewport, les mots s'écartent et s'estompent
 * (p=0 = titre hors-écran). Quand il est à sa position de lecture, les
 * mots sont rassemblés et bien visibles (p=1). L'animation est RÉVERSIBLE :
 * en remontant la page, les mots s'écartent à nouveau.
 *
 *   - Variante SIMPLE (texte brut, peu de mots, pas de balisage interne) :
 *     Le 1er mot glisse vers la gauche, le reste vers la droite,
 *     en fonction de p. Reproduit le ressenti du hero scroll-expand.
 *   - Variante COMPLEXE (titre long, multi-lignes, markup interne) :
 *     Fondu + léger glissement vertical (plus robuste car ne touche pas
 *     aux nœuds enfants du titre).
 *
 * PROGRESSION p
 * -------------
 * Pour chaque titre, on calcule p = f(position_dans_le_viewport) :
 *   - Quand le titre approche du tiers supérieur du viewport -> p=1 (lisible)
 *   - Quand il est en bas du viewport (entrant) ou en dehors -> p=0 (écarté)
 *   - Quand il est très haut (sortant) -> p diminue progressivement
 * L'animation est continue (recalculée à chaque scroll) et réversible.
 *
 * PROGRESSIVE ENHANCEMENT
 * -----------------------
 * 1. Le JS ajoute .js-title-reveal-ready sur <html> EN PREMIER.
 * 2. Seulement ensuite, il arme les titres (transform + opacity via CSS).
 * Si le JS ne s'exécute pas => aucune classe armée => titres visibles normalement.
 *
 * PERFORMANCE
 * -----------
 * - Un SEUL listener scroll global {passive:true} + requestAnimationFrame.
 * - Recalcul sur resize (debounce léger).
 * - Nettoyage sur turbo:before-visit (pas de fuite mémoire).
 * - transform + opacity uniquement (zéro reflow, GPU composited).
 *
 * PÉRIMÈTRE
 * ---------
 * Ne touche qu'aux éléments dans main / #main-content.
 * Exclut explicitement le H1 du hero (animé par le JS scroll-expand
 * de index.html.twig) via l'attribut data-no-reveal="true".
 */

(function () {
    'use strict';

    /* ── 0. GARDE-FOUS ─────────────────────────────────────────────────────
       On vérifie la présence des APIs nécessaires avant de rien faire.
       requestAnimationFrame est disponible dans tous les navigateurs modernes. */
    if (!window.requestAnimationFrame) { return; }

    /* ── 1. ACCESSIBILITÉ : prefers-reduced-motion ─────────────────────────
       Si l'utilisateur a activé "Réduire les animations" dans son OS,
       on ne pose aucune animation. Les titres sont déjà visibles via CSS
       (la règle @media prefers-reduced-motion dans title-reveal.css
       force l'état final immédiatement). On sort immédiatement. */
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

    /* ── 2. SÉLECTEURS DES TITRES À ANIMER ────────────────────────────────
       Liste précise des classes de titres réellement utilisées dans les
       templates publics Twig (repérées par grep sur les templates).

       PÉRIMÈTRE : on limite la portée aux éléments dans main / #main-content
       (voir l'appel querySelectorAll plus bas).

       EXCLUSION EXPLICITE du H1 hero : il est animé par le JS scroll-expand
       de index.html.twig. L'attribut data-no-reveal="true" posé sur ce H1
       permet de l'exclure sans avoir à connaître sa classe exacte ici.

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
       - Il n'a PAS d'élément enfant de type balise (span, br, strong, em...).
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
           <span class="tr-word" style="--tr-dir-x: Xpx;">mot</span>
         </span>

       Le 1er mot a un translateX négatif (vers la gauche quand écarté).
       Les suivants ont un translateX positif (vers la droite quand écartés).

       NOUVEAU : plus de transition CSS ni de délais en cascade.
       Le JS applique directement transform et opacity en fonction de p.
       C'est le JS seul qui pilote l'état visuel à chaque frame.

       @param {Element} el - L'élément titre à armer. */
    function armSimple(el) {
        var text  = (el.textContent || '').trim();
        var words = text.split(/\s+/).filter(Boolean);

        /* Amplitude de déplacement horizontal (en pixels) pour chaque mot.
           Valeur modérée : suffisamment visible pour le ressenti "écartement",
           mais pas au point de créer un scroll horizontal même sur mobile.
           L'overflow:hidden sur .tr-word-wrap contient les débordements. */
        var AMPLITUDE = 28;

        /* Construction du HTML de remplacement via DocumentFragment
           (évite les reflows en boucle avec innerHTML +=). */
        var fragment = document.createDocumentFragment();

        words.forEach(function (word, index) {
            /* Wrapper : masque le débordement pendant l'animation */
            var wrap = document.createElement('span');
            wrap.className = 'tr-word-wrap';

            /* Élément animé */
            var span = document.createElement('span');
            span.className = 'tr-word';

            /* --tr-dir-x : amplitude de départ de ce mot (négatif pour le 1er,
               positif pour les suivants). Le JS multiplie cette valeur par (1-p)
               pour obtenir le déplacement courant. */
            var dirX = index === 0 ? -AMPLITUDE : AMPLITUDE;
            span.style.setProperty('--tr-dir-x', dirX + 'px');
            span.textContent = word;

            wrap.appendChild(span);
            fragment.appendChild(wrap);
        });

        /* Vide le titre et insère les spans */
        el.textContent = '';
        el.appendChild(fragment);

        /* Stocke une référence aux spans pour les mises à jour continues */
        el._trWords = el.querySelectorAll('.tr-word');
    }

    /* ── 5. ARMEMENT VARIANTE COMPLEXE (fondu + glissement vertical) ────────
       On marque simplement le titre avec une classe CSS et un attribut data
       pour que le JS puisse le piloter.
       Pas de modification du DOM interne => le markup HTML est préservé intact.

       @param {Element} el - L'élément titre à armer. */
    function armFade(el) {
        el.classList.add('tr-fade');
    }

    /* ── 6. CALCUL DE LA PROGRESSION p ────────────────────────────────────
       Pour un élément donné, calcule p dans [0..1] selon sa position
       dans le viewport.

       Convention :
         p = 1  => titre à sa position de lecture (visible, mots rassemblés)
         p = 0  => titre hors-écran ou en bas du viewport (écarté, invisible)

       FENÊTRE DE LISIBILITÉ :
       Le titre est à p=1 quand son centre est dans la zone "tiers supérieur"
       du viewport (entre 15% et 65% du bas). En dehors de cette zone,
       p décroît progressivement vers 0.

       PARAMÈTRES DE LA FENÊTRE (en fraction du viewport height) :
         START_FROM_BOTTOM : quand le bord bas du titre dépasse ce seuil
                             en entrant depuis le bas => p commence à monter
         FULL_READ_TOP     : quand le bord haut du titre est en dessous de
                             ce seuil depuis le haut => p=1 (pleinement lisible)
         FADE_OUT_START    : quand le bord bas du titre remonte au-dessus de
                             ce seuil (sortie par le haut) => p commence à baisser

       Cela garantit que les titres ne sont jamais "cassés/illisibles"
       trop longtemps : ils sont p=1 dès qu'ils entrent dans la zone de lecture.

       @param {Element} el - L'élément titre.
       @returns {number}   p dans [0..1]. */
    function calcProgress(el) {
        var vh   = window.innerHeight;
        var rect = el.getBoundingClientRect();

        /* Bords du titre par rapport au viewport (positif = visible) */
        var elBottom = rect.bottom; /* bord bas du titre, en px depuis le haut du vp */
        var elTop    = rect.top;    /* bord haut du titre, en px depuis le haut du vp */

        /* ZONE ENTRÉE (depuis le bas) :
           Le titre entre dans le viewport par le bas.
           On commence l'animation quand elBottom dépasse START_FROM_BOTTOM * vh
           (c'est-à-dire quand le titre est encore assez bas pour commencer à
           "monter" visuellement sans être brutalement révélé). */
        var START_FROM_BOTTOM = 0.92; /* 92% du viewport height depuis le haut */

        /* Le titre est pleinement visible (p=1) quand son centre
           est dans le tiers supérieur-central du viewport. */
        var FULL_READ_BOTTOM = 0.60; /* bord bas en dessous de 60% du vp => p=1 */
        var FULL_READ_TOP    = 0.65; /* bord haut en dessous de 65% du vp => p=1 */

        /* ZONE SORTIE (par le haut) :
           Quand le titre commence à sortir du haut du viewport,
           p diminue progressivement. */
        var FADE_OUT_THRESHOLD = 0.15; /* titre sorti à 85% du vp par le haut => p=0 */

        /* Cas 1 : titre entièrement en dehors en bas (pas encore entré) */
        if (elBottom <= 0) { return 0; }

        /* Cas 2 : titre entièrement en dehors en haut (déjà sorti) */
        if (elTop <= -el.offsetHeight) { return 0; }

        /* Cas 3 : entrée depuis le bas
           Le titre est encore dans la moitié basse du viewport.
           p progresse de 0 à 1 au fur et à mesure qu'il monte. */
        if (elBottom > FULL_READ_BOTTOM * vh) {
            /* Progression : de (START_FROM_BOTTOM*vh) à (FULL_READ_BOTTOM*vh)
               elBottom décroît quand le titre monte => p augmente */
            var enterStart  = START_FROM_BOTTOM * vh;
            var enterEnd    = FULL_READ_BOTTOM * vh;
            if (elBottom >= enterStart) { return 0; } /* pas encore entré */
            var p = 1 - (elBottom - enterEnd) / (enterStart - enterEnd);
            return p < 0 ? 0 : (p > 1 ? 1 : p);
        }

        /* Cas 4 : titre dans la zone de pleine lisibilité */
        if (elTop >= FADE_OUT_THRESHOLD * vh) { return 1; }

        /* Cas 5 : sortie par le haut
           Le bord haut du titre remonte au-dessus de FADE_OUT_THRESHOLD*vh.
           p diminue de 1 à 0. */
        var exitStart = FULL_READ_TOP * vh;
        var exitEnd   = FADE_OUT_THRESHOLD * vh;
        if (elTop >= exitStart) { return 1; }
        var pExit = (elTop - exitEnd) / (exitStart - exitEnd);
        return pExit < 0 ? 0 : (pExit > 1 ? 1 : pExit);
    }

    /* ── 7. APPLICATION DE L'ÉTAT VISUEL ────────────────────────────────────
       Applique transform et opacity directement en inline style
       en fonction de p (calculé par calcProgress).

       Pour la variante SIMPLE : chaque mot reçoit un translateX proportionnel
       à (1-p) * amplitude du mot, et une opacity = p.
       Pour la variante COMPLEXE : translateY(18*(1-p)px) + opacity = p.

       @param {Element} el - Le titre à mettre à jour.
       @param {number}  p  - Progression dans [0..1]. */
    function applyState(el, p) {
        if (el._trWords) {
            /* Variante simple : applique sur chaque mot */
            var opacity = p;

            /* Petite fenêtre d'easing : on rend l'apparition légèrement plus douce
               en utilisant une courbe en S simple (p² * (3 - 2p) = smoothstep).
               Cela évite l'effet "brusque" au début et à la fin de la transition. */
            var smooth = p * p * (3 - 2 * p);

            for (var i = 0; i < el._trWords.length; i++) {
                var wordEl = el._trWords[i];
                /* --tr-dir-x est l'amplitude maximale posée lors de l'armement.
                   On la récupère depuis le style inline pour calculer le déplacement courant.
                   (1 - smooth) = facteur d'écartement : 0 quand lisible, 1 quand écarté */
                var dirX = parseFloat(wordEl.style.getPropertyValue('--tr-dir-x')) || 0;
                var tx   = dirX * (1 - smooth);
                wordEl.style.transform = 'translateX(' + tx + 'px)';
                wordEl.style.opacity   = smooth;
            }
        } else if (el.classList.contains('tr-fade')) {
            /* Variante complexe : glissement vertical + fondu */
            var smooth2   = p * p * (3 - 2 * p);
            var translateY = 18 * (1 - smooth2); /* 18px max de décalage vertical */
            el.style.transform = 'translateY(' + translateY + 'px)';
            el.style.opacity   = smooth2;
        }
    }

    /* ── 8. ÉTAT GLOBAL DU LISTENER ─────────────────────────────────────────
       Un seul rAF partagé pour tous les titres de la page.
       elements : liste des titres à animer (Array d'Elements).
       rafId    : handle rAF en cours (null = aucune requête en attente). */
    var elements = [];
    var rafId    = null;

    /* ── 9. BOUCLE DE MISE À JOUR ──────────────────────────────────────────
       Appelée via rAF. Recalcule p pour chaque titre et applique l'état. */
    function updateAll() {
        rafId = null; /* libère le slot pour la prochaine requête */
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            var p  = calcProgress(el);
            applyState(el, p);
        }
    }

    /* ── 10. LISTENER SCROLL (throttle via rAF) ─────────────────────────────
       Le listener scroll peut se déclencher des dizaines de fois par seconde.
       On planifie un rAF à la place : au plus une mise à jour par frame
       de rendu (60fps max), et jamais entre deux frames. */
    function onScroll() {
        if (rafId === null) {
            rafId = window.requestAnimationFrame(updateAll);
        }
    }

    /* ── 11. LISTENER RESIZE ────────────────────────────────────────────────
       Quand la fenêtre est redimensionnée, les positions relatives des titres
       changent. On recalcule immédiatement. */
    function onResize() {
        onScroll();
    }

    /* ── 12. POINT D'ENTRÉE PRINCIPAL ──────────────────────────────────────
       Appelé une seule fois au DOMContentLoaded (ou immédiatement si DOM prêt). */
    function init() {

        /* ÉTAPE A : signaler au CSS que le JS est prêt.
           La classe .js-title-reveal-ready sur <html> ACTIVE les règles CSS
           d'armement (will-change, etc.) sur .tr-word et .tr-fade.
           Si on ne la pose pas, les titres restent visibles normalement.
           IMPORTANT : on la pose AVANT d'armer les titres. */
        document.documentElement.classList.add('js-title-reveal-ready');

        /* ÉTAPE B : trouver tous les titres dans le contenu principal.
           On limite à main / #main-content pour éviter de toucher la nav,
           le footer, la bannière cookies, le back-office, etc. */
        var container = document.getElementById('main-content') || document.querySelector('main');
        if (!container) { return; }

        /* Collecte des titres correspondant aux sélecteurs définis plus haut. */
        var rawElements = container.querySelectorAll(SELECTORS);
        if (!rawElements || rawElements.length === 0) { return; }

        /* Conversion NodeList -> Array */
        var collected = Array.prototype.slice.call(rawElements);

        /* ÉTAPE C : filtrer les exclusions.
           - data-no-reveal="true" : marqueur posé sur les titres à exclure
             (ex : le H1 du hero, animé par son propre JS). */
        collected = collected.filter(function (el) {
            return el.getAttribute('data-no-reveal') !== 'true';
        });

        if (collected.length === 0) { return; }

        /* ÉTAPE D : armer chaque titre.
           L'armement prépare le DOM pour l'animation :
           - variante simple : découpe en mots + stores _trWords
           - variante complexe : ajoute la classe .tr-fade
           L'état visuel initial (p=0 ou p=1 selon la position courante)
           sera appliqué par le premier updateAll(). */
        collected.forEach(function (el) {
            if (isSimpleTitle(el)) {
                armSimple(el);
            } else {
                armFade(el);
            }
            elements.push(el);
        });

        /* ÉTAPE E : brancher les listeners et lancer la première mise à jour.
           La première mise à jour est essentielle : elle applique l'état initial
           pour les titres déjà visibles à l'arrivée sur la page (above the fold
           ou rechargement avec ancre). */
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onResize, { passive: true });
        onScroll(); /* première passe immédiate */

        /* ÉTAPE F : nettoyage Turbo.
           Si Symfony UX Turbo est actif, on retire les listeners avant chaque
           navigation pour éviter les fuites mémoire. On retire aussi la classe
           d'armement pour ne pas polluer les pages suivantes. */
        document.addEventListener('turbo:before-visit', function () {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onResize);
            if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; }
            document.documentElement.classList.remove('js-title-reveal-ready');
            /* Réinitialise le tableau pour éviter que de vieux éléments
               d'une page précédente traînent en mémoire */
            elements = [];
        }, { once: true });
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

}()); /* IIFE : portée locale, zéro variable globale */

/**
 * title-reveal.js — Animation des titres pilotée par le scroll (site-wide)
 * =========================================================================
 * Vanilla JS pur, zéro dépendance, zéro framework.
 * Chargé via base.html.twig => actif sur toutes les pages publiques.
 *
 * EFFET VISUEL — UNIFIÉ SUR LE MODÈLE DU HERO
 * --------------------------------------------
 * TOUS les titres ciblés utilisent désormais LE MÊME effet que le hero
 * de la page d'accueil (index.html.twig) :
 *
 *   - Le titre est découpé en DEUX blocs :
 *       Bloc 1 : le PREMIER MOT (translateX négatif = part vers la gauche)
 *       Bloc 2 : le RESTE DU TITRE (translateX positif = part vers la droite)
 *   - Quand le titre entre dans la zone de lecture du viewport, les deux blocs
 *     se REJOIGNENT (animation à l'envers = lisible).
 *   - Quand il en sort (par le bas en entrant, par le haut en sortant),
 *     les blocs s'ECARTENT.
 *   - L'animation est REVERSIBLE : scroll vers le bas = les blocs s'écartent
 *     à mesure que le titre sort de la zone ; remonter = ils se rejoignent.
 *
 * PARAMETRES COPIÉS DU HERO (identiques pour un ressenti uniforme)
 * ----------------------------------------------------------------
 *   AMPLITUDE_DESKTOP = 160px  (même que TITLE_SPREAD_DESKTOP du hero)
 *   AMPLITUDE_MOBILE  = 70px   (même que TITLE_SPREAD_MOBILE du hero)
 *   Interpolation : linéaire (lerp, comme le hero — pas de smoothstep)
 *
 * DÉCOUPAGE EN 2 BLOCS (pas mot-par-mot)
 * ---------------------------------------
 * Pour chaque titre, le JS :
 *   1. Extrait le TEXTE du premier nœud texte trouvé pour isoler le premier mot.
 *   2. Place le reste du contenu (y compris tout markup interne) dans le bloc 2.
 *   Cela préserve les <br>, <span>, icônes, liens, etc. dans le bloc 2.
 *
 * CAS LIMITE — TITRES INSCINDABLES (avec markup avant le premier mot)
 * -------------------------------------------------------------------
 * Si un titre commence directement par un élément HTML (pas un nœud texte),
 * on ne peut pas isoler le "premier mot" sans casser le markup.
 * Dans ce cas, on applique l'effet sur UN SEUL BLOC (le titre entier translate
 * vers la droite uniquement). Le ressenti reste cohérent avec le hero —
 * pas de fondu vertical, pas d'animation différente. Voir armTwoBlocks().
 *
 * PROGRESSION p
 * -------------
 * Pour chaque titre, p = f(position_dans_le_viewport) :
 *   p = 1  => titre dans la zone de lecture (blocs rassemblés, lisible)
 *   p = 0  => titre hors-écran ou trop loin de la zone (blocs écartés)
 * Animation continue (recalculée à chaque scroll) et réversible.
 *
 * PROGRESSIVE ENHANCEMENT
 * -----------------------
 * 1. Le JS ajoute .js-title-reveal-ready sur <html> EN PREMIER.
 * 2. Seulement ensuite, il arme les titres (DOM modifié + transform via JS).
 * Si le JS ne s'exécute pas => aucune classe armée => titres visibles normalement.
 *
 * PERFORMANCE
 * -----------
 * - Un SEUL listener scroll global {passive:true} + requestAnimationFrame.
 * - Recalcul sur resize (debounce léger via rAF).
 * - Nettoyage sur turbo:before-visit (pas de fuite mémoire).
 * - transform + opacity uniquement (zéro reflow, GPU composited).
 *
 * PREVENTION DU SCROLL HORIZONTAL
 * --------------------------------
 * Chaque bloc est enveloppé dans un .tr-block-wrap (overflow:hidden).
 * L'amplitude est bornée par le CSS (voir title-reveal.css) et par la
 * logique mobile qui réduit l'amplitude à 70px (même que le hero mobile).
 *
 * PERIMETRE
 * ---------
 * Ne touche qu'aux éléments dans main / #main-content.
 * Exclut explicitement le H1 du hero (data-no-reveal="true") qui a sa propre
 * animation dans index.html.twig.
 */

(function () {
    'use strict';

    /* ── 0. GARDE-FOUS ─────────────────────────────────────────────────────
       On vérifie la présence des APIs nécessaires avant de rien faire.
       requestAnimationFrame est disponible dans tous les navigateurs modernes. */
    if (!window.requestAnimationFrame) { return; }

    /* ── 1. ACCESSIBILITÉ : prefers-reduced-motion ─────────────────────────
       Si l'utilisateur a activé "Réduire les animations" dans son OS,
       on ne pose aucune animation. Les titres restent dans leur état HTML
       naturel. Le CSS @media prefers-reduced-motion dans title-reveal.css
       force la visibilité complète au cas où .js-title-reveal-ready aurait
       quand même été posée. */
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

    /* ── 3. PARAMÈTRES D'ANIMATION — COPIÉS DU HERO ───────────────────────
       Ces valeurs sont IDENTIQUES à celles du JS scroll-expand du hero
       (index.html.twig) pour garantir un ressenti visuel uniforme.

       TITLE_SPREAD_DESKTOP : amplitude de déplacement horizontal en px
         => même valeur que TITLE_SPREAD_DESKTOP dans le hero (160px).
         Bloc 1 (premier mot) translateX(-160px), Bloc 2 translateX(+160px)
         quand p=0.

       TITLE_SPREAD_MOBILE : amplitude réduite sur mobile
         => même valeur que TITLE_SPREAD_MOBILE dans le hero (70px).

       Seuil mobile : 768px (identique au hero). */
    var TITLE_SPREAD_DESKTOP = 160; /* pixels, desktop — copié du hero */
    var TITLE_SPREAD_MOBILE  = 70;  /* pixels, mobile  — copié du hero */
    var MOBILE_BREAKPOINT    = 768; /* px — même breakpoint que le hero */

    /* isMobile est mis à jour à chaque resize */
    var isMobile = window.innerWidth <= MOBILE_BREAKPOINT;

    /* ── 4. UTILITAIRES ────────────────────────────────────────────────────

       lerp(a, b, t) : interpolation linéaire entre a et b pour t dans [0,1].
       MÊME fonction que dans le hero — on n'utilise PAS smoothstep ici
       pour coller au ressenti du hero (interpolation linéaire pure). */
    function lerp(a, b, t) {
        return a + (b - a) * t;
    }

    /* clamp(val, min, max) : borne une valeur entre min et max. */
    function clamp(val, min, max) {
        return val < min ? min : (val > max ? max : val);
    }

    /* ── 5. ARMEMENT : découpage en 2 BLOCS (modèle hero) ─────────────────
       Cette fonction remplace le contenu du titre par DEUX blocs :
         <span class="tr-block-wrap tr-block-wrap--left">
           <span class="tr-block" data-tr-side="-1">PREMIER MOT</span>
         </span>
         <span class="tr-block-wrap tr-block-wrap--right">
           <span class="tr-block" data-tr-side="1">reste du titre...</span>
         </span>

       Pour le bloc 1 : data-tr-side="-1" => translateX négatif (vers la gauche)
       Pour le bloc 2 : data-tr-side="1"  => translateX positif (vers la droite)
       => IDENTIQUE au comportement de .lp-hero__title-left / .lp-hero__title-right

       STRATÉGIE DE DÉCOUPAGE :
       - On cherche le premier nœud texte direct du titre.
       - On isole le premier mot de ce nœud texte dans le bloc gauche.
       - Le reste de ce nœud texte + tous les autres nœuds enfants (markup)
         vont dans le bloc droit.
       - Si le titre ne commence pas par un nœud texte (markup en premier),
         on tombe sur le cas limite "un seul bloc" (voir plus bas).

       POURQUOI CETTE APPROCHE plutôt que innerHTML ?
       - innerHTML.split(' ') casserait le markup (les spans, les br, etc.)
       - Travailler sur les nœuds DOM préserve toute la structure HTML.

       @param {Element} el - L'élément titre à armer. */
    function armTwoBlocks(el) {

        /* ── Collecte de tous les nœuds enfants actuels ─────────────────── */
        /* On crée un tableau (snapshot) car on va modifier el pendant la boucle. */
        var childNodes = Array.prototype.slice.call(el.childNodes);

        /* ── Recherche du premier nœud texte non vide ───────────────────── */
        /* Un nœud texte (nodeType === 3) peut être du whitespace pur (sauts
           de ligne, espaces d'indentation Twig). On cherche le premier avec
           du contenu réel. */
        var firstTextNode  = null;
        var firstTextIndex = -1;

        for (var i = 0; i < childNodes.length; i++) {
            var node = childNodes[i];
            if (node.nodeType === 3 && node.nodeValue.trim().length > 0) {
                firstTextNode  = node;
                firstTextIndex = i;
                break;
            }
        }

        /* ── CAS LIMITE : pas de nœud texte en premier ───────────────────
           Si le titre commence par un élément HTML (par exemple une icône,
           un <span> coloré, un <br>) avant tout texte, on ne peut pas
           isoler le "premier mot" sans casser le markup.
           SOLUTION : on enveloppe TOUT le contenu dans un SEUL bloc droit
           (translateX positif, comme le bloc "reste" du hero). L'effet reste
           cohérent visuellement avec le hero — pas de fondu vertical. */
        if (firstTextNode === null) {
            armSingleBlock(el, childNodes);
            return;
        }

        /* ── Extraction du premier mot dans le nœud texte ────────────────
           On coupe le texte du nœud sur le premier espace blanc.
           Exemple : "  CE QUI EST OUVERT." -> firstWord="CE", restText=" QUI EST OUVERT."
           On prend en compte les espaces de début (trim avant split). */
        var rawText   = firstTextNode.nodeValue;
        /* On repère la position du premier espace APRÈS le premier mot.
           leading spaces sont conservés dans rawText mais ignorés pour le split. */
        var trimmed   = rawText.trimStart ? rawText.trimStart() : rawText.replace(/^\s+/, '');
        /* leadingWhitespace = l'espace de début (indentation Twig, etc.) */
        var leadingWS = rawText.slice(0, rawText.length - trimmed.length);

        /* Découpe sur le premier espace blanc dans trimmed */
        var spaceIdx  = trimmed.search(/\s/);

        var firstWord, restOfText;
        if (spaceIdx === -1) {
            /* Cas : le nœud texte ne contient qu'un seul mot (pas d'espace).
               Le premier mot = tout le nœud texte.
               Le "reste" sera constitué des nœuds suivants (s'ils existent). */
            firstWord  = trimmed;
            restOfText = '';
        } else {
            firstWord  = trimmed.slice(0, spaceIdx);
            /* On conserve l'espace lui-même dans restOfText pour ne pas
               coller les mots au début du bloc droit. */
            restOfText = trimmed.slice(spaceIdx);
        }

        /* ── Construction des deux blocs ─────────────────────────────────

           BLOC GAUCHE : premier mot uniquement.
           data-tr-side="-1" => le JS lui appliquera translateX(-amplitude) quand p=0. */
        var wrapLeft  = document.createElement('span');
        wrapLeft.className = 'tr-block-wrap tr-block-wrap--left';

        var spanLeft  = document.createElement('span');
        spanLeft.className = 'tr-block';
        spanLeft.setAttribute('data-tr-side', '-1'); /* direction gauche */
        spanLeft.textContent = firstWord;

        wrapLeft.appendChild(spanLeft);

        /* BLOC DROIT : reste du nœud texte + tous les nœuds suivants.
           data-tr-side="1" => translateX(+amplitude) quand p=0. */
        var wrapRight = document.createElement('span');
        wrapRight.className = 'tr-block-wrap tr-block-wrap--right';

        var spanRight = document.createElement('span');
        spanRight.className = 'tr-block';
        spanRight.setAttribute('data-tr-side', '1'); /* direction droite */

        /* On insère d'abord le texte résiduel du premier nœud texte */
        if (restOfText.length > 0) {
            spanRight.appendChild(document.createTextNode(restOfText));
        }

        /* Puis on réinsère tous les nœuds qui suivaient le premier nœud texte.
           Cela inclut les <span class="lp-hero__h1-orange">, les <br>, les liens, etc.
           Ces nœuds sont DÉPLACÉS (non clonés) depuis el vers spanRight. */
        for (var j = firstTextIndex + 1; j < childNodes.length; j++) {
            spanRight.appendChild(childNodes[j]);
        }

        wrapRight.appendChild(spanRight);

        /* ── Remplacement du contenu de el ───────────────────────────────
           On vide le titre et on insère les deux blocs.
           Si leadingWS est non vide (indentation Twig), on peut l'ignorer
           car les espaces de début d'un titre sont invisibles. */
        el.textContent = ''; /* vide proprement (supprime TOUS les nœuds enfants) */
        el.appendChild(wrapLeft);

        /* Espace blanc entre les deux blocs : reproduit l'espace naturel
           entre le premier mot et la suite (un espace simple suffit).
           On n'utilise PAS margin car on veut que les blocs soient collés
           quand p=1 (comme dans le titre du hero). */
        el.appendChild(document.createTextNode(' '));

        el.appendChild(wrapRight);

        /* ── Stockage des références pour applyState() ───────────────────
           On stocke directement les deux .tr-block (les éléments animés),
           avec leur direction (side) pour le calcul du translateX. */
        el._trBlocks = [
            { el: spanLeft,  side: -1 },
            { el: spanRight, side:  1 }
        ];
    }

    /* ── 6. CAS LIMITE : armement en un seul bloc ──────────────────────────
       Utilisé quand le titre commence par du markup (pas de nœud texte en premier).
       On translate TOUT le contenu vers la droite (side=+1), sans fondu vertical.
       Le ressenti est différent d'un vrai "deux blocs" mais reste cohérent
       avec le hero (pas d'animation différente de type fondu). */
    function armSingleBlock(el, childNodes) {
        var wrap = document.createElement('span');
        wrap.className = 'tr-block-wrap tr-block-wrap--right';

        var span = document.createElement('span');
        span.className = 'tr-block';
        span.setAttribute('data-tr-side', '1');

        /* Réinsère tous les nœuds enfants dans le bloc */
        for (var k = 0; k < childNodes.length; k++) {
            span.appendChild(childNodes[k]);
        }

        wrap.appendChild(span);
        el.textContent = '';
        el.appendChild(wrap);

        el._trBlocks = [
            { el: span, side: 1 }
        ];
    }

    /* ── 7. CALCUL DE LA PROGRESSION p ─────────────────────────────────────
       Pour un élément donné, calcule p dans [0..1] selon sa position
       dans le viewport.

       Convention (IDENTIQUE au hero) :
         p = 1  => titre dans la zone de lecture (blocs rassemblés, lisible)
         p = 0  => titre hors-écran ou trop écarté de la zone (blocs écartés)

       FENÊTRE DE LISIBILITÉ :
       Le titre est à p=1 quand il est dans la zone centrale du viewport.
       En dehors de cette zone (en bas en entrant, en haut en sortant),
       p décroît progressivement vers 0 — les blocs s'écartent.

       PARAMÈTRES DE LA FENÊTRE (en fraction du viewport height) :
         START_FROM_BOTTOM : seuil d'entrée depuis le bas (le titre commence
                             à se rassembler quand son bas dépasse ce seuil)
         FULL_READ_BOTTOM  : à partir de là, le titre est pleinement lisible (p=1)
         FADE_OUT_START    : à partir de là (sortie par le haut), p commence à baisser
         FADE_OUT_END      : le titre est complètement sorti (p=0)

       Ces valeurs créent une fenêtre de lisibilité confortable : le titre est
       p=1 dès qu'il entre dans la zone centrale, et commence à "s'ouvrir"
       seulement quand il repart vers le haut.

       @param {Element} el - L'élément titre.
       @returns {number}   p dans [0..1]. */
    function calcProgress(el) {
        var vh   = window.innerHeight;
        var rect = el.getBoundingClientRect();

        var elBottom = rect.bottom; /* bord bas du titre, en px depuis le haut du viewport */
        var elTop    = rect.top;    /* bord haut du titre, en px depuis le haut du viewport */

        /* ZONE ENTRÉE (depuis le bas) :
           Le titre entre dans le viewport par le bas.
           L'animation commence quand elBottom passe sous START_FROM_BOTTOM*vh. */
        var START_FROM_BOTTOM = 0.92; /* 92% de la hauteur du viewport */

        /* Zone de pleine lisibilité */
        var FULL_READ_BOTTOM  = 0.60; /* bord bas en dessous de 60%*vh => p=1 */
        var FULL_READ_TOP     = 0.65; /* bord haut en dessous de 65%*vh => p=1 */

        /* Zone de sortie (par le haut) */
        var FADE_OUT_END      = 0.15; /* titre sorti à 85% par le haut => p=0 */

        /* Cas 1 : titre entièrement en dessous du viewport (pas encore entré) */
        if (elBottom <= 0) { return 0; }

        /* Cas 2 : titre entièrement sorti par le haut */
        if (elTop <= -el.offsetHeight) { return 0; }

        /* Cas 3 : entrée depuis le bas
           elBottom décroît quand le titre remonte => p augmente de 0 à 1. */
        if (elBottom > FULL_READ_BOTTOM * vh) {
            var enterStart = START_FROM_BOTTOM * vh;
            var enterEnd   = FULL_READ_BOTTOM * vh;
            if (elBottom >= enterStart) { return 0; } /* pas encore entré */
            var pEnter = 1 - (elBottom - enterEnd) / (enterStart - enterEnd);
            return pEnter < 0 ? 0 : (pEnter > 1 ? 1 : pEnter);
        }

        /* Cas 4 : titre dans la zone de pleine lisibilité (p=1) */
        if (elTop >= FADE_OUT_END * vh) { return 1; }

        /* Cas 5 : sortie par le haut
           Le bord haut remonte au-dessus de FADE_OUT_END*vh => p diminue de 1 à 0.
           RÉVERSIBILITÉ : si l'utilisateur remonte, elTop remonte aussi et p
           augmente => les blocs se rejoignent à nouveau. */
        var exitStart = FULL_READ_TOP * vh;
        var exitEnd   = FADE_OUT_END * vh;
        if (elTop >= exitStart) { return 1; }
        var pExit = (elTop - exitEnd) / (exitStart - exitEnd);
        return pExit < 0 ? 0 : (pExit > 1 ? 1 : pExit);
    }

    /* ── 8. APPLICATION DE L'ÉTAT VISUEL ────────────────────────────────────
       Applique translateX et opacity directement en inline style
       en fonction de p (calculé par calcProgress).

       LOGIQUE (IDENTIQUE AU HERO) :
         spread = amplitude selon l'écran (TITLE_SPREAD_DESKTOP ou MOBILE)
         Pour le bloc gauche  (side=-1) : translateX = lerp(0, -spread, 1-p)
           => à p=1 (lisible) : translateX=0 (position naturelle)
           => à p=0 (écarté)  : translateX=-spread (parti vers la gauche)
         Pour le bloc droit (side=+1) : translateX = lerp(0, +spread, 1-p)
           => à p=1 (lisible) : translateX=0
           => à p=0 (écarté)  : translateX=+spread (parti vers la droite)

       Opacity : suit aussi p (0 = invisible, 1 = opaque).
       On utilise opacity sur les .tr-block (pas sur le titre entier) pour
       éviter de faire disparaître les éléments de layout du titre.

       @param {Element} el - Le titre à mettre à jour.
       @param {number}  p  - Progression dans [0..1]. */
    function applyState(el, p) {
        if (!el._trBlocks) { return; } /* titre non armé, rien à faire */

        /* Amplitude selon la taille d'écran — mise à jour à chaque frame
           via la variable isMobile qui est recalculée au resize. */
        var spread  = isMobile ? TITLE_SPREAD_MOBILE : TITLE_SPREAD_DESKTOP;

        /* 1-p = facteur d'écartement : 0 quand lisible (p=1), 1 quand écarté (p=0). */
        var factor  = 1 - p;
        var opacity = p; /* même que dans le hero : opacité proportionnelle à p */

        for (var i = 0; i < el._trBlocks.length; i++) {
            var block   = el._trBlocks[i];
            /* side = -1 (gauche) ou +1 (droite).
               lerp(0, side*spread, factor) :
                 - à factor=0 (p=1) => translateX=0 (rassemblé)
                 - à factor=1 (p=0) => translateX = side*spread (écarté) */
            var tx = lerp(0, block.side * spread, factor);
            block.el.style.transform = 'translateX(' + tx + 'px)';
            block.el.style.opacity   = opacity;
        }
    }

    /* ── 9. ÉTAT GLOBAL DU LISTENER ─────────────────────────────────────────
       Un seul rAF partagé pour tous les titres de la page.
       elements : liste des titres armés (Array d'Elements).
       rafId    : handle rAF en cours (null = aucune requête en attente). */
    var elements = [];
    var rafId    = null;

    /* ── 10. BOUCLE DE MISE À JOUR ──────────────────────────────────────────
       Appelée via rAF. Recalcule p pour chaque titre et applique l'état. */
    function updateAll() {
        rafId = null; /* libère le slot pour la prochaine requête */
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            var p  = calcProgress(el);
            applyState(el, p);
        }
    }

    /* ── 11. LISTENER SCROLL (throttle via rAF) ─────────────────────────────
       Le listener scroll peut se déclencher des dizaines de fois par seconde.
       On planifie un rAF à la place : au plus une mise à jour par frame
       de rendu (60fps max), et jamais entre deux frames. */
    function onScroll() {
        if (rafId === null) {
            rafId = window.requestAnimationFrame(updateAll);
        }
    }

    /* ── 12. LISTENER RESIZE ────────────────────────────────────────────────
       Quand la fenêtre est redimensionnée, les positions relatives des titres
       changent et la détection mobile peut changer.
       On met à jour isMobile et on recalcule immédiatement. */
    function onResize() {
        isMobile = window.innerWidth <= MOBILE_BREAKPOINT;
        onScroll();
    }

    /* ── 13. POINT D'ENTRÉE PRINCIPAL ──────────────────────────────────────
       Appelé une seule fois au DOMContentLoaded (ou immédiatement si DOM prêt). */
    function init() {

        /* ÉTAPE A : signaler au CSS que le JS est prêt.
           La classe .js-title-reveal-ready sur <html> ACTIVE les règles CSS
           d'armement (will-change, overflow:hidden, etc.) sur les blocs.
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
           armTwoBlocks() découpe le titre en 2 blocs (ou 1 si markup en premier)
           et stocke les références dans el._trBlocks pour applyState().
           L'état visuel initial est appliqué par le premier updateAll(). */
        collected.forEach(function (el) {
            armTwoBlocks(el);
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

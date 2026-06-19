/**
 * title-reveal.js — Animation "shutter/slice" par caractère (site-wide)
 * =========================================================================
 * Vanilla JS pur, zéro dépendance, zéro framework.
 * Chargé via base.html.twig => actif sur toutes les pages publiques.
 *
 *
 * EFFET VISUEL (par lettre)
 * -------------------------
 * Chaque caractère du titre est découpé en :
 *   - .tr-char__main  : la lettre principale, fondu + blur (opacity 0->1,
 *                        filter:blur(10px)->blur(0)).
 *   - .tr-char__slice--top/mid/bot : 3 bandes colorées (lime Bazaart) qui
 *     balaient horizontalement en découpant la lettre en hauteur via clip-path.
 *
 * L'animation est pilotée par CSS (keyframes tr-char-main, tr-slice-*).
 * Le JS pose animation-delay via la custom property CSS --i (index de lettre).
 * => stagger croissant : ~0.04s par lettre + 0.05s de base.
 *
 *
 * DÉCLENCHEMENT
 * -------------
 * L'animation se rejoue à CHAQUE ENTRÉE dans le viewport (descente ET remontée).
 * Mécanisme : IntersectionObserver.
 *   - Titre entre dans le viewport -> .is-revealing ajouté -> animation CSS lancée.
 *   - Titre sort du viewport -> .is-revealing retiré.
 *   - Titre ré-entre -> reflow forcé (lecture offsetWidth) pour réinitialiser
 *     l'état CSS, puis .is-revealing re-posé -> animation relancée proprement.
 *
 * Le titre du HERO (above the fold) reçoit .is-revealing AU CHARGEMENT de la page
 * (sans attendre le scroll), car il est déjà visible.
 *
 *
 * DÉCOUPAGE PAR CARACTÈRE
 * -----------------------
 * La fonction armElement() parcourt les nœuds enfants de chaque titre.
 *   - Nœuds TEXTE (nodeType 3) : chaque caractère est enveloppé dans un .tr-char.
 *     Les espaces deviennent des .tr-space (pas d'animation, juste largeur).
 *   - Nœuds ÉLÉMENTS (nodeType 1 : <span>, <br>, etc.) : préservés tels quels,
 *     pas détruits. Pour les éléments inline avec du texte (ex: <span> coloré),
 *     le découpage par caractère est appliqué RÉCURSIVEMENT sur leur contenu.
 *     Pour les éléments structurels (<br>, <svg>…), ils sont replacés sans modification.
 *
 * L'index --i est global à tout le titre (pas par mot) pour un stagger continu.
 *
 *
 * ROBUSTESSE MARKUP
 * -----------------
 * Le découpage récursif préserve les éléments internes (<span> de couleur,
 * <br>, liens, icônes SVG). La lettre hérite de la couleur de son ancêtre
 * (color:inherit sur .tr-char__main).
 *
 * Cas limite documenté :
 * Les <br> sont préservés comme nœuds éléments (pas tentative de découpage
 * de leur contenu vide). L'index --i continue après le <br> sans interruption.
 *
 *
 * PROGRESSIVE ENHANCEMENT
 * -----------------------
 * 1. Le JS ajoute .js-title-reveal-ready sur <html> EN PREMIER.
 * 2. Les titres sont ensuite armés (DOM modifié avec les .tr-char).
 * 3. Sans JS : aucune classe posée => titres visibles normalement.
 * 4. prefers-reduced-motion : le JS sort IMMÉDIATEMENT, avant toute modification.
 *
 *
 * PERFORMANCE
 * -----------
 * - IntersectionObserver : zéro JS par frame (contrairement à un scroll listener).
 *   Le navigateur appelle le callback uniquement quand un titre entre/sort du viewport.
 * - CSS animations GPU (transform + opacity + filter).
 * - will-change posé en CSS sous .js-title-reveal-ready, retiré via .is-done.
 * - Nettoyage Turbo : disconnect() de l'observer avant chaque navigation.
 *
 *
 * PÉRIMÈTRE
 * ---------
 * Cible les titres dans main / #main-content.
 * Le back-office utilise .app-main (div), pas <main> => jamais touché.
 * Inclut le H1 du hero (data-no-reveal supprimé) : déclenché au chargement.
 */

(function () {
    'use strict';

    /* ── 0. GARDE-FOU : API requise ────────────────────────────────────────────
       IntersectionObserver est disponible dans tous les navigateurs modernes
       (support global > 97% en 2026). Polyfill non fourni : on sort proprement
       si l'API est absente, ce qui laisse les titres visibles normalement. */
    if (!window.IntersectionObserver) { return; }

    /* ── 1. ACCESSIBILITÉ : prefers-reduced-motion ─────────────────────────────
       Si l'OS a activé "Réduire les animations", on sort immédiatement.
       Le CSS @media prefers-reduced-motion dans title-reveal.css force
       la visibilité complète en cas de doute. */
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

    /* ── 2. SÉLECTEURS DES TITRES À ANIMER ────────────────────────────────────
       Liste de toutes les classes de titres publics à animer.
       Même liste que l'ancien système "deux blocs", plus le H1 hero.
       Ordre du plus grand titre vers les plus petits pour lisibilité.

       PÉRIMÈTRE : limité à main / #main-content (voir l'appel querySelectorAll).
       Le back-office est épargné automatiquement (il n'a pas de <main>). */
    var SELECTORS = [
        /* === PAGE D'ACCUEIL (vitrine/index.html.twig) === */
        '.lp-hero__h1',                /* H1 hero — déclenché au chargement */
        '.lp-section-header__title',   /* titres de sections (h2) */
        '.lp-saas__h2',                /* "Tout ce dont tu as besoin..." */
        '.lp-hub__name',               /* noms des hubs */

        /* === RESSOURCERIE === */
        '.opp-title',
        '.resource-hero__title',
        '.myresources-title',
        '.alerts-title',
        '.fav-title',

        /* === BLOG === */
        '.art-header__title',
        '.art-identity__title',
        '.art-form-header__title',
        '.art-my-header__title',

        /* === FORUM === */
        '.fi-title',
        '.ft-post__title',
        '.fn-page-title',

        /* === FORMATIONS === */
        '.cf-hero__title',
        '.cf-catalogue-title',
        '.cf-show-hero__title',
        '.cp-title'
    ].join(', ');

    /* ── 3. ARMEMENT D'UN TITRE PAR CARACTÈRE ──────────────────────────────────
       Remplace le contenu textuel du titre par des .tr-char individuels.
       Préserve les éléments HTML internes (couleurs, liens, <br>, icônes).

       @param {Element} el     - L'élément titre à armer.
       @param {Object}  state  - Objet partagé { charIndex: number }
                                  pour le compteur global de lettres du titre.
                                  On le passe par référence pour que la
                                  récursion incrémente le même compteur. */
    function armElement(el, state) {

        /* Snapshot des nœuds enfants AVANT modification (car on va les remplacer) */
        var childNodes = Array.prototype.slice.call(el.childNodes);

        /* Vide l'élément — on va le remplir avec les nouveaux nœuds */
        while (el.firstChild) { el.removeChild(el.firstChild); }

        /* Parcours de chaque nœud enfant */
        for (var i = 0; i < childNodes.length; i++) {
            var node = childNodes[i];

            if (node.nodeType === 3) {
                /* ── Nœud TEXTE : on découpe caractère par caractère ─── */
                var text = node.nodeValue;
                for (var c = 0; c < text.length; c++) {
                    var ch = text[c];

                    if (ch === ' ' || ch === ' ' || ch === '\n' || ch === '\t') {
                        /* Espace (normal ou insécable) : on crée un .tr-space.
                           Pas d'animation, juste un placeholder pour la largeur.
                           Les sauts de ligne et tabulations sont ignorés
                           (whitespace d'indentation Twig non visible). */
                        if (ch === ' ' || ch === ' ') {
                            var spaceSpan = document.createElement('span');
                            spaceSpan.className = 'tr-space';
                            /* Espace insécable : on conserve le caractère */
                            spaceSpan.textContent = ch === ' ' ? ' ' : ' ';
                            el.appendChild(spaceSpan);
                        }
                        /* Whitespace invisible (newline Twig) : ignoré */
                    } else {
                        /* Caractère visible : crée un .tr-char complet */
                        el.appendChild(buildCharSpan(ch, state.charIndex));
                        state.charIndex++;
                    }
                }

            } else if (node.nodeType === 1) {
                /* ── Nœud ÉLÉMENT : préserver la structure HTML ──────── */
                var tagName = node.tagName.toLowerCase();

                if (tagName === 'br') {
                    /* <br> : on le réinsère tel quel.
                       Il ne contient pas de texte à découper. */
                    el.appendChild(node);

                } else if (tagName === 'svg' || tagName === 'img' ||
                           tagName === 'canvas' || tagName === 'video') {
                    /* Éléments médias : réinsérés sans modification */
                    el.appendChild(node);

                } else {
                    /* Éléments inline (<span>, <a>, <strong>, <em>…) :
                       on réinsère l'élément ET on découpe son contenu
                       textuel récursivement.
                       Cela permet d'animer lettre par lettre les spans
                       de couleur comme .lp-hero__h1-orange ou .lp-saas__h2-accent,
                       tout en conservant leurs styles.
                       L'index --i continue de façon contiguë grâce à state.charIndex
                       partagé par référence. */
                    el.appendChild(node);
                    /* Découpe récursive du contenu de cet élément */
                    armElement(node, state);
                }
            }
            /* Les autres types de nœuds (commentaires, CDATA…) sont ignorés */
        }
    }

    /* ── 4. CONSTRUCTION D'UN SPAN DE LETTRE ──────────────────────────────────
       Crée la structure complète d'une lettre animée :
         <span class="tr-char" style="--i: N">
           <span class="tr-char__main">A</span>
           <span class="tr-char__slice tr-char__slice--top" aria-hidden="true"></span>
           <span class="tr-char__slice tr-char__slice--mid" aria-hidden="true"></span>
           <span class="tr-char__slice tr-char__slice--bot" aria-hidden="true"></span>
         </span>

       Les slices sont des éléments VIDES (aucun texte) — purement décoratifs.
       Leur fond coloré (var(--accent) via CSS) crée l'effet de balayage.
       Seul .tr-char__main contient le texte lisible de la lettre.

       @param {string} char  - Le caractère à afficher.
       @param {number} index - L'index de cette lettre dans le titre (pour --i).
       @returns {HTMLElement} Le span .tr-char prêt à être inséré dans le DOM. */
    function buildCharSpan(char, index) {
        /* Conteneur de la lettre */
        var wrap = document.createElement('span');
        wrap.className = 'tr-char';
        /* --i = index de la lettre => animation-delay calculé en CSS :
           calc(var(--i, 0) * 0.04s + 0.05s)
           On utilise setProperty pour garantir la compatibilité avec les
           custom properties (les navigateurs anciens peuvent ignorer style="--i:") */
        wrap.style.setProperty('--i', index);

        /* Lettre principale (visible, fondu+blur) */
        var main = document.createElement('span');
        main.className = 'tr-char__main';
        main.textContent = char;
        wrap.appendChild(main);

        /* Les 3 bandes de balayage (éléments vides, purement décoratifs).
           Leur fond coloré (var(--accent) via CSS) crée l'effet de balayage.
           Elles n'ont aucun contenu textuel — le texte lisible est dans
           .tr-char__main uniquement.
           aria-hidden="true" : les lecteurs d'écran les ignorent. */
        var slices = ['tr-char__slice--top', 'tr-char__slice--mid', 'tr-char__slice--bot'];
        for (var s = 0; s < slices.length; s++) {
            var slice = document.createElement('span');
            slice.className = 'tr-char__slice ' + slices[s];
            slice.setAttribute('aria-hidden', 'true');
            /* Les bandes ne contiennent PAS le caractère : elles sont de purs
               éléments décoratifs colorés (fond var(--accent)), pas de texte.
               Le texte lisible est dans .tr-char__main uniquement. */
            wrap.appendChild(slice);
        }

        return wrap;
    }

    /* ── 5. DÉCLENCHEMENT DE L'ANIMATION ──────────────────────────────────────
       Ajoute la classe .is-revealing sur un titre pour lancer l'animation CSS.
       Planifie aussi la pose de .is-done après la fin de la dernière animation,
       pour retirer will-change et libérer les ressources GPU.

       @param {Element} el       - Le titre à animer.
       @param {number}  charCount - Nombre total de lettres animées dans ce titre. */
    function triggerReveal(el, charCount) {
        /* Calcul du délai de fin de la DERNIÈRE lettre :
           delay de la dernière lettre = (charCount - 1) * 0.04s + 0.05s
           Durée de l'animation = 0.8s (tr-char-main, la plus longue)
           => temps total avant is-done = delay_dernière_lettre + durée + marge 50ms */
        var lastDelay     = (charCount - 1) * 0.04 + 0.05;  /* en secondes */
        var animDuration  = 0.8;                              /* secondes */
        var totalMs       = (lastDelay + animDuration + 0.05) * 1000; /* millisecondes */

        /* Pose .is-revealing => les règles CSS .is-revealing .tr-char__main etc.
           lancent les animations avec les bons delays. */
        el.classList.add('is-revealing');
        el.classList.remove('is-done');

        /* Retire will-change après la fin de toutes les animations */
        setTimeout(function () {
            /* Vérification : si le titre a été ré-sorti du viewport pendant
               ce timeout, on n'ajoute pas is-done (ça serait incohérent). */
            if (el.classList.contains('is-revealing')) {
                el.classList.add('is-done');
            }
        }, totalMs);
    }

    /* ── 6. ÉTAT GLOBAL ────────────────────────────────────────────────────────
       On stocke pour chaque titre le nombre de lettres animées (charCount)
       pour pouvoir calculer le délai de fin dans triggerReveal(). */
    var titlesData = []; /* tableau de { el, charCount, isHero } */
    var observer   = null;

    /* ── 7. INITIALISATION ─────────────────────────────────────────────────────
       Appelée une seule fois au DOMContentLoaded (ou immédiatement si DOM prêt). */
    function init() {

        /* ÉTAPE A : signaler au CSS que le JS est actif.
           Doit être fait AVANT d'armer les titres pour que le CSS de cache
           (opacity:0 sur .js-title-reveal-ready .tr-char__main) s'applique
           dès la création des éléments. Si on posait la classe après, les
           lettres seraient visibles l'espace d'un frame avant d'être masquées
           => flash non souhaité (FOUC). */
        document.documentElement.classList.add('js-title-reveal-ready');

        /* ÉTAPE B : trouver le conteneur principal.
           On limite à main / #main-content pour éviter nav, footer, back-office. */
        var container = document.getElementById('main-content') || document.querySelector('main');
        if (!container) {
            /* Pas de <main> : retirer la classe pour ne pas cacher les titres */
            document.documentElement.classList.remove('js-title-reveal-ready');
            return;
        }

        /* ÉTAPE C : collecter les titres correspondant aux sélecteurs. */
        var rawElements = container.querySelectorAll(SELECTORS);
        if (!rawElements || rawElements.length === 0) {
            document.documentElement.classList.remove('js-title-reveal-ready');
            return;
        }

        var collected = Array.prototype.slice.call(rawElements);

        /* Filtrer les exclusions explicites (data-no-reveal="true").
           Cette fois le H1 hero n'a PAS data-no-reveal => il est inclus. */
        collected = collected.filter(function (el) {
            return el.getAttribute('data-no-reveal') !== 'true';
        });

        if (collected.length === 0) {
            document.documentElement.classList.remove('js-title-reveal-ready');
            return;
        }

        /* ÉTAPE D : armer chaque titre (découpage par caractère). */
        collected.forEach(function (el) {

            /* ── ACCESSIBILITÉ : aria-label avant découpe ─────────────────────
               Certains lecteurs d'écran (NVDA+Firefox notamment) peuvent épeler
               un titre lettre par lettre quand son contenu est fragmenté en spans.
               Solution : on capture le TEXTE COMPLET du titre AVANT de modifier
               le DOM, et on le pose en aria-label sur l'élément racine.
               Ainsi le lecteur d'écran lit le label de l'élément en entier et
               ignore complètement la structure interne (les spans .tr-char).

               Règles appliquées :
               - On normalise le texte : trim() + remplacement des suites
                 d'espaces/retours à la ligne (whitespace Twig) par un espace unique.
               - On ne pose l'aria-label QUE si l'élément n'en a pas déjà un
                 (on respecte une décision éditoriale manuelle).
               - On ne l'applique QU'ICI (l'élément titre racine), jamais
                 sur les enfants ou les lettres individuelles.

               Note : el.textContent récupère bien le texte de TOUS les descendants
               (y compris les <span> de couleur internes comme .lp-hero__h1-orange).
               C'est exactement ce que l'on veut pour un aria-label complet. */
            if (!el.getAttribute('aria-label')) {
                var fullText = el.textContent.replace(/\s+/g, ' ').trim();
                if (fullText) {
                    el.setAttribute('aria-label', fullText);
                }
            }

            /* Compteur de lettres partagé par la récursion (objet passé par référence) */
            var state = { charIndex: 0 };
            armElement(el, state);

            /* Vérification : si le titre est vide après armement (pas de texte),
               on le saute pour ne pas démarrer d'animation vide. */
            if (state.charIndex === 0) { return; }

            /* Identifier si c'est le hero (above the fold) :
               .lp-hero__h1 doit jouer l'animation au chargement, pas au scroll. */
            var isHero = el.classList.contains('lp-hero__h1');

            titlesData.push({ el: el, charCount: state.charIndex, isHero: isHero });
        });

        if (titlesData.length === 0) {
            document.documentElement.classList.remove('js-title-reveal-ready');
            return;
        }

        /* ÉTAPE E : configurer l'IntersectionObserver.

           threshold: 0.1 = le callback se déclenche quand au moins 10% du titre
           est visible. On n'attend pas 100% de visibilité car les grands titres
           (H1 hero) font parfois plus de 50% de la hauteur du viewport.

           rootMargin: '0px' = on utilise les bords exacts du viewport,
           sans marche d'anticipation (on veut l'entrée réelle dans la vue). */
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var el = entry.target;

                /* Retrouver les données du titre */
                var data = null;
                for (var i = 0; i < titlesData.length; i++) {
                    if (titlesData[i].el === el) { data = titlesData[i]; break; }
                }
                if (!data) { return; }

                if (entry.isIntersecting) {
                    /* ── Le titre ENTRE dans le viewport ─────────────────
                       Si .is-revealing est déjà présent (cas impossible avec
                       l'observer mais garde-fou) : forcer un reflow d'abord. */
                    if (el.classList.contains('is-revealing')) {
                        /* Retrait + reflow + re-pose pour rejouer l'animation */
                        el.classList.remove('is-revealing', 'is-done');
                        /* eslint-disable-next-line no-unused-expressions */
                        void el.offsetWidth; /* force reflow (réinitialise l'état CSS) */
                    }
                    triggerReveal(el, data.charCount);

                } else {
                    /* ── Le titre SORT du viewport ────────────────────────
                       On retire .is-revealing pour que l'animation puisse
                       être relancée à la prochaine entrée (scroll up ou down).
                       On retire aussi .is-done pour remettre will-change en place. */
                    el.classList.remove('is-revealing', 'is-done');
                }
            });
        }, {
            threshold:  0.1,    /* 10% de visibilité = déclenchement */
            rootMargin: '0px'   /* bords exacts du viewport */
        });

        /* ÉTAPE F : observer les titres et déclencher le hero immédiatement. */
        titlesData.forEach(function (data) {
            if (data.isHero) {
                /* Le hero est above the fold : déclencher l'animation
                   MAINTENANT sans attendre l'observer.
                   L'observer le surveille quand même pour que l'animation
                   se rejoue si l'utilisateur remonte en haut de la page. */
                triggerReveal(data.el, data.charCount);
            }
            /* Observer tous les titres (y compris le hero pour la ré-entrée) */
            observer.observe(data.el);
        });

        /* ÉTAPE G : nettoyage Turbo.
           Si Symfony UX Turbo est actif, on déconnecte l'observer et on retire
           la classe d'armement avant chaque navigation (évite les fuites mémoire
           et pollutions de la page suivante). */
        document.addEventListener('turbo:before-visit', function () {
            if (observer) {
                observer.disconnect();
                observer = null;
            }
            /* Retirer la classe d'armement : la page suivante initialisera
               son propre système via le DOMContentLoaded Turbo. */
            document.documentElement.classList.remove('js-title-reveal-ready');
            /* Vide le tableau pour éviter que les refs DOM de l'ancienne page
               restent en mémoire. */
            titlesData = [];
        }, { once: true });
    }

    /* Lance l'initialisation dès que le DOM est parsé.
       Si le script est chargé en defer, le DOM est déjà prêt
       quand le script s'exécute => on appelle init() directement.
       Si pour une raison quelconque le script est dans le <head> sans defer,
       on attend DOMContentLoaded. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}()); /* IIFE : portée locale, zéro variable globale */

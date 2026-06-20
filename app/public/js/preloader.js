/**
 * preloader.js — Animation spirale + splash screen Bazaart
 *
 * PRINCIPE GENERAL :
 *   Ce script gere l'overlay de chargement plein ecran affiche lors des
 *   hard refresh et de la premiere arrivee sur le site.
 *
 *   Il reimplemente fidelement la logique du composant React/GSAP "SpiralAnimation"
 *   en JS vanilla pur (pas de GSAP, pas de React, pas de librairie tierce).
 *
 * CLASSES PRINCIPALES :
 *   - AnimationController : gere le canvas, la boucle rAF, la spirale, la projection 3D->2D
 *   - Star                : une etoile individuelle avec ses 3 phases de deplacement
 *
 * REGLES D'AFFICHAGE :
 *   - Hard refresh (navigation.type === 'reload') OU premiere visite de la session
 *     -> afficher le splash
 *   - Navigation interne (Turbo/SPA ou lien normal) -> NE PAS afficher le splash
 *   - JS coupe -> l'overlay reste invisible (opacity:0 par defaut en CSS) = pas de blocage
 *   - prefers-reduced-motion -> aucune animation, logo bref, puis disparition
 *
 * DUREE :
 *   Animation spirale : ~2500ms (ANIM_DURATION_MS)
 *   Fondu de sortie   : ~500ms (gere par CSS transition opacity)
 *
 * DEVICEPIXELRATIO :
 *   Le canvas est dimensionne en pixels physiques (DPR * taille CSS) pour
 *   un rendu net sur les ecrans Retina (DPR = 2 ou 3).
 */

(function () {
    'use strict';

    /* ── Constantes de configuration ───────────────────────────────────────── */

    /* Duree totale de l'animation spirale en millisecondes */
    var ANIM_DURATION_MS = 2500;

    /* Duree du fondu de sortie de l'overlay en millisecondes (doit correspondre
     * a la valeur de "transition: opacity Xs" dans preloader.css) */
    var FADE_OUT_MS = 500;

    /* Nombre d'etoiles affichees dans la spirale */
    var STAR_COUNT = 180;

    /* Facteur de perspective pour la projection 3D -> 2D.
     * Plus cette valeur est grande, moins la perspective est prononcee. */
    var PERSPECTIVE = 250;

    /* ── Utilitaires mathematiques ──────────────────────────────────────────── */

    /**
     * ease (easing lineaire simple - identite ici, peut etre remplace).
     * t : valeur normalisee entre 0 et 1.
     * @param {number} t
     * @returns {number}
     */
    function ease(t) {
        /* Courbe sinusoidale douce : accelere au debut, ralentit a la fin */
        return t < 0.5
            ? 2 * t * t
            : -1 + (4 - 2 * t) * t;
    }

    /**
     * easeOutElastic : rebond elastique en sortie.
     * Utilise pour l'apparition des points de la spirale.
     * @param {number} t - valeur entre 0 et 1
     * @returns {number}
     */
    function easeOutElastic(t) {
        if (t === 0 || t === 1) return t;
        var p = 0.4;
        var s = p / 4;
        return Math.pow(2, -10 * t) * Math.sin((t - s) * (2 * Math.PI) / p) + 1;
    }

    /**
     * map : remapping lineaire d'une valeur d'un intervalle vers un autre.
     * @param {number} value
     * @param {number} low1 - borne basse de l'intervalle source
     * @param {number} high1 - borne haute de l'intervalle source
     * @param {number} low2 - borne basse de l'intervalle cible
     * @param {number} high2 - borne haute de l'intervalle cible
     * @returns {number}
     */
    function map(value, low1, high1, low2, high2) {
        return low2 + (high2 - low2) * ((value - low1) / (high1 - low1));
    }

    /**
     * constrain : limite une valeur entre min et max.
     * @param {number} value
     * @param {number} min
     * @param {number} max
     * @returns {number}
     */
    function constrain(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }

    /**
     * lerp : interpolation lineaire entre a et b.
     * t=0 -> a, t=1 -> b.
     * @param {number} a
     * @param {number} b
     * @param {number} t
     * @returns {number}
     */
    function lerp(a, b, t) {
        return a + (b - a) * t;
    }

    /* ── Classe Star ────────────────────────────────────────────────────────── */

    /**
     * Star : une etoile dans l'espace 3D de la spirale.
     *
     * PHASES DE DEPLACEMENT (identiques au composant React d'origine) :
     *   Phase 1 (t de 0 a 0.3) : l'etoile part d'une position aleatoire eloignee
     *                             et converge vers l'axe de la spirale.
     *   Phase 2 (t de 0.3 a 0.7) : l'etoile suit la trajectoire helicoide (spirale 3D).
     *   Phase 3 (t de 0.7 a 1.0) : l'etoile se disperse vers l'exterieur puis disparait.
     *
     * @param {number} index - index de l'etoile (0 a STAR_COUNT-1)
     * @param {number} total - nombre total d'etoiles
     */
    function Star(index, total) {
        /* Angle sur la spirale : reparti regulierement + decalage aleatoire */
        this.angle = (index / total) * Math.PI * 2 * 6 + (Math.random() - 0.5) * 0.5;

        /* Rayon initial dans l'espace 3D */
        this.radius = map(index, 0, total, 20, 160);

        /* Profondeur z initiale : les etoiles partent "devant" la camera */
        this.z = Math.random() * 300 - 150;

        /* Position de depart aleatoire (phase 1) */
        this.startX = (Math.random() - 0.5) * 800;
        this.startY = (Math.random() - 0.5) * 800;
        this.startZ = Math.random() * 400 - 200;

        /* Taille visuelle de l'etoile en pixels CSS */
        this.size = Math.random() * 2.5 + 0.5;

        /* Opacite de base */
        this.opacity = Math.random() * 0.6 + 0.4;

        /* Decalage de phase : chaque etoile demarre legerement plus tard
         * -> effet de cascade progressif plutot qu'un demarrage simultane */
        this.phaseOffset = (index / total) * 0.3;

        /* Historique des positions precedentes pour le trail (trainee lumineuse) */
        this.trail = [];

        /* Nombre maximum de points dans la trainee */
        this.maxTrail = 8;
    }

    /**
     * Calcule la position 3D de l'etoile pour une progression t donnee.
     *
     * @param {number} t - progression globale de l'animation (0 a 1)
     * @returns {{x3d: number, y3d: number, z3d: number, alpha: number}}
     *          Position 3D et opacite resultante.
     */
    Star.prototype.getPosition = function (t) {
        /* Progression locale de cette etoile (decalee par phaseOffset) */
        var localT = constrain((t - this.phaseOffset) / (1 - this.phaseOffset), 0, 1);

        var x3d, y3d, z3d, alpha;

        if (localT < 0.3) {
            /* ── Phase 1 : convergence vers la spirale ─────────────────────── */
            /* L'etoile va de sa position initiale vers sa position sur la spirale */
            var p1 = localT / 0.3;             /* progression dans la phase 1 : 0->1 */
            var p1e = easeOutElastic(p1);

            /* Position cible sur la spirale au debut de la phase 2 */
            var targetAngle = this.angle;
            var targetR     = this.radius;
            var targetX     = Math.cos(targetAngle) * targetR;
            var targetY     = Math.sin(targetAngle) * targetR;
            var targetZ     = this.z;

            x3d = lerp(this.startX, targetX, p1e);
            y3d = lerp(this.startY, targetY, p1e);
            z3d = lerp(this.startZ, targetZ, p1e);
            alpha = lerp(0, this.opacity, p1);

        } else if (localT < 0.7) {
            /* ── Phase 2 : rotation helicoide (spirale proprement dite) ────── */
            var p2 = (localT - 0.3) / 0.4;    /* progression dans la phase 2 : 0->1 */

            /* L'angle augmente au fil du temps : rotation de la spirale */
            var a2 = this.angle + p2 * Math.PI * 2;

            /* Le rayon se resserre legerement vers le centre */
            var r2 = this.radius * (1 - p2 * 0.15);

            x3d  = Math.cos(a2) * r2;
            y3d  = Math.sin(a2) * r2;
            /* Deplacement en profondeur : les etoiles avancent vers la camera */
            z3d  = this.z + p2 * 100;
            alpha = this.opacity;

        } else {
            /* ── Phase 3 : dispersion et disparition ────────────────────────── */
            var p3 = (localT - 0.7) / 0.3;    /* progression dans la phase 3 : 0->1 */

            /* Continuer la rotation */
            var a3 = this.angle + 0.4 * Math.PI * 2 + p3 * Math.PI;

            /* Les etoiles s'ecartent rapidement du centre */
            var r3 = this.radius * (1 - 0.15 + p3 * 2.5);

            x3d  = Math.cos(a3) * r3;
            y3d  = Math.sin(a3) * r3;
            z3d  = this.z + 100 + p3 * 200;
            alpha = lerp(this.opacity, 0, p3);
        }

        return { x3d: x3d, y3d: y3d, z3d: z3d, alpha: alpha };
    };

    /**
     * Rendu d'une etoile sur le canvas.
     * Inclut le dessin de la trainee lumineuse (trail) derriere l'etoile.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {number} cx  - coordonnee X du centre de la scene
     * @param {number} cy  - coordonnee Y du centre de la scene
     * @param {number} t   - progression de l'animation (0 a 1)
     */
    Star.prototype.render = function (ctx, cx, cy, t) {
        var pos = this.getPosition(t);

        /* ── Projection 3D -> 2D (perspective) ─────────────────────────────── */
        /*
         * Formule de projection en perspective conique :
         *   x2d = cx + x3d * (PERSPECTIVE / (PERSPECTIVE + z3d))
         * Plus z3d est grand (loin), plus l'etoile est proche du centre (cx, cy).
         * Plus z3d est negatif (pres), plus elle s'ecarte du centre.
         */
        var dz = PERSPECTIVE + pos.z3d;
        if (dz <= 0) return;                    /* derriere la camera : ne pas dessiner */
        var scale = PERSPECTIVE / dz;

        var x2d = cx + pos.x3d * scale;
        var y2d = cy + pos.y3d * scale;
        var r   = this.size * scale;

        if (r < 0.1) return;                    /* trop petit : ne pas dessiner */

        /* ── Mise a jour de la trainee ─────────────────────────────────────── */
        /* On empile la position courante au debut du tableau */
        this.trail.unshift({ x: x2d, y: y2d, r: r });

        /* On limite la longueur de la trainee */
        if (this.trail.length > this.maxTrail) {
            this.trail.pop();
        }

        /* ── Dessin de la trainee (trait qui s'estompe) ─────────────────────── */
        if (this.trail.length > 1) {
            for (var i = 1; i < this.trail.length; i++) {
                /* L'opacite de la trainee diminue avec la distance */
                var trailAlpha = pos.alpha * (1 - i / this.trail.length) * 0.4;
                var trailR     = this.trail[i].r * (1 - i / this.trail.length * 0.5);

                if (trailR < 0.05 || trailAlpha < 0.01) continue;

                ctx.beginPath();
                ctx.arc(this.trail[i].x, this.trail[i].y, trailR, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(255,255,255,' + trailAlpha + ')';
                ctx.fill();
            }
        }

        /* ── Dessin de l'etoile principale ─────────────────────────────────── */
        ctx.beginPath();
        ctx.arc(x2d, y2d, r, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,' + pos.alpha + ')';
        ctx.fill();
    };

    /* ── AnimationController ────────────────────────────────────────────────── */

    /**
     * AnimationController : gere le cycle de vie complet de l'animation.
     *
     * CYCLE :
     *   1. init()       : recupere les elements DOM, initialise les etoiles
     *   2. start()      : verifie si le splash doit etre affiche, lance la boucle rAF
     *   3. render()     : dessin a chaque frame (appele par rAF)
     *   4. finish()     : declenche le fondu de sortie de l'overlay
     *   5. hide()       : masque definitivement l'overlay apres le fondu
     *
     * @constructor
     */
    function AnimationController() {
        /* Elements DOM */
        this.overlay = null;
        this.canvas  = null;
        this.logo    = null;
        this.ctx     = null;

        /* Dimensions du canvas en pixels physiques (DPR pris en compte) */
        this.canvasW = 0;
        this.canvasH = 0;
        this.dpr     = 1;

        /* Tableau des etoiles */
        this.stars = [];

        /* Progression de l'animation : de 0 a 1 */
        this.time = 0;

        /* Timestamp de debut de l'animation (fixe par rAF a la premiere frame) */
        this.startTime = null;

        /* Identifiant de la boucle requestAnimationFrame (pour pouvoir l'annuler) */
        this.rafId = null;

        /* Référence au handler 'resize' enregistré sur window.
         * Stockée ici pour pouvoir le retirer proprement dans hide() et éviter
         * une fuite mémoire (un handler orphelin resterait lié à l'objet controller
         * même après la disparition de l'overlay). */
        this.resizeHandler = null;
    }

    /**
     * Initialise les elements DOM et les etoiles.
     * Retourne false si les elements sont absents du DOM.
     * @returns {boolean}
     */
    AnimationController.prototype.init = function () {
        this.overlay = document.getElementById('bzrt-preloader');
        this.canvas  = document.getElementById('bzrt-preloader-canvas');
        this.logo    = document.getElementById('bzrt-preloader-logo');

        if (!this.overlay || !this.canvas || !this.logo) {
            /* Les elements ne sont pas dans le DOM (page sans base.html.twig ?) */
            return false;
        }

        this.ctx = this.canvas.getContext('2d');
        if (!this.ctx) return false;

        /* Calibration initiale du canvas */
        this.resize();

        /* Initialisation des etoiles */
        this.stars = [];
        for (var i = 0; i < STAR_COUNT; i++) {
            this.stars.push(new Star(i, STAR_COUNT));
        }

        return true;
    };

    /**
     * Adapte la taille du canvas a la fenetre en tenant compte du devicePixelRatio.
     *
     * POURQUOI DPR ?
     * Sur un ecran Retina (DPR=2), chaque pixel CSS = 4 pixels physiques.
     * Si on ne tient pas compte du DPR, le canvas est rendu en basse resolution
     * et apparait flou sur ces ecrans.
     * Solution : canvas.width/height en pixels physiques, context scale par DPR,
     * style.width/height en pixels CSS (via CSS position:absolute 100%).
     */
    AnimationController.prototype.resize = function () {
        this.dpr     = window.devicePixelRatio || 1;
        this.canvasW = window.innerWidth  * this.dpr;
        this.canvasH = window.innerHeight * this.dpr;

        this.canvas.width  = this.canvasW;
        this.canvas.height = this.canvasH;

        /* Le context est scale : 1 unite = 1 pixel CSS (pas physique) */
        this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    };

    /**
     * Calcule la position angulaire de la spirale pour un parametre t donne.
     * Utilise pour dessiner le chemin central de la spirale (reference visuelle).
     *
     * @param {number} t - progression de l'animation (0 a 1)
     * @returns {{x: number, y: number}}
     */
    AnimationController.prototype.spiralPath = function (t) {
        var cx = window.innerWidth  / 2;
        var cy = window.innerHeight / 2;

        /* La spirale fait 4 tours complets */
        var angle  = t * Math.PI * 2 * 4;
        var radius = t * Math.min(cx, cy) * 0.6;

        return {
            x: cx + Math.cos(angle) * radius,
            y: cy + Math.sin(angle) * radius
        };
    };

    /**
     * Rendu d'un point central de la spirale (le "point de depart" visible).
     * @param {number} cx
     * @param {number} cy
     */
    AnimationController.prototype.drawStartDot = function (cx, cy) {
        /* Halo lumineux autour du point central */
        var grad = this.ctx.createRadialGradient(cx, cy, 0, cx, cy, 12);
        grad.addColorStop(0,   'rgba(255,255,255,0.9)');
        grad.addColorStop(0.4, 'rgba(255,255,255,0.3)');
        grad.addColorStop(1,   'rgba(255,255,255,0)');

        this.ctx.beginPath();
        this.ctx.arc(cx, cy, 12, 0, Math.PI * 2);
        this.ctx.fillStyle = grad;
        this.ctx.fill();

        /* Point central opaque */
        this.ctx.beginPath();
        this.ctx.arc(cx, cy, 2, 0, Math.PI * 2);
        this.ctx.fillStyle = 'rgba(255,255,255,0.95)';
        this.ctx.fill();
    };

    /**
     * Rendu d'une frame de l'animation.
     * Appele a chaque iteration de la boucle requestAnimationFrame.
     *
     * @param {number} timestamp - timestamp fourni par rAF en millisecondes
     */
    AnimationController.prototype.render = function (timestamp) {
        /* Enregistrement du debut de l'animation a la premiere frame */
        if (this.startTime === null) {
            this.startTime = timestamp;
        }

        /* Calcul de la progression (0 a 1, clampee a 1) */
        var elapsed = timestamp - this.startTime;
        this.time   = Math.min(elapsed / ANIM_DURATION_MS, 1);

        var W  = window.innerWidth;
        var H  = window.innerHeight;
        var cx = W / 2;
        var cy = H / 2;

        /* ── Effacement du canvas (fond noir semi-transparent pour trail effect) */
        /*
         * On n'efface pas completement le canvas a chaque frame.
         * On applique un rectangle noir semi-opaque (alpha=0.15) :
         * les anciennes frames s'estompent progressivement -> effet de trainee lumineuse.
         * Plus l'alpha est eleve, plus la trainee est courte.
         */
        this.ctx.fillStyle = 'rgba(0,0,0,0.15)';
        this.ctx.fillRect(0, 0, W, H);

        /* ── Rendu de toutes les etoiles ─────────────────────────────────────── */
        for (var i = 0; i < this.stars.length; i++) {
            this.stars[i].render(this.ctx, cx, cy, this.time);
        }

        /* ── Point central de la spirale ─────────────────────────────────────── */
        /* Le point suit la trajectoire spirale avec un leger easing */
        var eased    = ease(this.time);
        var dotPos   = this.spiralPath(eased);
        this.drawStartDot(dotPos.x, dotPos.y);

        /* ── Animation du logo en fondu progressif ───────────────────────────── */
        /*
         * Le logo monte progressivement en opacite pendant l'animation.
         * Il demarre a 0 et atteint 1 vers t=0.7 (70% de l'animation).
         * On utilise constrain pour eviter d'aller au-dela de 1.
         */
        var logoAlpha = constrain(map(this.time, 0.1, 0.7, 0, 1), 0, 1);
        this.logo.style.opacity = logoAlpha;

        /* ── Fin de l'animation -> fondu de sortie ───────────────────────────── */
        if (this.time >= 1) {
            this.finish();
            return; /* on n'appelle plus rAF -> la boucle s'arrete */
        }

        /* Continuer la boucle */
        var self = this;
        this.rafId = requestAnimationFrame(function (ts) { self.render(ts); });
    };

    /**
     * Declenche la disparition de l'overlay.
     * Retire .is-active (qui stoppe la transition:none) -> la transition CSS opacity:0.5s
     * s'applique automatiquement et fait fondre l'overlay.
     */
    AnimationController.prototype.finish = function () {
        var self = this;

        /* S'assure que le logo est bien visible avant la sortie */
        this.logo.style.opacity = 1;

        /* Retire la classe active -> la transition CSS (0.5s) demarre automatiquement */
        this.overlay.classList.remove('is-active');

        /* Apres le fondu, masque definitivement l'overlay */
        setTimeout(function () {
            self.hide();
        }, FADE_OUT_MS + 50);  /* +50ms de marge pour la transition CSS */
    };

    /**
     * Masque definitivement l'overlay apres le fondu.
     * display:none retire l'element du flux de rendu.
     * pointer-events:none (deja dans le CSS) est redondant mais explicite.
     *
     * On retire egalement le handler 'resize' de window pour eviter une fuite
     * memoire : sans ce removeEventListener, le handler garderait une reference
     * a l'objet AnimationController meme apres la fin du splash.
     */
    AnimationController.prototype.hide = function () {
        /* Nettoyage du listener resize (fuite memoire preventive) */
        if (this.resizeHandler) {
            window.removeEventListener('resize', this.resizeHandler);
            this.resizeHandler = null;
        }

        this.overlay.style.display    = 'none';
        this.overlay.style.pointerEvents = 'none';
    };

    /**
     * Demarre l'animation ou masque immediatement l'overlay selon les conditions.
     *
     * CONDITIONS D'AFFICHAGE :
     *   - hard refresh (navigation.type === 'reload') OU premiere visite de session
     *   -> affiche le splash
     *
     *   - navigation interne (clic de lien SPA ou Turbo)
     *   -> masque immediatement l'overlay, sans animation
     *
     *   - prefers-reduced-motion
     *   -> affiche le logo brievement sans animation spirale, puis disparait
     */
    AnimationController.prototype.start = function () {
        /* ── Verification : doit-on afficher le splash ? ─────────────────────── */

        /*
         * performance.getEntriesByType('navigation') retourne un tableau
         * de PerformanceNavigationTiming. Le premier element decrit le type
         * de navigation courante :
         *   - 'navigate'   : premiere arrivee, lien externe
         *   - 'reload'     : hard refresh (F5, Ctrl+R, cmd+R)
         *   - 'back_forward' : bouton retour/avant
         *   - 'prerender'  : prerendu speculatif
         *
         * On affiche le splash si c'est un reload OU la premiere visite de la session.
         * sessionStorage est efface a la fermeture de l'onglet -> une nouvelle session
         * = nouvelle visite = splash affiche.
         */
        var navEntries  = performance.getEntriesByType('navigation');
        var nav         = navEntries && navEntries.length > 0 ? navEntries[0] : null;
        var isReload    = nav && nav.type === 'reload';
        var firstArrival = !sessionStorage.getItem('bzrt_splash_seen');

        if (!isReload && !firstArrival) {
            /* Navigation interne -> masquage immediat, pas d'animation */
            this.hide();
            return;
        }

        /* Marquer la session comme ayant vu le splash */
        sessionStorage.setItem('bzrt_splash_seen', '1');

        /* ── Cas : prefers-reduced-motion ────────────────────────────────────── */
        /*
         * Si l'utilisateur a configure "Reduire les animations" dans son OS,
         * on respecte cette preference : pas de mouvement.
         * On affiche juste l'overlay avec le logo visible brievement, puis on disparait.
         */
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            /* Le CSS rend deja le logo visible (opacity:1 dans @media reduced-motion) */
            this.overlay.classList.add('is-active');

            var self = this;
            /* Attente courte (400ms), puis disparition sans animation */
            setTimeout(function () {
                self.hide();
            }, 400);
            return;
        }

        /* ── Cas normal : animation spirale ──────────────────────────────────── */

        /* Afficher l'overlay instantanement (is-active supprime la transition d'entree) */
        this.overlay.classList.add('is-active');

        /* Effacement initial complet du canvas */
        this.ctx.fillStyle = '#000000';
        this.ctx.fillRect(0, 0, window.innerWidth, window.innerHeight);

        /* Demarrer la boucle d'animation */
        var controller = this;
        this.rafId = requestAnimationFrame(function (ts) { controller.render(ts); });

        /* ── Gestion du redimensionnement de la fenetre ──────────────────────── */
        /*
         * Si l'utilisateur redimensionne la fenetre pendant le splash,
         * le canvas doit etre recompute (DPR, dimensions).
         * On utilise un delai de 100ms pour eviter de recalculer a chaque pixel.
         *
         * IMPORTANT : on stocke le handler dans this.resizeHandler pour pouvoir
         * le retirer dans hide(). Sans ce nettoyage, le handler resterait lie
         * a window indefiniment, gardant une reference a controller en memoire
         * (fuite memoire mineure mais reelle).
         */
        var resizeTimer = null;
        controller.resizeHandler = function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                controller.resize();
            }, 100);
        };
        window.addEventListener('resize', controller.resizeHandler);
    };

    /* ── Point d'entree — execution apres chargement du DOM ────────────────── */

    /*
     * On utilise DOMContentLoaded plutot que 'load' :
     *   - DOMContentLoaded se declenche quand le HTML est parse (DOM pret)
     *     mais AVANT que les images, CSS et JS externes soient tous charges.
     *   - 'load' se declenche quand TOUT est charge (images incluses).
     *   - Pour le preloader, on veut demarrer AUSSITOT que le DOM est pret,
     *     pas attendre que toutes les images soient chargees.
     *
     * Si le DOM est deja pret au moment ou ce script s'execute (cas 'defer'),
     * DOMContentLoaded ne se declenchera plus -> on verifie document.readyState.
     */
    function bootstrap() {
        var ctrl = new AnimationController();
        var ok   = ctrl.init();

        if (!ok) {
            /* Elements absents du DOM -> rien a faire */
            return;
        }

        ctrl.start();
    }

    if (document.readyState === 'loading') {
        /* Le DOM n'est pas encore pret -> on attend DOMContentLoaded */
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        /* Le DOM est deja pret (script charge apres le parsing) */
        bootstrap();
    }

}()); /* Fin de l'IIFE (Immediately Invoked Function Expression) */

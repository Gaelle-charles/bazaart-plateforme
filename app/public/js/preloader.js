/**
 * preloader.js -- Animation spirale + splash screen Bazaart
 *
 * PRINCIPE GENERAL :
 *   Ce script reimplemente fidelement en JS vanilla pur (sans GSAP, sans React,
 *   sans aucune dependance) le composant SpiralAnimation de la reference.
 *
 *   L'animation reproduit EXACTEMENT les constantes, le generateur aleatoire
 *   a graine fixe, les formules mathematiques, la projection 3D->2D, le trail
 *   et les 3 phases de la classe Star de la reference React/GSAP.
 *
 * REGLES D'AFFICHAGE :
 *   - Hard refresh (navigation.type === 'reload') OU premiere visite de la session
 *     -> afficher le splash
 *   - Navigation interne -> NE PAS afficher le splash
 *   - JS coupe -> l'overlay reste invisible (opacity:0 par defaut en CSS) -> pas de blocage
 *   - prefers-reduced-motion -> aucune animation, logo bref, puis disparition
 *
 * DUREE TOTALE VISIBLE :
 *   Le splash s'affiche pendant SPLASH_DURATION ms, puis fondu de sortie 0.5s.
 *   La variable time avance de 0 a 1 en 15 000 ms (vitesse identique a la ref GSAP).
 *   On voit donc le debut fidele de la sequence spirale pendant SPLASH_DURATION ms.
 *
 * CANVAS CARRE :
 *   size = Math.max(innerWidth, innerHeight)
 *   Le canvas est size x size en backing store (x DPR), centre par CSS.
 *   Cela correspond exactement a la reference (pas de deformation sur ecrans larges).
 *
 * DEVICEPIXELRATIO :
 *   Le canvas est dimensionne en pixels physiques (DPR * size) pour
 *   un rendu net sur les ecrans Retina (DPR = 2 ou 3).
 */

(function () {
    'use strict';

    /* ======================================================================
     * CONSTANTES IDENTIQUES A LA REFERENCE REACT/GSAP
     * Reproduire exactement ces valeurs est essentiel pour la fidelite visuelle.
     * ====================================================================== */

    /**
     * SPLASH_DURATION : duree d'affichage du splash en millisecondes.
     * La spirale tourne pendant ce temps (time avance a la vitesse ref).
     * Ajustable pour calibrer la duree du preloader sans modifier la vitesse
     * de l'animation (les deux sont independants).
     * Valeur par defaut : 5000 ms = 5 secondes.
     */
    var SPLASH_DURATION = 5000;

    /* Duree du fondu de sortie (ms) -- doit correspondre a la transition CSS */
    var FADE_OUT_MS = 500;

    /* Fraction du temps total ou le point de depart change de direction (phase 2 begin) */
    var changeEventTime = 0.32;

    /* Position Z initiale de la camera (valeur negative = devant la scene) */
    var cameraZ = -400;

    /* Distance totale parcourue par la camera sur l'axe Z pendant l'animation */
    var cameraTravelDistance = 3400;

    /* Decalage vertical du point de depart de la spirale par rapport au centre */
    var startDotYOffset = 28;

    /* Facteur de zoom de la projection 3D (controle l'ampleur de la perspective) */
    var viewZoom = 100;

    /* Nombre total d'etoiles dans la scene */
    var numberOfStars = 5000;

    /* Nombre de segments dans la trainee lumineuse derriere le point central */
    var trailLength = 80;

    /* ======================================================================
     * GENERATEUR ALEATOIRE A GRAINE FIXE (seed = 1234)
     *
     * La reference utilise ce generateur pendant la creation des etoiles pour
     * garantir une DISTRIBUTION DETERMINISTE. Cela signifie que la position de
     * chaque etoile est IDENTIQUE a chaque chargement de page, ce qui est cle
     * pour matcher le visuel de la reference.
     *
     * Algorithme : generateur lineaire congruentiel (LCG) classique.
     *   seed = (seed * 9301 + 49297) % 233280
     *   valeur = seed / 233280  (retourne un float dans [0, 1[)
     *
     * ATTENTION AU BUG DE LA REFERENCE :
     *   Dans la ref originale, createStars() est appelee deux fois, ce qui
     *   avance le generateur deux fois et produit un ensemble d'etoiles different
     *   de ce qu'on obtiendrait en l'appelant une seule fois.
     *   INSTRUCTION : creer les etoiles UNE SEULE FOIS avec le RNG a graine.
     *   On reproduit ainsi la seed de depart mais sans le doublon.
     * ====================================================================== */

    /**
     * setupRandomGenerator : installe temporairement un Math.random personnalise.
     * Appeler endRandomGenerator() pour restaurer le Math.random natif.
     *
     * @returns {{ end: function }} -- objet avec methode end() pour restaurer
     */
    function setupRandomGenerator() {
        /* Graine initiale -- identique a la reference */
        var seed = 1234;

        /* Sauvegarde du Math.random natif pour le restaurer ensuite */
        var nativeRandom = Math.random;

        /* Remplacement de Math.random par le LCG a graine */
        Math.random = function () {
            /* Etape LCG : avance la graine */
            seed = (seed * 9301 + 49297) % 233280;
            /* Normalise dans [0, 1[ */
            return seed / 233280;
        };

        return {
            end: function () {
                /* Restaure Math.random natif -- important pour le reste du code */
                Math.random = nativeRandom;
            }
        };
    }

    /* ======================================================================
     * UTILITAIRES MATHEMATIQUES -- reproduits exactement de la reference
     * ====================================================================== */

    /**
     * ease(p, g) : easing generique a puissance.
     *
     * Courbe : accelere au debut (comportement "ease-in" si g > 1).
     * Utilise pour le mouvement de la camera (newCameraZ) et la rotation globale.
     *
     * @param {number} p - valeur normalisee [0, 1]
     * @param {number} g - exposant (gamma) : > 1 = ease-in, 1 = lineaire
     * @returns {number}
     */
    function ease(p, g) {
        return Math.pow(p, g);
    }

    /**
     * easeOutElastic(x) : rebond elastique en sortie.
     *
     * Depasse brievement 1 puis revient -- effet "ressort".
     * Utilise dans la classe Star (sizeMultiplier, blend phases).
     *
     * @param {number} x - valeur normalisee [0, 1]
     * @returns {number}
     */
    function easeOutElastic(x) {
        var c4 = (2 * Math.PI) / 3;
        if (x === 0) return 0;
        if (x === 1) return 1;
        return Math.pow(2, -10 * x) * Math.sin((x * 10 - 0.75) * c4) + 1;
    }

    /**
     * map(value, low1, high1, low2, high2) : remapping lineaire.
     *
     * Transforme `value` qui vit dans [low1, high1] vers un equivalent dans [low2, high2].
     * Identique a la fonction map() de Processing/p5.js.
     *
     * @param {number} value
     * @param {number} low1
     * @param {number} high1
     * @param {number} low2
     * @param {number} high2
     * @returns {number}
     */
    function map(value, low1, high1, low2, high2) {
        return low2 + (high2 - low2) * ((value - low1) / (high1 - low1));
    }

    /**
     * constrain(value, min, max) : clamp.
     *
     * Limite `value` entre `min` et `max`.
     *
     * @param {number} value
     * @param {number} min
     * @param {number} max
     * @returns {number}
     */
    function constrain(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }

    /**
     * lerp(a, b, t) : interpolation lineaire.
     *
     * t=0 -> a, t=1 -> b, valeurs intermediaires : lineaire.
     *
     * @param {number} a
     * @param {number} b
     * @param {number} t - facteur [0, 1]
     * @returns {number}
     */
    function lerp(a, b, t) {
        return a + (b - a) * t;
    }

    /**
     * spiralPath(p) : position 2D du point courant de la spirale.
     *
     * La spirale fait 6 tours complets (theta = 2*PI*6*p).
     * Le rayon grandit proportionnellement a sqrt(p) (spirale d'Archimede modifiee).
     * Le point de depart est decale vers le bas de startDotYOffset.
     *
     * @param {number} p - progression [0, 1]
     * @returns {{ x: number, y: number }}
     */
    function spiralPath(p) {
        var r = 170 * Math.sqrt(p);
        var theta = p * Math.PI * 2 * 6; /* 6 tours */
        return {
            x: Math.cos(theta) * r,
            y: Math.sin(theta) * r + startDotYOffset
        };
    }

    /**
     * rotate(v1, v2, p, orientation) : interpolation de position avec rebond.
     *
     * Interpole entre les positions v1 et v2 avec un easing cubique (ease(p,3))
     * et un leger rebond elastique. L'orientation (+1 ou -1) controle le sens
     * du deplacement lateral.
     *
     * Utilise pour calculer la position des segments du trail (drawTrail).
     *
     * @param {{ x: number, y: number }} v1 - position de depart
     * @param {{ x: number, y: number }} v2 - position d'arrivee
     * @param {number} p - progression [0, 1]
     * @param {number} orientation - +1 ou -1 (sens du rebond lateral)
     * @returns {{ x: number, y: number }}
     */
    function rotate(v1, v2, p, orientation) {
        /* Easing cubique de base */
        var t = ease(p, 3);
        /* Rebond elastique attenue : easeOutElastic reduit a 15% pour un effet subtil */
        var bounce = easeOutElastic(p) * 0.15;
        return {
            x: lerp(v1.x, v2.x, t) + orientation * bounce * (v2.x - v1.x),
            y: lerp(v1.y, v2.y, t) + orientation * bounce * (v2.y - v1.y)
        };
    }

    /* ======================================================================
     * CLASSE STAR
     *
     * Represente une etoile individuelle dans l'espace 3D de la scene.
     * Chaque etoile a trois phases de mouvement distinctes identiques a la ref.
     *
     * PHASES :
     *   Phase 1 (t de 0 a 0.30) : convergence depuis position aleatoire
     *   Phase 2 (t de 0.30 a 0.70) : suivi de trajectoire helicoide (deplacement z)
     *   Phase 3 (t de 0.70 a 1.00) : expansion et disparition
     *
     * Deplacements : 30% / 40% / 30% de la duree totale.
     *
     * UTILISATION DU RNG :
     *   Le constructeur doit etre appele APRES setupRandomGenerator() et AVANT end().
     *   Toutes les valeurs aleatoires de chaque etoile sont ainsi determinees
     *   par la graine fixe -> distribution identique a chaque chargement.
     * ====================================================================== */

    /**
     * Constructeur Star.
     *
     * @param {number} totalStars - nombre total d'etoiles (pour le placement initial)
     */
    function Star(totalStars) {
        /* Position initiale aleatoire dans l'espace 3D (point de depart phase 1) */
        this.x = (Math.random() - 0.5) * 2000;
        this.y = (Math.random() - 0.5) * 2000;
        this.z = (Math.random() - 0.5) * 2000;

        /* Facteur de poids visuel (epaisseur du trait) -- variant par etoile */
        this.strokeWeightFactor = Math.random() * 0.8 + 0.6;

        /* dotSize de base identique a la reference */
        this.dotSize = 8.5 * this.strokeWeightFactor;

        /* Identifiant de phase propre a cette etoile :
         * decalage aleatoire [0, 1[ qui repartit les etoiles dans le temps */
        this.phaseId = Math.random();

        /* Orientation du rebond lateral dans rotate() : alternance +1/-1 */
        this.orientation = Math.random() > 0.5 ? 1 : -1;

        /* Position cible finale determinee aleatoirement (point de destination phase 3) */
        this.targetX = (Math.random() - 0.5) * 2000;
        this.targetY = (Math.random() - 0.5) * 2000;
        this.targetZ = (Math.random() - 0.5) * 2000;
    }

    /**
     * getPosition(t1, t2) : calcule la position 3D et le facteur de taille
     * de cette etoile en fonction des deux temps de progression.
     *
     * t1 : progression phase spirale (0->1 entre 0 et changeEventTime+0.25)
     * t2 : progression phase camera (0->1 entre changeEventTime et 1)
     *
     * PHASES :
     *   - t1 < 0.3  (phase 1) : convergence lineaire depuis position initiale
     *   - t1 < 0.7  (phase 2) : deplacement sur l'axe Z avec blend power/elastic
     *   - t1 >= 0.7 (phase 3) : expansion vers position cible
     *
     * @param {number} t1 - progression phase 1 (0->1)
     * @param {number} t2 - progression phase 2 / camera (0->1)
     * @returns {{ x: number, y: number, z: number, sizeMultiplier: number }}
     */
    Star.prototype.getPosition = function (t1, t2) {
        var x, y, z, sizeMultiplier;

        if (t1 < 0.3) {
            /* ---- Phase 1 : convergence depuis la position initiale ---------- */
            /* p = progression locale dans cette phase (0->1) */
            var p1 = t1 / 0.3;

            /* Interpolation lineaire vers (0, 0, 0) -- centre de la scene */
            x = lerp(this.x, 0, p1);
            y = lerp(this.y, 0, p1);
            z = lerp(this.z, 0, p1);

            /* La taille grandit progressivement depuis 0 */
            sizeMultiplier = p1;

        } else if (t1 < 0.7) {
            /* ---- Phase 2 : deplacement helicoide sur l'axe Z ---------------- */
            /* p2 = progression locale (0->1), centree sur la phase 2 */
            var p2 = (t1 - 0.3) / 0.4;

            /* Position en X/Y : oscillation autour de 0 (etoiles "flottent") */
            x = Math.sin(p2 * Math.PI * 2 * 3 + this.phaseId * Math.PI * 2) * 100;
            y = Math.cos(p2 * Math.PI * 2 * 3 + this.phaseId * Math.PI * 2) * 100;

            /* Deplacement en Z : les etoiles avancent vers la camera */
            z = lerp(0, -cameraTravelDistance * 0.6, ease(p2, 1.2));

            /* Blend entre easing power et elastique : effect "pulse" de taille */
            var powerBlend = ease(p2, 2);
            var elasticBlend = easeOutElastic(p2);
            sizeMultiplier = lerp(powerBlend, elasticBlend, 0.3) + 0.5;

        } else {
            /* ---- Phase 3 : expansion vers position cible -------------------- */
            var p3 = (t1 - 0.7) / 0.3;

            /* Les etoiles s'eloignent rapidement vers leur position cible */
            x = lerp(0, this.targetX, ease(p3, 2));
            y = lerp(0, this.targetY, ease(p3, 2));
            z = lerp(-cameraTravelDistance * 0.6, this.targetZ, ease(p3, 1.5));

            /* La taille diminue et disparait */
            sizeMultiplier = lerp(1.5, 0, ease(p3, 1.5));
        }

        return { x: x, y: y, z: z, sizeMultiplier: sizeMultiplier };
    };

    /* ======================================================================
     * ANIMATION CONTROLLER
     *
     * Gere le cycle de vie complet de l'animation :
     *   1. init()         : recupere les elements DOM, calibre le canvas
     *   2. createStars()  : instancie les etoiles avec le RNG a graine fixe
     *   3. start()        : verifie si le splash doit s'afficher, lance la boucle
     *   4. render()       : dessin a chaque frame (boucle requestAnimationFrame)
     *   5. showProjectedDot() : projection 3D->2D + dessin d'un point
     *   6. drawStartDot() : point de depart de la spirale (halo + cercle)
     *   7. drawTrail()    : trainee de 80 segments derriere le point central
     *   8. finish()       : declenche le fondu de sortie de l'overlay
     *   9. hide()         : masque definitivement l'overlay + nettoyage
     * ====================================================================== */

    function AnimationController() {
        /* Elements DOM */
        this.overlay = null;
        this.canvas  = null;
        this.logo    = null;
        this.ctx     = null;

        /* Taille du cote du canvas carre en pixels CSS */
        this.size    = 0;
        /* DevicePixelRatio courant */
        this.dpr     = 1;

        /* Tableau des etoiles */
        this.stars = [];

        /* Progression de l'animation : de 0 a 1 sur 15 000 ms */
        this.time = 0;

        /* Timestamp de la premiere frame (fixe par rAF) */
        this.startTime = null;

        /* Identifiant du rAF courant (pour annulation) */
        this.rafId = null;

        /* Indique si le fondu de sortie a deja ete declenche */
        this.finishing = false;

        /* Handler resize stocke pour nettoyage dans hide() */
        this.resizeHandler = null;
    }

    /**
     * init() : initialise les elements DOM et calibre le canvas.
     * @returns {boolean} false si un element est manquant
     */
    AnimationController.prototype.init = function () {
        this.overlay = document.getElementById('bzrt-preloader');
        this.canvas  = document.getElementById('bzrt-preloader-canvas');
        this.logo    = document.getElementById('bzrt-preloader-logo');

        if (!this.overlay || !this.canvas || !this.logo) {
            return false;
        }

        this.ctx = this.canvas.getContext('2d');
        if (!this.ctx) return false;

        /* Calibrage initial */
        this.resize();

        return true;
    };

    /**
     * createStars() : instancie toutes les etoiles avec le RNG a graine fixe.
     *
     * IMPORTANT : setupRandomGenerator() remplace Math.random pendant toute
     * cette methode. Le RNG est restaure a la fin via rng.end().
     * La distribution est ainsi 100% deterministe = identique a chaque page load.
     *
     * On cree les etoiles UNE SEULE FOIS (bug original : 2 appels -> on n'en fait qu'un).
     */
    AnimationController.prototype.createStars = function () {
        /* Installation du RNG a graine fixe */
        var rng = setupRandomGenerator();

        this.stars = [];
        for (var i = 0; i < numberOfStars; i++) {
            this.stars.push(new Star(numberOfStars));
        }

        /* Restauration de Math.random natif IMMEDIATEMENT apres la creation */
        rng.end();
    };

    /**
     * resize() : adapte la taille du canvas a la fenetre.
     *
     * Le canvas est CARRE (size = max(innerWidth, innerHeight)) pour
     * correspondre exactement a la reference. Le CSS centre le canvas
     * avec position:absolute + transform translate(-50%,-50%).
     *
     * DPR : canvas.width/height = size * DPR (pixels physiques nets).
     * ctx.setTransform() : corrige l'echelle pour travailler en pixels CSS.
     */
    AnimationController.prototype.resize = function () {
        this.dpr  = window.devicePixelRatio || 1;
        /* Canvas carre : max des deux dimensions de la fenetre */
        this.size = Math.max(window.innerWidth, window.innerHeight);

        /* Dimensions du backing store en pixels physiques */
        this.canvas.width  = this.size * this.dpr;
        this.canvas.height = this.size * this.dpr;

        /* Le canvas occupe exactement `size` x `size` pixels CSS.
         * position:absolute avec left:50%/top:50% + translate(-50%,-50%)
         * le centre sur l'ecran (voir preloader.css). */
        this.canvas.style.width  = this.size + 'px';
        this.canvas.style.height = this.size + 'px';

        /* On travaille en coordonnees CSS (1 unite = 1px CSS, pas physique) */
        this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    };

    /**
     * showProjectedDot(ctx, position, sizeFactor) : projette un point 3D sur le canvas 2D.
     *
     * Formule de projection identique a la reference :
     *   newCameraZ = cameraZ + ease(pow(t2, 1.2), 1.8) * cameraTravelDistance
     *   depth = position.z - newCameraZ
     *   x2d   = viewZoom * position.x / depth   (+ translation au centre)
     *   y2d   = viewZoom * position.y / depth
     *   sw    = 400 * sizeFactor / depth         (epaisseur / rayon)
     *
     * NB : dans la reference, la projection est calculee avec t2 courant.
     * t2 est passe en parametre via le contexte de render().
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {{ x: number, y: number, z: number }} position - position 3D
     * @param {number} sizeFactor - facteur de taille (sizeMultiplier de l'etoile)
     * @param {number} t2 - progression de la phase camera (0->1)
     */
    AnimationController.prototype.showProjectedDot = function (ctx, position, sizeFactor, t2) {
        /* Calcul de la position Z courante de la camera */
        var newCameraZ = cameraZ + ease(Math.pow(t2, 1.2), 1.8) * cameraTravelDistance;

        /* Profondeur : distance entre le point et la camera */
        var depth = position.z - newCameraZ;

        /* On ne dessine pas les points derriere la camera (depth <= 0) */
        if (depth <= 0) return;

        /* Projection 3D -> 2D (coordonnees relatives au centre du canvas) */
        var x2d = viewZoom * position.x / depth;
        var y2d = viewZoom * position.y / depth;

        /* Rayon du point projete (sw = strokeWeight) */
        var sw = 400 * sizeFactor / depth;

        /* On ne dessine pas les points trop petits (optimisation + proprete) */
        if (sw < 0.1) return;

        /* Dessin du cercle plein blanc */
        ctx.beginPath();
        ctx.arc(x2d, y2d, sw * 0.5, 0, Math.PI * 2);
        ctx.fill();
    };

    /**
     * drawStartDot(ctx) : dessine le point de depart de la spirale.
     *
     * Ce point suit la trajectoire spiralPath() et est toujours au centre
     * (coordonnees relatives au centre du canvas, apres translate(size/2, size/2)).
     * Il est visible en permanence pendant la phase 1 (t1 faible).
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {number} t1 - progression de la spirale (0->1)
     */
    AnimationController.prototype.drawStartDot = function (ctx, t1) {
        /* Position du point sur la spirale */
        var pos = spiralPath(t1);

        /* Halo lumineux gradient radial autour du point */
        var grad = ctx.createRadialGradient(pos.x, pos.y, 0, pos.x, pos.y, 16);
        grad.addColorStop(0,   'rgba(255,255,255,0.9)');
        grad.addColorStop(0.4, 'rgba(255,255,255,0.3)');
        grad.addColorStop(1,   'rgba(255,255,255,0)');

        ctx.beginPath();
        ctx.arc(pos.x, pos.y, 16, 0, Math.PI * 2);
        ctx.fillStyle = grad;
        ctx.fill();

        /* Point central opaque */
        ctx.beginPath();
        ctx.arc(pos.x, pos.y, 2.5, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,0.95)';
        ctx.fill();
    };

    /**
     * drawTrail(ctx, t1) : dessine la trainee de 80 segments derriere le point central.
     *
     * La trainee interpole entre les positions successives de la spirale en utilisant
     * la fonction rotate() pour creer un effet de courbure avec rebond.
     * Chaque segment est plus fin et plus transparent que le precedent.
     *
     * Le nombre de segments est exactement trailLength = 80 (identique a la ref).
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {number} t1 - progression de la spirale (0->1)
     */
    AnimationController.prototype.drawTrail = function (ctx, t1) {
        if (t1 <= 0) return;

        /* Position courante du point (bout de la trainee) */
        var current = spiralPath(t1);

        for (var i = 1; i <= trailLength; i++) {
            /* Progression relative dans le trail : 0 (bout) -> 1 (queue) */
            var trailT = i / trailLength;

            /* Position de ce segment : on remonte la spirale dans le passe */
            var pastT = Math.max(0, t1 - trailT * t1);
            var past  = spiralPath(pastT);

            /* Interpolation avec rebond via rotate() */
            var pos = rotate(current, past, trailT, this._trailOrientation || 1);

            /* Opacite et epaisseur decroissantes avec la distance */
            var alpha     = (1 - trailT) * 0.6;
            var lineWidth = (1 - trailT) * 3.0;

            if (alpha < 0.01 || lineWidth < 0.05) continue;

            /* Dessin du segment comme un petit arc */
            ctx.beginPath();
            ctx.arc(pos.x, pos.y, lineWidth * 0.5, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,255,255,' + alpha.toFixed(3) + ')';
            ctx.fill();
        }
    };

    /**
     * render(timestamp) : boucle principale de rendu.
     *
     * Appelee a chaque frame par requestAnimationFrame.
     *
     * VITESSE : time avance de 0 a 1 en 15 000 ms (identique a la ref GSAP).
     *   time += dt / 15000
     *
     * CALCUL DE t1 ET t2 (identique a la reference) :
     *   t1 = constrain(map(time, 0, changeEventTime + 0.25, 0, 1), 0, 1)
     *        -> progression de la spirale (phase 1)
     *   t2 = constrain(map(time, changeEventTime, 1, 0, 1), 0, 1)
     *        -> progression de la camera (phase 2 + vol)
     *
     * FOND NOIR : fillRect plein (pas de trail alpha partiel) -> efface proprement.
     * ROTATION GLOBALE : rotate(-PI * ease(t2, 2.7)) -> vrille autour du centre.
     * ORDRE DE DESSIN : trail -> etoiles -> point de depart.
     *
     * @param {number} timestamp - timestamp fourni par rAF en ms
     */
    AnimationController.prototype.render = function (timestamp) {
        /* Enregistrement du debut a la premiere frame */
        if (this.startTime === null) {
            this.startTime = timestamp;
        }

        /* Delta time en ms depuis la frame precedente */
        var dt = (this.lastTimestamp !== undefined)
            ? timestamp - this.lastTimestamp
            : 16; /* valeur par defaut pour la premiere frame */
        this.lastTimestamp = timestamp;

        /* Avancement de time : 0 a 1 en 15 000 ms (vitesse identique a la ref GSAP) */
        this.time = Math.min(this.time + dt / 15000, 1);

        /* Calcul des deux progressions derivees de time, identiques a la reference */
        var t1 = constrain(map(this.time, 0, changeEventTime + 0.25, 0, 1), 0, 1);
        var t2 = constrain(map(this.time, changeEventTime, 1, 0, 1), 0, 1);

        var ctx  = this.ctx;
        var size = this.size;

        /* ---- Fond noir total --------------------------------------------- */
        /* fillRect sur toute la surface du canvas -> efface la frame precedente */
        ctx.fillStyle = '#000000';
        ctx.fillRect(0, 0, size, size);

        /* ---- Sauvegarde + centrage + rotation globale -------------------- */
        ctx.save();

        /* On centre le repere au milieu du canvas carre */
        ctx.translate(size / 2, size / 2);

        /* Rotation globale de la scene : vrille autour du centre pendant t2
         * La vitesse de rotation est identique a la reference :
         * -PI * ease(t2, 2.7) = une demi-revolution avec acceleration cubique */
        ctx.rotate(-Math.PI * ease(t2, 2.7));

        /* ---- Dessin de la trainee ---------------------------------------- */
        ctx.fillStyle = 'white';
        this.drawTrail(ctx, t1);

        /* ---- Dessin des etoiles ------------------------------------------ */
        ctx.fillStyle = 'white';
        for (var i = 0; i < this.stars.length; i++) {
            var pos3d = this.stars[i].getPosition(t1, t2);
            var sm    = pos3d.sizeMultiplier * this.stars[i].strokeWeightFactor;
            if (sm <= 0) continue;
            this.showProjectedDot(ctx, pos3d, sm, t2);
        }

        /* ---- Dessin du point de depart de la spirale --------------------- */
        ctx.fillStyle = 'white';
        this.drawStartDot(ctx, t1);

        /* ---- Restauration du contexte ------------------------------------ */
        ctx.restore();

        /* ---- Animation du logo en fondu progressif ----------------------- */
        /*
         * Le logo commence a apparaitre progressivement apres le debut de l'animation.
         * Il atteint opacity:1 vers t=0.25 (25% des 15 secondes = ~3.75s).
         * On le rend visible tot pour qu'il soit deja bien visible pendant SPLASH_DURATION.
         */
        var logoAlpha = constrain(map(this.time, 0.05, 0.25, 0, 1), 0, 1);
        this.logo.style.opacity = logoAlpha;

        /* ---- Continuer la boucle ----------------------------------------- */
        /* La boucle continue indeterminement ; c'est le timeout SPLASH_DURATION
         * qui declenche finish() independamment de time. */
        var self = this;
        this.rafId = requestAnimationFrame(function (ts) { self.render(ts); });
    };

    /**
     * finish() : declenche la disparition de l'overlay.
     *
     * Annule la boucle rAF, assure le logo visible, retire .is-active
     * pour que la transition CSS opacity 0.5s s'applique.
     */
    AnimationController.prototype.finish = function () {
        /* Protection contre les appels multiples */
        if (this.finishing) return;
        this.finishing = true;

        /* Arret de la boucle d'animation */
        if (this.rafId !== null) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }

        /* S'assure que le logo est pleinement visible avant le fondu */
        this.logo.style.opacity = 1;

        /* Retire .is-active -> la transition CSS opacity:0.5s demarre */
        this.overlay.classList.remove('is-active');

        /* Masquage definitif apres le fondu */
        var self = this;
        setTimeout(function () {
            self.hide();
        }, FADE_OUT_MS + 50);
    };

    /**
     * hide() : masque definitivement l'overlay + nettoyage memoire.
     *
     * display:none retire l'element du flux de rendu (plus de GPU utilise).
     * removeEventListener evite la fuite memoire du handler resize.
     */
    AnimationController.prototype.hide = function () {
        /* Nettoyage du handler resize (previent fuite memoire) */
        if (this.resizeHandler) {
            window.removeEventListener('resize', this.resizeHandler);
            this.resizeHandler = null;
        }

        this.overlay.style.display     = 'none';
        this.overlay.style.pointerEvents = 'none';
    };

    /**
     * start() : demarre l'animation ou masque l'overlay selon les conditions.
     *
     * CONDITIONS D'AFFICHAGE :
     *   - Hard refresh (type === 'reload') OU premiere visite de la session
     *     -> affiche le splash et lance la boucle rAF
     *   - Navigation interne (lien, Turbo...)
     *     -> masque immediatement, sans animation
     *   - prefers-reduced-motion
     *     -> affiche logo brievement (400ms), pas d'animation spirale
     */
    AnimationController.prototype.start = function () {
        /* ---- Verification : doit-on afficher le splash ? ----------------
         *
         * performance.getEntriesByType('navigation')[0].type :
         *   'reload'      -> hard refresh -> splash
         *   'navigate'    -> premiere arrivee ou lien externe -> splash si !seen
         *   'back_forward'-> bouton retour/avant -> pas de splash
         */
        var navEntries  = performance.getEntriesByType('navigation');
        var nav         = navEntries && navEntries.length > 0 ? navEntries[0] : null;
        var isReload    = nav && nav.type === 'reload';
        var firstArrival = !sessionStorage.getItem('bzrt_splash_seen');

        if (!isReload && !firstArrival) {
            /* Navigation interne -> masquage immediat */
            this.hide();
            return;
        }

        /* Marque la session pour ne pas re-afficher le splash sur les pages suivantes */
        sessionStorage.setItem('bzrt_splash_seen', '1');

        /* ---- Cas : prefers-reduced-motion --------------------------------
         *
         * Respecte la preference systeme "Reduire les animations".
         * On affiche juste le logo brievement, sans aucun mouvement.
         */
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.overlay.classList.add('is-active');
            var selfReduced = this;
            setTimeout(function () {
                selfReduced.hide();
            }, 400);
            return;
        }

        /* ---- Cas normal : animation spirale ------------------------------ */

        /* Creation des etoiles avec le RNG a graine fixe */
        this.createStars();

        /* Affichage instantane de l'overlay (is-active supprime la transition d'entree) */
        this.overlay.classList.add('is-active');

        /* Effacement initial du canvas (fond noir pur) */
        this.ctx.fillStyle = '#000000';
        this.ctx.fillRect(0, 0, this.size, this.size);

        /* Demarrage de la boucle rAF */
        var self = this;
        this.rafId = requestAnimationFrame(function (ts) { self.render(ts); });

        /* ---- Declenchement du fondu de sortie apres SPLASH_DURATION -------
         *
         * SPLASH_DURATION (5000 ms par defaut) est independant de la vitesse
         * de l'animation (15 000 ms pour un cycle complet).
         * On voit donc les ~33% premiers de la sequence spirale pendant le splash.
         * Pour voir plus : augmenter SPLASH_DURATION.
         */
        setTimeout(function () {
            self.finish();
        }, SPLASH_DURATION);

        /* ---- Gestion du redimensionnement -------------------------------- */
        var resizeTimer = null;
        self.resizeHandler = function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                self.resize();
            }, 100);
        };
        window.addEventListener('resize', self.resizeHandler);
    };

    /* ======================================================================
     * POINT D'ENTREE
     *
     * DOMContentLoaded plutot que 'load' : on demarre des que le DOM est pret,
     * sans attendre les images. Le script est charge avec defer -> le DOM est
     * garanti pret, mais on double-check readyState pour etre robuste.
     * ====================================================================== */

    function bootstrap() {
        var ctrl = new AnimationController();
        if (!ctrl.init()) {
            /* Elements absents du DOM (page sans base.html.twig) -> rien a faire */
            return;
        }
        ctrl.start();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        /* Script execute apres le parsing -> DOM deja pret */
        bootstrap();
    }

}()); /* Fin IIFE (scope isole, pas de pollution du scope global) */

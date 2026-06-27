/*
 * preloader.js — Splash screen Bazaart : animation spirale + logo blanc.
 * =====================================================================
 * PORT FIDÈLE (vanilla JS, zéro dépendance) du composant React/GSAP
 * « SpiralAnimation » fourni par Gaëlle. On reproduit À L'IDENTIQUE :
 *   - les constantes, le RNG à graine fixe (1234),
 *   - la DOUBLE création d'étoiles du constructeur de référence
 *     (setupRandomGenerator() crée 5000 étoiles à graine, puis createStars()
 *      en recrée 5000 avec Math.random -> 10000 étoiles au total),
 *   - les fonctions ease / easeOutElastic (c4 = 2π/4.5) / spiralPath / rotate /
 *     showProjectedDot / drawTrail / Star (3 phases) / render,
 *   - la vitesse GSAP (time 0->1 en 15 s),
 *   - le canvas CARRÉ (size = max(W,H)) ÉTIRÉ sur tout le viewport
 *     (comme la réf : canvas.style = viewport, backing = carré).
 *
 * Seuls écarts volontaires (splash, pas page d'accueil React) :
 *   - le « Enter » est remplacé par le LOGO Bazaart blanc qui apparaît en
 *     fondu après LOGO_DELAY (comme le bouton Enter de la démo : 2 s),
 *   - le splash s'auto-ferme après SPLASH_DURATION puis fondu de sortie,
 *   - affiché uniquement à l'arrivée / hard refresh (pas en navigation interne),
 *   - invisible si JS coupé, respect de prefers-reduced-motion.
 */
(function () {
    'use strict';

    /* ── Paramètres ajustables ─────────────────────────────────────────────── */
    /* ⚠️ DISTINCTION IMPORTANTE entre les deux durées :
       - ANIM_DURATION = VITESSE de l'animation (temps pour que `time` aille de 0 à 1).
         On NE LA TOUCHE PAS : 9000 ms = la vitesse d'origine, naturelle, validée.
         La baisser accélérerait la spirale (effet « speed up » non voulu).
       - SPLASH_DURATION = DURÉE D'AFFICHAGE du splash à l'écran.
         C'est ELLE qu'on règle pour « raccourcir » le préloader : ici 5000 ms.
         L'animation joue donc à sa vitesse normale, et le splash se ferme au
         bout de 5 s (l'animation est simplement interrompue, pas accélérée). */
    var ANIM_DURATION   = 4500;  /* ms : VITESSE de l'animation (time 0->1). ~2x plus rapide qu'à l'origine. */
    /* Le splash se ferme EXACTEMENT quand l'animation se termine (puis fondu de sortie) :
       on couple donc la durée d'affichage à la durée de l'animation. À la demande de
       Gaëlle : « une fois l'animation terminée, on passe à la page home ». */
    var SPLASH_DURATION = ANIM_DURATION;  /* ms : fermeture dès la fin de l'animation */
    var LOGO_DELAY      = 2000;  /* ms : délai avant le DÉBUT de l'apparition du logo */
    /* Le fondu du logo doit se TERMINER exactement quand l'animation se termine
       (demande Gaëlle) : durée du fondu = ANIM_DURATION - LOGO_DELAY. Ainsi le logo est
       pleinement visible pile au moment où le splash se ferme et où l'on passe à la home. */
    var LOGO_FADE       = ANIM_DURATION - LOGO_DELAY;

    var overlay = document.getElementById('bzrt-preloader');
    var canvas  = document.getElementById('bzrt-preloader-canvas');
    var logo    = document.getElementById('bzrt-preloader-logo');
    if (!overlay || !canvas) { return; }

    /* ── Règle d'affichage : UNE SEULE FOIS, à la toute première session ─────────
       On utilise localStorage (PERSISTANT) : le splash ne s'affiche qu'au tout
       premier passage de l'utilisateur (première session). Ensuite, plus jamais :
       ni sur les reloads, ni sur les navigations internes, ni sur les sessions
       suivantes (la clé localStorage survit à la fermeture du navigateur).
       (Demande Gaëlle.) Pour le revoir : effacer la clé "bzrt_splash_seen". */
    var alreadySeen = false;
    try { alreadySeen = !!localStorage.getItem('bzrt_splash_seen'); } catch (e) {}
    if (alreadySeen) { return; } /* déjà vu une fois -> on ne le réaffiche jamais */
    try { localStorage.setItem('bzrt_splash_seen', '1'); } catch (e) {}

    overlay.classList.add('is-active');

    /* ── reduced-motion : pas d'animation, juste un bref affichage du logo ──── */
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) {
        if (logo) { logo.style.opacity = '1'; }
        window.setTimeout(hideOverlay, 900);
        return;
    }

    var ctx = canvas.getContext('2d');
    if (!ctx) { hideOverlay(); return; }

    /* ── Utilitaires mathématiques (identiques à la référence) ─────────────── */
    function ease(p, g) {
        if (p < 0.5) { return 0.5 * Math.pow(2 * p, g); }
        return 1 - 0.5 * Math.pow(2 * (1 - p), g);
    }
    function easeOutElastic(x) {
        var c4 = (2 * Math.PI) / 4.5;
        if (x <= 0) { return 0; }
        if (x >= 1) { return 1; }
        return Math.pow(2, -8 * x) * Math.sin((x * 8 - 0.75) * c4) + 1;
    }
    function map(value, a1, a2, b1, b2) { return b1 + (b2 - b1) * ((value - a1) / (a2 - a1)); }
    function constrain(v, mn, mx) { return Math.min(Math.max(v, mn), mx); }
    function lerp(a, b, t) { return a * (1 - t) + b * t; }
    function randRange(mn, mx) { return mn + Math.random() * (mx - mn); }

    /* ── Constantes (identiques à la référence) ────────────────────────────── */
    var changeEventTime      = 0.32;
    var cameraZ              = -400;
    var cameraTravelDistance = 3400;
    var startDotYOffset      = 28;
    var viewZoom             = 100;
    var numberOfStars        = 5000;
    var trailLength          = 80;

    var time = 0;       /* progression 0 -> 1 */
    var size = 1;       /* côté du canvas carré = max(W,H) */
    var stars = [];

    /* ── spiralPath / rotate / projection ──────────────────────────────────── */
    function spiralPath(p) {
        p = constrain(1.2 * p, 0, 1);
        p = ease(p, 1.8);
        var turns = 6;
        var theta = 2 * Math.PI * turns * Math.sqrt(p);
        var r = 170 * Math.sqrt(p);
        return { x: r * Math.cos(theta), y: r * Math.sin(theta) + startDotYOffset };
    }
    function rotate(v1, v2, p, orientation) {
        var mx = (v1.x + v2.x) / 2, my = (v1.y + v2.y) / 2;
        var dx = v1.x - mx, dy = v1.y - my;
        var angle = Math.atan2(dy, dx);
        var o = orientation ? -1 : 1;
        var r = Math.sqrt(dx * dx + dy * dy);
        var bounce = Math.sin(p * Math.PI) * 0.05 * (1 - p);
        var e = easeOutElastic(p);
        return {
            x: mx + r * (1 + bounce) * Math.cos(angle + o * Math.PI * e),
            y: my + r * (1 + bounce) * Math.sin(angle + o * Math.PI * e)
        };
    }
    function showProjectedDot(position, sizeFactor) {
        var t2 = constrain(map(time, changeEventTime, 1, 0, 1), 0, 1);
        var newCameraZ = cameraZ + ease(Math.pow(t2, 1.2), 1.8) * cameraTravelDistance;
        if (position.z > newCameraZ) {
            var depth = position.z - newCameraZ;
            var x = viewZoom * position.x / depth;
            var y = viewZoom * position.y / depth;
            var sw = 400 * sizeFactor / depth;
            ctx.lineWidth = sw;
            ctx.beginPath();
            ctx.arc(x, y, 0.5, 0, Math.PI * 2);
            ctx.fill();
        }
    }
    function drawStartDot() {
        if (time > changeEventTime) {
            var dy = cameraZ * startDotYOffset / viewZoom;
            showProjectedDot({ x: 0, y: dy, z: cameraTravelDistance }, 2.5);
        }
    }
    function drawTrail(t1) {
        for (var i = 0; i < trailLength; i++) {
            var f = map(i, 0, trailLength, 1.1, 0.1);
            var sw = (1.3 * (1 - t1) + 3.0 * Math.sin(Math.PI * t1)) * f;
            ctx.fillStyle = 'white';
            ctx.lineWidth = sw;
            var pathTime = t1 - 0.00015 * i;
            var position = spiralPath(pathTime);
            var offset = { x: position.x + 5, y: position.y + 5 };
            var rotated = rotate(position, offset, Math.sin(time * Math.PI * 2) * 0.5 + 0.5, i % 2 === 0);
            ctx.beginPath();
            ctx.arc(rotated.x, rotated.y, sw / 2, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    /* ── Étoile (constructeur + render, identiques à la référence) ─────────── */
    function makeStar() {
        var s = {};
        s.angle = Math.random() * Math.PI * 2;
        s.distance = 30 * Math.random() + 15;
        s.rotationDirection = Math.random() > 0.5 ? 1 : -1;
        s.expansionRate = 1.2 + Math.random() * 0.8;
        s.finalScale = 0.7 + Math.random() * 0.6;
        s.dx = s.distance * Math.cos(s.angle);
        s.dy = s.distance * Math.sin(s.angle);
        s.spiralLocation = (1 - Math.pow(1 - Math.random(), 3.0)) / 1.3;
        s.z = randRange(0.5 * cameraZ, cameraTravelDistance + cameraZ);
        s.z = lerp(s.z, cameraTravelDistance / 2, 0.3 * s.spiralLocation);
        s.strokeWeightFactor = Math.pow(Math.random(), 2.0);
        return s;
    }
    function renderStar(s, p) {
        var spiralPos = spiralPath(s.spiralLocation);
        var q = p - s.spiralLocation;
        if (q <= 0) { return; }
        var dp = constrain(4 * q, 0, 1);

        var linearEasing = dp;
        var elasticEasing = easeOutElastic(dp);
        var powerEasing = Math.pow(dp, 2);
        var easing;
        if (dp < 0.3) { easing = lerp(linearEasing, powerEasing, dp / 0.3); }
        else if (dp < 0.7) { easing = lerp(powerEasing, elasticEasing, (dp - 0.3) / 0.4); }
        else { easing = elasticEasing; }

        var screenX, screenY;
        if (dp < 0.3) {
            screenX = lerp(spiralPos.x, spiralPos.x + s.dx * 0.3, easing / 0.3);
            screenY = lerp(spiralPos.y, spiralPos.y + s.dy * 0.3, easing / 0.3);
        } else if (dp < 0.7) {
            var midProgress = (dp - 0.3) / 0.4;
            var curveStrength = Math.sin(midProgress * Math.PI) * s.rotationDirection * 1.5;
            var baseX = spiralPos.x + s.dx * 0.3;
            var baseY = spiralPos.y + s.dy * 0.3;
            var targetX = spiralPos.x + s.dx * 0.7;
            var targetY = spiralPos.y + s.dy * 0.7;
            var perpX = -s.dy * 0.4 * curveStrength;
            var perpY = s.dx * 0.4 * curveStrength;
            screenX = lerp(baseX, targetX, midProgress) + perpX * midProgress;
            screenY = lerp(baseY, targetY, midProgress) + perpY * midProgress;
        } else {
            var finalProgress = (dp - 0.7) / 0.3;
            var bX = spiralPos.x + s.dx * 0.7;
            var bY = spiralPos.y + s.dy * 0.7;
            var targetDistance = s.distance * s.expansionRate * 1.5;
            var spiralAngle = s.angle + (1.2 * s.rotationDirection) * finalProgress * Math.PI;
            var tX = spiralPos.x + targetDistance * Math.cos(spiralAngle);
            var tY = spiralPos.y + targetDistance * Math.sin(spiralAngle);
            screenX = lerp(bX, tX, finalProgress);
            screenY = lerp(bY, tY, finalProgress);
        }

        var vx = (s.z - cameraZ) * screenX / viewZoom;
        var vy = (s.z - cameraZ) * screenY / viewZoom;

        var sizeMultiplier;
        if (dp < 0.6) { sizeMultiplier = 1.0 + dp * 0.2; }
        else { var t = (dp - 0.6) / 0.4; sizeMultiplier = 1.2 * (1.0 - t) + s.finalScale * t; }

        var dotSize = 8.5 * s.strokeWeightFactor * sizeMultiplier;
        showProjectedDot({ x: vx, y: vy, z: s.z }, dotSize);
    }

    /* ── Création des étoiles : DOUBLE création comme la référence ──────────── */
    function createStars() {
        for (var i = 0; i < numberOfStars; i++) { stars.push(makeStar()); }
    }
    function buildStars() {
        stars = [];
        /* 1) 5000 étoiles avec RNG à graine fixe (déterministe, identique à chaque chargement) */
        var orig = Math.random;
        var seed = 1234;
        Math.random = function () { seed = (seed * 9301 + 49297) % 233280; return seed / 233280; };
        createStars();
        Math.random = orig;
        /* 2) 5000 étoiles supplémentaires avec Math.random (comme le double appel de la réf) */
        createStars();
    }

    /* ── render() : identique à la référence ───────────────────────────────── */
    function render() {
        ctx.fillStyle = 'black';
        ctx.fillRect(0, 0, size, size);
        ctx.save();
        ctx.translate(size / 2, size / 2);
        var t1 = constrain(map(time, 0, changeEventTime + 0.25, 0, 1), 0, 1);
        var t2 = constrain(map(time, changeEventTime, 1, 0, 1), 0, 1);
        ctx.rotate(-Math.PI * ease(t2, 2.7));
        drawTrail(t1);
        ctx.fillStyle = 'white';
        for (var i = 0; i < stars.length; i++) { renderStar(stars[i], t1); }
        drawStartDot();
        ctx.restore();
    }

    /* ── Canvas : backing CARRÉ (size×size×dpr), affiché ÉTIRÉ sur le viewport ─ */
    var dpr = window.devicePixelRatio || 1;
    function resize() {
        dpr = window.devicePixelRatio || 1;
        size = Math.max(window.innerWidth, window.innerHeight);
        canvas.width = Math.round(size * dpr);
        canvas.height = Math.round(size * dpr);
        /* Le CSS (#bzrt-preloader-canvas : inset:0; width/height:100%) étire ce
           carré sur tout l'écran, exactement comme la réf (canvas.style = viewport). */
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0); /* équivaut à ctx.scale(dpr,dpr) */
    }
    var resizeHandler = function () { resize(); };
    window.addEventListener('resize', resizeHandler);

    resize();
    buildStars();

    /* ── Boucle d'animation : time 0->1 en ANIM_DURATION (vitesse GSAP) ─────── */
    var startTs = null;
    var rafId = null;
    var stopped = false;
    function frame(ts) {
        if (stopped) { return; }
        if (startTs === null) { startTs = ts; }
        var elapsed = ts - startTs;
        /* On joue l'animation UNE fois jusqu'à la fin (time clampé à 1, pas de boucle)
           pour que le splash montre la séquence complète avant de se fermer. */
        time = Math.min(elapsed / ANIM_DURATION, 1);
        render();
        rafId = window.requestAnimationFrame(frame);
    }
    rafId = window.requestAnimationFrame(frame);

    /* ── Logo : apparition en fondu après LOGO_DELAY (comme le bouton Enter) ── */
    if (logo) {
        window.setTimeout(function () {
            logo.style.transition = 'opacity ' + (LOGO_FADE / 1000) + 's ease';
            logo.style.opacity = '1';
        }, LOGO_DELAY);
    }

    /* ── Fermeture du splash ───────────────────────────────────────────────── */
    window.setTimeout(hideOverlay, SPLASH_DURATION);

    function hideOverlay() {
        stopped = true;
        if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; }
        window.removeEventListener('resize', resizeHandler);
        overlay.classList.remove('is-active'); /* déclenche le fondu CSS 0.5s */
        window.setTimeout(function () { overlay.style.display = 'none'; }, 600);
    }
}());

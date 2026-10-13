/**
 * association-projects.js : carrousel de la page « Projets en cours » (ADR-0039).
 *
 * Page : templates/association_projects/index.html.twig. Styles : css/association-projects.css.
 * JavaScript natif, sans bibliothèque. Chargé en « defer » : le HTML est déjà là.
 *
 * DEUX MODES (le HTML de départ fonctionne déjà sans ce fichier) :
 *   - MODE 3D (écran de 640 px et plus, animations autorisées) : la carte centrale
 *     est de face, ses voisines inclinées en retrait de chaque côté et superposées.
 *     Ce script calcule la position (transform) de chaque carte.
 *   - MODE DÉFILEMENT (mobile, ou système réglé sur « réduire les animations ») :
 *     rangée de cartes qui défile nativement (CSS scroll-snap), sans aucune rotation.
 *     Ce script ne fait que synchroniser l'indicateur.
 *
 * NAVIGATION :
 *   - flèches gauche / droite, Début / Fin (quand le focus est dans le carrousel) ;
 *   - glisser à la souris ou au doigt (mode 3D ; en mode défilement, c'est natif) ;
 *   - molette horizontale, ou Maj + molette (la molette verticale fait défiler la page) ;
 *   - clic sur une carte voisine : elle revient au centre ;
 *   - clic (ou Entrée) sur la carte centrale : ouverture de la fiche ;
 *   - clic sur un trait de l'indicateur.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-carousel]');
    if (!root) {
        return;
    }

    var viewport = root.querySelector('[data-viewport]');
    var track = root.querySelector('[data-track]');
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-card]'));
    var links = cards.map(function (card) { return card.querySelector('a'); });
    var ticks = Array.prototype.slice.call(root.querySelectorAll('[data-tick]'));
    var live = root.querySelector('[data-live]');
    var count = cards.length;
    if (!viewport || !track || count === 0) {
        return;
    }

    // ── Réglages de la mise en perspective (mode 3D) ──────────────────────────
    // Décalage horizontal, en largeur de carte : la 1re voisine se décale de 62 %
    // (elle chevauche donc la carte centrale), les suivantes de 40 % de plus.
    var NEAR_SHIFT = 0.62;
    var FAR_SHIFT = 0.40;
    var NEAR_ANGLE = 40;      // inclinaison des voisines, en degrés
    var NEAR_DEPTH = 170;     // recul de la 1re voisine, en pixels
    var FAR_DEPTH = 80;       // recul supplémentaire par carte au-delà
    var NEAR_SCALE = 0.94;    // légère réduction des voisines
    var VISIBLE_RANGE = 3.5;  // au-delà de 3 cartes et demie de distance : masquée
    var DRAG_THRESHOLD = 6;   // pixels à parcourir avant qu'un appui devienne un glisser

    var mqWide = window.matchMedia('(min-width: 640px)');
    var mqReduce = window.matchMedia('(prefers-reduced-motion: reduce)');

    var mode = null;          // '3d' ou 'scroll'
    var index = 0;            // carte centrale (entier)
    var position = 0;         // position affichée (décimale pendant un glisser)

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    // ── Rendu ─────────────────────────────────────────────────────────────────

    /**
     * Transformation d'une carte située à la distance d (en cartes) du centre.
     * d < 0 : à gauche, d > 0 : à droite. Les voisines pivotent de façon que leur
     * bord EXTÉRIEUR recule (comme dans la maquette) ; le recul (translateZ) les
     * fait paraître plus petites.
     */
    function transformFor(d, cardWidth) {
        var distance = Math.abs(d);
        var side = d < 0 ? -1 : 1;
        var near = Math.min(distance, 1);
        var far = Math.max(distance - 1, 0);

        var x = side * (near * NEAR_SHIFT + far * FAR_SHIFT) * cardWidth;
        var z = -(near * NEAR_DEPTH + far * FAR_DEPTH);
        var angle = side * near * NEAR_ANGLE;
        var scale = 1 - near * (1 - NEAR_SCALE);

        return 'translateX(' + x.toFixed(1) + 'px) translateZ(' + z.toFixed(1) + 'px) '
            + 'rotateY(' + angle.toFixed(2) + 'deg) scale(' + scale.toFixed(3) + ')';
    }

    function render() {
        var cardWidth = cards[0].offsetWidth;

        cards.forEach(function (card, i) {
            var d = i - position;
            var distance = Math.abs(d);

            if (mode === '3d') {
                card.style.transform = transformFor(d, cardWidth);
                // La carte la plus proche du centre passe devant les autres.
                card.style.zIndex = String(1000 - Math.round(distance * 10));
                card.style.opacity = distance > VISIBLE_RANGE ? '0' : '1';
                card.style.pointerEvents = distance > VISIBLE_RANGE ? 'none' : '';
            }

            var isActive = i === index;
            card.classList.toggle('is-active', isActive);
            if (isActive) {
                links[i].setAttribute('aria-current', 'true');
            } else {
                links[i].removeAttribute('aria-current');
            }
        });

        ticks.forEach(function (tick, i) {
            if (i === index) {
                tick.setAttribute('aria-current', 'true');
            } else {
                tick.removeAttribute('aria-current');
            }
        });
    }

    /** Message lu par les lecteurs d'écran quand la carte centrale change. */
    function announce() {
        if (!live) {
            return;
        }
        var title = cards[index].querySelector('.ap-card__title');
        live.textContent = 'Projet ' + (index + 1) + ' sur ' + count + ' : ' + (title ? title.textContent.trim() : '');
    }

    /** Mode défilement : fait défiler la rangée pour centrer la carte i. */
    function scrollToCard(i, smooth) {
        cards[i].scrollIntoView({
            behavior: smooth && !mqReduce.matches ? 'smooth' : 'auto',
            block: 'nearest',
            inline: 'center'
        });
    }

    /**
     * Amène la carte i au centre.
     * options.announce : prévenir les lecteurs d'écran (inutile quand le focus
     *                    se déplace avec, car la carte focalisée est déjà lue).
     */
    function goTo(i, options) {
        options = options || {};
        var target = clamp(Math.round(i), 0, count - 1);
        var changed = target !== index;

        index = target;
        position = target;
        render();

        if (mode === 'scroll' && options.scroll !== false) {
            scrollToCard(target, options.smooth !== false);
        }
        if (changed) {
            if (options.announce) {
                announce();
            }
            // On garde la carte dans l'adresse (#projet-…) : en revenant d'une fiche
            // avec le bouton « retour » du navigateur, le carrousel est au même endroit.
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#projet-' + cards[target].dataset.slug);
            }
        }
    }

    // ── Choix du mode ─────────────────────────────────────────────────────────

    /** Hauteurs du mode 3D : toutes les cartes à la hauteur de la plus haute. */
    function measure3d() {
        var tallest = 0;
        cards.forEach(function (card) {
            card.style.height = '';
            tallest = Math.max(tallest, card.offsetHeight);
        });
        cards.forEach(function (card) {
            card.style.height = tallest + 'px';
        });
        // Marge haute (carte) + marge basse (ombre décalée et perspective).
        track.style.setProperty('--ap-track-h', (tallest + 72) + 'px');
    }

    function setMode() {
        var next = mqWide.matches && !mqReduce.matches ? '3d' : 'scroll';
        if (next === mode) {
            if (mode === '3d') {
                measure3d();
                render();
            }
            return;
        }
        mode = next;
        root.classList.toggle('is-3d', mode === '3d');

        if (mode === '3d') {
            measure3d();
            position = index;
            render();
        } else {
            // Retour au défilement : on efface tout ce que le mode 3D avait posé.
            cards.forEach(function (card) {
                card.style.transform = '';
                card.style.zIndex = '';
                card.style.opacity = '';
                card.style.pointerEvents = '';
                card.style.height = '';
            });
            track.style.removeProperty('--ap-track-h');
            render();
            scrollToCard(index, false);
        }
    }

    function onMediaChange(query, handler) {
        if (query.addEventListener) {
            query.addEventListener('change', handler);
        } else if (query.addListener) {
            query.addListener(handler); // anciens Safari
        }
    }

    // ── Clavier ───────────────────────────────────────────────────────────────

    root.addEventListener('keydown', function (event) {
        dragMoved = false; // une touche n'est jamais la fin d'un glisser
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        var target;
        switch (event.key) {
            case 'ArrowRight': target = index + 1; break;
            case 'ArrowLeft': target = index - 1; break;
            case 'Home': target = 0; break;
            case 'End': target = count - 1; break;
            default: return;
        }
        event.preventDefault();
        target = clamp(target, 0, count - 1);

        var onTick = ticks.indexOf(document.activeElement) !== -1;
        goTo(target, { announce: false });
        // Le focus suit : sur la carte (ou sur le trait, si on était sur l'indicateur).
        if (onTick && ticks[target]) {
            ticks[target].focus();
        } else {
            links[target].focus({ preventScroll: true });
        }
    });

    // ── Focus, clic ───────────────────────────────────────────────────────────

    var pointerIsDown = false;        // vrai entre l'appui et le relâchement
    var centerAtPointerDown = null;   // carte centrale au moment de l'appui
    var dragMoved = false;            // le dernier appui était un glisser

    root.addEventListener('pointerdown', function () {
        pointerIsDown = true;
        centerAtPointerDown = index;
        dragMoved = false;
    }, true);
    document.addEventListener('pointerup', function () {
        pointerIsDown = false;
    }, true);

    links.forEach(function (link, i) {
        // Au clavier (Tab), une carte qui reçoit le focus passe au centre.
        // À la souris, le focus arrive AVANT le clic : on ne fait rien ici, c'est
        // le clic qui décide (sinon la voisine cliquée deviendrait centrale avant
        // le clic, qui ouvrirait alors sa fiche au lieu de la centrer).
        link.addEventListener('focus', function () {
            if (!pointerIsDown && i !== index) {
                goTo(i, { announce: false });
            }
        });

        link.addEventListener('click', function (event) {
            if (dragMoved) {
                event.preventDefault();
                return;
            }
            var center = centerAtPointerDown !== null ? centerAtPointerDown : index;
            centerAtPointerDown = null;
            if (i !== center) {
                // Carte voisine : on la ramène au centre, sans ouvrir la fiche.
                event.preventDefault();
                goTo(i, { announce: true });
            }
            // Carte centrale : le lien s'ouvre normalement (fiche du projet).
        });
    });

    // Après un glisser, le navigateur envoie quand même un « clic » : on l'annule.
    root.addEventListener('click', function (event) {
        if (dragMoved) {
            event.preventDefault();
            event.stopPropagation();
            dragMoved = false;
        }
    }, true);

    // Le navigateur ne doit pas lancer son propre « glisser-déposer » d'image ou de lien.
    track.addEventListener('dragstart', function (event) {
        event.preventDefault();
    });

    // Mode 3D : le cadre ne doit jamais défiler (un focus pourrait le décaler).
    viewport.addEventListener('scroll', function () {
        if (mode === '3d' && (viewport.scrollLeft !== 0 || viewport.scrollTop !== 0)) {
            viewport.scrollLeft = 0;
            viewport.scrollTop = 0;
        }
    });

    // ── Glisser (mode 3D) ─────────────────────────────────────────────────────

    var drag = null;

    viewport.addEventListener('pointerdown', function (event) {
        if (mode !== '3d' || count < 2) {
            return;
        }
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }
        drag = { id: event.pointerId, startX: event.clientX, startIndex: index, active: false };
    });

    viewport.addEventListener('pointermove', function (event) {
        if (!drag || event.pointerId !== drag.id) {
            return;
        }
        var dx = event.clientX - drag.startX;
        if (!drag.active) {
            if (Math.abs(dx) < DRAG_THRESHOLD) {
                return;
            }
            drag.active = true;
            root.classList.add('is-dragging');
            try {
                viewport.setPointerCapture(event.pointerId);
            } catch (e) {
                // Pas de capture possible : le glisser marche quand même tant
                // que le pointeur reste au-dessus du carrousel.
            }
        }
        // Un déplacement d'une « largeur de décalage » = une carte.
        var step = cards[0].offsetWidth * NEAR_SHIFT;
        position = clamp(drag.startIndex - dx / step, -0.35, count - 1 + 0.35);
        render();
    });

    function endDrag(event, cancelled) {
        if (!drag || (event && event.pointerId !== drag.id)) {
            return;
        }
        var wasActive = drag.active;
        var dx = event ? event.clientX - drag.startX : 0;
        var startIndex = drag.startIndex;
        drag = null;
        root.classList.remove('is-dragging');
        if (!wasActive) {
            return; // simple clic : c'est le gestionnaire de clic qui agit
        }
        dragMoved = true;
        // Le « clic » qui suit un glisser arrive juste après le relâchement : passé
        // ce court délai, on réarme les clics (sinon un Entrée au clavier serait ignoré).
        setTimeout(function () { dragMoved = false; }, 80);
        if (cancelled) {
            goTo(startIndex, { announce: false });
            return;
        }
        var target = Math.round(position);
        // Petit coup de doigt rapide : on avance quand même d'une carte.
        if (target === startIndex && Math.abs(dx) > 40) {
            target += dx < 0 ? 1 : -1;
        }
        // On repart de la position du doigt pour que la carte glisse jusqu'à sa place.
        index = -1;
        goTo(target, { announce: true });
    }

    viewport.addEventListener('pointerup', function (event) { endDrag(event, false); });
    viewport.addEventListener('pointercancel', function (event) { endDrag(event, true); });

    // ── Molette horizontale (mode 3D) ─────────────────────────────────────────

    var wheelSum = 0;
    var wheelLockUntil = 0;
    var wheelResetTimer = null;

    viewport.addEventListener('wheel', function (event) {
        if (mode !== '3d' || count < 2 || event.ctrlKey) {
            return; // ctrl + molette = zoom du navigateur : on n'y touche pas
        }
        var dx = event.deltaX;
        if (Math.abs(dx) <= Math.abs(event.deltaY)) {
            // Molette verticale : la page défile normalement, sauf avec Maj.
            dx = event.shiftKey ? event.deltaY : 0;
        }
        if (dx === 0) {
            return;
        }
        if (event.deltaMode === 1) {
            dx *= 16;                       // défilement exprimé en lignes
        } else if (event.deltaMode === 2) {
            dx *= viewport.clientWidth;     // défilement exprimé en pages
        }
        event.preventDefault();

        var now = Date.now();
        clearTimeout(wheelResetTimer);
        wheelResetTimer = setTimeout(function () { wheelSum = 0; }, 200);
        if (now < wheelLockUntil) {
            return;
        }
        wheelSum += dx;
        if (Math.abs(wheelSum) >= 40) {
            goTo(index + (wheelSum > 0 ? 1 : -1), { announce: true });
            wheelSum = 0;
            wheelLockUntil = now + 380;     // une carte à la fois
        }
    }, { passive: false });

    // ── Indicateur ────────────────────────────────────────────────────────────

    ticks.forEach(function (tick, i) {
        tick.addEventListener('click', function () {
            goTo(i, { announce: true });
        });
    });

    // ── Mode défilement : l'indicateur suit la carte la plus centrée ──────────

    var scrollFrame = null;
    track.addEventListener('scroll', function () {
        if (mode !== 'scroll' || scrollFrame !== null) {
            return;
        }
        scrollFrame = window.requestAnimationFrame(function () {
            scrollFrame = null;
            var trackRect = track.getBoundingClientRect();
            var middle = trackRect.left + trackRect.width / 2;
            var closest = index;
            var best = Infinity;
            cards.forEach(function (card, i) {
                var rect = card.getBoundingClientRect();
                var gap = Math.abs(rect.left + rect.width / 2 - middle);
                if (gap < best) {
                    best = gap;
                    closest = i;
                }
            });
            if (closest !== index) {
                goTo(closest, { announce: false, scroll: false });
            }
        });
    }, { passive: true });

    // ── Démarrage ─────────────────────────────────────────────────────────────

    // Carte de départ : celle indiquée dans l'adresse (#projet-…, au retour d'une
    // fiche), sinon la première.
    var start = 0;
    if (window.location.hash) {
        var wanted = decodeURIComponent(window.location.hash.slice(1)).replace(/^projet-/, '');
        cards.forEach(function (card, i) {
            if (card.dataset.slug === wanted) {
                start = i;
            }
        });
    }
    index = start;
    position = start;

    setMode();
    if (mode === 'scroll') {
        scrollToCard(start, false);
    }

    onMediaChange(mqWide, setMode);
    onMediaChange(mqReduce, setMode);

    var resizeFrame = null;
    window.addEventListener('resize', function () {
        if (resizeFrame !== null) {
            return;
        }
        resizeFrame = window.requestAnimationFrame(function () {
            resizeFrame = null;
            if (mode === '3d') {
                measure3d();
                render();
            }
        });
    });
}());

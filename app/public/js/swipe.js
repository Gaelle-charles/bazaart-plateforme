/**
 * swipe.js — UI Swipe de la section matching (ADR-0021, Lot C)
 *
 * Ce fichier gère l'interaction swipe sur la section de matching de la home.
 *
 * PROGRESSIVE ENHANCEMENT :
 *   Sans ce script, la section affiche :
 *     - La première carte avec un formulaire POST natif pour "Intéressé(e)"
 *     - Des boutons "Passer" et "Intéressé(e)" classiques
 *   Avec ce script, on enrichit l'expérience :
 *     - Navigation entre cartes sans rechargement
 *     - Swipe tactile (glisser) et souris (drag)
 *     - Animations de transition (glissement à gauche/droite)
 *     - Appel AJAX pour le toggle favori (swipe droite)
 *     - Compteur mis à jour dynamiquement
 *     - Formulaire alerte en AJAX
 *
 * STRUCTURE HTML ATTENDUE :
 *   .swipe-deck                → conteneur de la pile de cartes
 *     .swipe-card[data-index]  → chaque carte (la première est visible)
 *   .swipe-counter             → affiche "X / Y matchs"
 *   .swipe-btn-pass            → bouton "Passer" (swipe gauche)
 *   .swipe-btn-fav             → bouton "Intéressé(e)" (swipe droite)
 *   .swipe-btn-alert           → bouton "Créer une alerte" (optionnel)
 *
 * GESTION DES ERREURS :
 *   En cas d'échec AJAX, on logue dans la console (pas d'alert intrusif)
 *   et on passe à la carte suivante normalement.
 *
 * prefers-reduced-motion :
 *   Si la préférence système est "reduce", les animations sont désactivées :
 *   les cartes changent instantanément sans transition.
 *
 * Compatibilité : ES2020+ (tout navigateur depuis 2020). Pas de transpilation.
 */
(function () {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // CONFIGURATION
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Durée des animations en millisecondes.
     * Réduite à 0 si prefers-reduced-motion est activé.
     */
    const ANIM_DURATION_MS = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ? 0
        : 260;

    /**
     * Seuil en pixels pour considérer un swipe comme intentionnel.
     * En dessous → on remet la carte à sa position initiale (pas de swipe).
     */
    const SWIPE_THRESHOLD_PX = 80;

    /**
     * Rotation maximale en degrés appliquée à la carte pendant un drag (souris ou tactile).
     * La carte s'incline légèrement dans la direction du glissement pour donner
     * un retour visuel naturel. Valeur calculée : (deltaX / 400) * 12.
     * Aussi utilisée pour l'animation de sortie (animateAndAdvance) : la carte
     * part en tournant de 12 degrés vers la gauche (passer) ou la droite (favori).
     */
    const DRAG_ROTATION_MAX_DEG = 12;

    // ═══════════════════════════════════════════════════════════════════════════
    // INITIALISATION
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Point d'entrée principal — appelé une fois le DOM chargé.
     * On cible la section swipe par son ID pour isoler le script.
     */
    function init() {
        const section = document.getElementById('swipe-section');
        if (!section) {
            // Section absente (utilisateur non artiste ou profil incomplet) → rien à faire
            return;
        }

        // Récupère tous les éléments de la section
        const deck      = section.querySelector('.swipe-deck');
        const counter   = section.querySelector('.swipe-counter');
        const btnPass   = section.querySelector('.swipe-btn-pass');
        const btnFav    = section.querySelector('.swipe-btn-fav');
        const btnAlert  = section.querySelector('.swipe-btn-alert');
        const emptyMsg  = section.querySelector('.swipe-empty');

        if (!deck) {
            // Structure HTML manquante (ne devrait pas arriver mais sécurité)
            return;
        }

        // ── État interne ──────────────────────────────────────────────────────
        let currentIndex   = 0;             // Index de la carte actuellement visible
        const cards        = Array.from(deck.querySelectorAll('.swipe-card'));
        const totalCards   = cards.length;  // Nombre total de cartes chargées
        const totalMatches = parseInt(section.dataset.totalMatches || '0', 10);

        if (totalCards === 0) {
            // Aucune carte → état vide affiché par le HTML de fallback, rien à faire
            return;
        }

        // Montre la première carte immédiatement
        showCard(0);
        updateCounter();

        // ── Boutons ───────────────────────────────────────────────────────────
        if (btnPass) {
            // Swipe gauche = passer : on passe à la carte suivante sans action BDD
            btnPass.addEventListener('click', () => animateAndAdvance('left', null));
        }

        if (btnFav) {
            // Swipe droite = "intéressé(e)" = ajouter en favori
            btnFav.addEventListener('click', () => {
                const card = cards[currentIndex];
                if (!card) { return; }

                const resourceId = card.dataset.resourceId;
                const csrfToken  = card.dataset.csrfToken;

                // Action asynchrone : on appelle l'endpoint toggle favori existant
                // et on anime simultanément pour ne pas bloquer l'UX
                animateAndAdvance('right', null);
                postFavorite(resourceId, csrfToken, card);
            });
        }

        if (btnAlert) {
            // Bouton "Créer une alerte" : soumet le formulaire d'alerte en AJAX
            initAlertButton(btnAlert);
        }

        // ── Swipe tactile et souris ───────────────────────────────────────────
        initDragSwipe(deck, cards);

        // ── Navigation clavier (ArrowLeft / ArrowRight) ───────────────────────
        // Annoncee dans l'UI (.swipe-hint + title="fleche gauche/droite" sur les boutons).
        // On ecoute keydown sur document entier pour ne pas exiger que la section
        // soit focalisee -- coherent avec la convention "fleches = navigation page".
        //
        // La navigation clavier est conditionnee a la VISIBILITE de la section :
        // si l'utilisateur a scrolle loin, on ne veut pas parasiter d'autres interactions.
        // On utilise IntersectionObserver pour savoir si la section est dans le viewport.
        let sectionVisible = false;
        const keyboardObserver = new IntersectionObserver(
            (entries) => {
                // La section est "visible" si au moins 10% est dans le viewport
                sectionVisible = entries[0].isIntersecting;
            },
            { threshold: 0.10 }
        );
        keyboardObserver.observe(section);

        document.addEventListener('keydown', function (e) {
            // On n'intercepte les fleches que si la section swipe est visible
            // ET qu'il reste des cartes a voir (currentIndex < totalCards).
            if (!sectionVisible || currentIndex >= totalCards) {
                return;
            }

            // On n'intercepte pas si le focus est dans un champ de saisie
            // (textarea, input, select) pour ne pas bloquer la navigation native.
            const tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                return;
            }

            if (e.key === 'ArrowLeft') {
                // Fleche gauche = "Passer" (equivalent du bouton .swipe-btn-pass)
                e.preventDefault(); // empeche le scroll horizontal natif
                animateAndAdvance('left', null);

            } else if (e.key === 'ArrowRight') {
                // Fleche droite = "Interesse(e)" (equivalent du bouton .swipe-btn-fav)
                e.preventDefault(); // empeche le scroll horizontal natif
                const card = cards[currentIndex];
                if (!card) { return; }
                const resourceId = card.dataset.resourceId;
                const csrfToken  = card.dataset.csrfToken;
                // Meme logique que le clic sur .swipe-btn-fav :
                // on anime ET on poste le favori en parallele.
                animateAndAdvance('right', null);
                postFavorite(resourceId, csrfToken, card);
            }
        });

        // ─────────────────────────────────────────────────────────────────────
        // FONCTIONS INTERNES
        // ─────────────────────────────────────────────────────────────────────

        /**
         * Affiche la carte à l'index donné et masque toutes les autres.
         * La carte active est mise en position "haut de la pile" (z-index + opacité).
         *
         * @param {number} index L'index de la carte à afficher (0-based)
         */
        function showCard(index) {
            cards.forEach((card, i) => {
                if (i === index) {
                    // Carte active : visible, en premier plan
                    card.classList.add('swipe-card--active');
                    card.classList.remove('swipe-card--hidden');
                    card.removeAttribute('aria-hidden');
                    card.setAttribute('tabindex', '0');
                } else if (i === index + 1) {
                    // Carte suivante : visible en arrière-plan (effet "pile")
                    card.classList.remove('swipe-card--active', 'swipe-card--hidden');
                    card.setAttribute('aria-hidden', 'true');
                    card.setAttribute('tabindex', '-1');
                } else {
                    // Autres cartes : complètement cachées
                    card.classList.add('swipe-card--hidden');
                    card.classList.remove('swipe-card--active');
                    card.setAttribute('aria-hidden', 'true');
                    card.setAttribute('tabindex', '-1');
                }
            });
        }

        /**
         * Met à jour le compteur "X sur Y matchs".
         * Utilise le totalMatches global (peut dépasser le nombre de cartes chargées).
         */
        function updateCounter() {
            if (!counter) { return; }
            const displayed = Math.min(currentIndex + 1, totalCards);
            // On affiche l'index dans les cartes chargées, pas le total global
            // pour ne pas semer la confusion (on n'a chargé que les 20 premiers matchs)
            counter.textContent = displayed + ' / ' + totalMatches;
        }

        /**
         * Anime la carte courante vers la gauche (passer) ou la droite (favori),
         * puis avance à la carte suivante.
         *
         * @param {'left'|'right'} direction Direction de l'animation
         * @param {Function|null} onComplete Callback optionnel après l'animation
         */
        function animateAndAdvance(direction, onComplete) {
            const card = cards[currentIndex];
            if (!card) { return; }

            const translateX = direction === 'left' ? '-120%' : '120%';

            if (ANIM_DURATION_MS > 0) {
                // Animation CSS via transition inline
                card.style.transition = `transform ${ANIM_DURATION_MS}ms ease, opacity ${ANIM_DURATION_MS}ms ease`;
                card.style.transform  = `translateX(${translateX}) rotate(${direction === 'left' ? '-' : ''}${DRAG_ROTATION_MAX_DEG}deg)`;
                card.style.opacity    = '0';

                // Après l'animation, on passe à la carte suivante
                setTimeout(() => {
                    card.style.transition = '';
                    card.style.transform  = '';
                    card.style.opacity    = '';
                    advance();
                    if (onComplete) { onComplete(); }
                }, ANIM_DURATION_MS);
            } else {
                // prefers-reduced-motion : pas d'animation, changement instantané
                advance();
                if (onComplete) { onComplete(); }
            }
        }

        /**
         * Avance à la carte suivante ou affiche l'état vide si toutes les cartes
         * ont été swipées.
         */
        function advance() {
            currentIndex++;

            if (currentIndex >= totalCards) {
                // Toutes les cartes ont été parcourues → état vide
                showEmptyState();
                return;
            }

            showCard(currentIndex);
            updateCounter();

            // Remet le focus sur la nouvelle carte pour l'accessibilité clavier
            const newCard = cards[currentIndex];
            if (newCard) {
                newCard.focus();
            }
        }

        /**
         * Affiche l'état vide (toutes les cartes ont été swipées).
         * Masque la pile de cartes et les boutons, affiche le message de fin.
         */
        function showEmptyState() {
            // Cache la pile
            deck.style.display = 'none';

            // Cache les boutons d'action
            const actionsBar = section.querySelector('.swipe-actions');
            if (actionsBar) { actionsBar.style.display = 'none'; }

            // Affiche le message de fin
            if (emptyMsg) {
                emptyMsg.classList.remove('swipe-empty--hidden');
                emptyMsg.removeAttribute('aria-hidden');
            }
        }

        /**
         * Envoie un POST AJAX pour toggler le favori d'une ressource.
         * Utilise l'endpoint existant POST /resources/{id}/favorite.
         *
         * En cas d'échec (réseau, CSRF), on logue en console sans bloquer l'UX :
         * le swipe est déjà animé, on ne peut pas "l'annuler" raisonnablement.
         *
         * @param {string} resourceId L'ID de la ressource à mettre en favori
         * @param {string} csrfToken  Le token CSRF spécifique à cette ressource
         * @param {Element} card      L'élément DOM de la carte (pour feedback visuel)
         */
        function postFavorite(resourceId, csrfToken, card) {
            const body = new FormData();
            body.append('_token', csrfToken);

            fetch('/resources/' + resourceId + '/favorite', {
                method:  'POST',
                headers: {
                    // L'en-tête standard pour indiquer une requête AJAX.
                    // ResourceController::toggleFavorite() utilise isXmlHttpRequest()
                    // pour détecter ce mode et retourner du JSON.
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body,
            })
            .then(response => {
                if (!response.ok) {
                    // Statut HTTP d'erreur → on logue sans bloquer l'UI
                    console.warn('[Swipe] Erreur favori HTTP ' + response.status);
                    return null;
                }
                return response.json();
            })
            .then(data => {
                if (!data) { return; }
                // data.favorited = true si ajouté, false si retiré
                // En swipe droite, on ajoute toujours (jamais de toggle "retrait" depuis le swipe)
                // Le feedback visuel est l'animation de la carte qui part à droite
                // Si déjà en favori (data.favorited = false = toggle off depuis le swipe),
                // on ne fait rien de particulier (l'animation a déjà eu lieu)
                if (data.favorited) {
                    // Feedback discret : l'animation de la carte suffit
                    // On pourrait ajouter un toast ici en V2
                }
            })
            .catch(err => {
                // Erreur réseau : logue sans bloquer, le swipe est déjà fait
                console.warn('[Swipe] Erreur réseau toggle favori :', err);
            });
        }

        /**
         * Initialise le bouton "Créer une alerte" pour fonctionner en AJAX.
         * Le formulaire HTML natif (fallback sans JS) est remplacé par un fetch().
         *
         * @param {Element} btn Le bouton d'alerte
         */
        function initAlertButton(btn) {
            const form = btn.closest('form');
            if (!form) {
                // Pas de formulaire parent → on ne peut pas intercepter la soumission
                return;
            }

            form.addEventListener('submit', function (e) {
                // Empêche la soumission HTML normale → on prend la main en AJAX
                e.preventDefault();

                const formData = new FormData(form);

                // Vérification côté JS (doublée côté serveur) :
                // la checkbox de consentement doit être cochée.
                if (!formData.get('consent')) {
                    // Affiche un message d'erreur discret sous la checkbox
                    showAlertFeedback(
                        form,
                        'Vous devez cocher la case pour activer les alertes.',
                        'error'
                    );
                    return;
                }

                // Désactive le bouton pendant la requête (évite le double-clic)
                btn.disabled  = true;
                btn.textContent = 'Activation...';

                fetch('/swipe/alert', {
                    method:  'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body:    formData,
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Succès : on remplace le formulaire par un message de confirmation.
                        // On utilise data.message renvoyé par le serveur (avec accents complets)
                        // en repli sur un texte par défaut si absent (réponse inattendue).
                        const successMsg = data.message || 'Alerte activée ! Vous serez notifié(e) des prochaines opportunités.';
                        showAlertFeedback(form, successMsg, 'success');
                        // Masque le formulaire pour ne pas proposer de re-cliquer
                        form.style.display = 'none';
                    } else {
                        // Erreur serveur : on ré-active le bouton
                        btn.disabled    = false;
                        btn.textContent = 'Activer les alertes';
                        showAlertFeedback(
                            form,
                            data.error || 'Une erreur s\'est produite. Veuillez réessayer.',
                            'error'
                        );
                    }
                })
                .catch(err => {
                    console.warn('[Swipe] Erreur réseau alerte :', err);
                    btn.disabled    = false;
                    btn.textContent = 'Activer les alertes';
                    showAlertFeedback(form, 'Erreur réseau. Veuillez réessayer.', 'error');
                });
            });
        }

        /**
         * Affiche un message de retour sous le formulaire d'alerte.
         *
         * @param {Element} form    Le formulaire parent
         * @param {string}  message Le texte à afficher
         * @param {'success'|'error'} type Le type de message (couleur différente)
         */
        function showAlertFeedback(form, message, type) {
            // Cherche un espace de feedback existant ou en crée un
            let feedback = form.querySelector('.swipe-alert-feedback');
            if (!feedback) {
                feedback = document.createElement('p');
                feedback.className = 'swipe-alert-feedback';
                form.appendChild(feedback);
            }
            feedback.textContent = message;
            feedback.dataset.type = type; // CSS utilise [data-type="error"] pour colorier
            // Accessibilité : on annonce le changement aux lecteurs d'écran
            feedback.setAttribute('role', 'status');
            feedback.setAttribute('aria-live', 'polite');
        }

        /**
         * Initialise le swipe tactile et souris sur la pile de cartes.
         * Seule la carte active (currentIndex) réagit au drag.
         *
         * Implémentation "pointer events" — API unifiée souris + tactile.
         * Plus simple que de doubler les event listeners touchstart/mousedown.
         *
         * @param {Element} deck  Le conteneur de la pile
         * @param {Element[]} cards  Tableau des cartes
         */
        function initDragSwipe(deck, cards) {
            let startX      = 0;    // Position X au début du drag
            let currentX    = 0;    // Position X courante pendant le drag
            let isDragging  = false;
            let activeCard  = null; // Référence à la carte en cours de drag

            deck.addEventListener('pointerdown', function (e) {
                // On ne réagit qu'aux pointeurs primaires (doigt 1 ou bouton gauche souris)
                if (!e.isPrimary) { return; }

                activeCard = cards[currentIndex];
                if (!activeCard) { return; }

                // Capture le pointeur pour rester notifié même si le doigt sort du deck
                activeCard.setPointerCapture(e.pointerId);

                startX     = e.clientX;
                currentX   = e.clientX;
                isDragging = true;

                // Désactive la transition pendant le drag (on contrôle en temps réel)
                activeCard.style.transition = 'none';
            });

            deck.addEventListener('pointermove', function (e) {
                if (!isDragging || !e.isPrimary || !activeCard) { return; }

                currentX        = e.clientX;
                const deltaX    = currentX - startX;
                // Rotation légère proportionnelle au déplacement (max ±DRAG_ROTATION_MAX_DEG)
                const rotation  = (deltaX / 400) * DRAG_ROTATION_MAX_DEG;

                // Feedback visuel temps réel : la carte suit le doigt
                activeCard.style.transform = `translateX(${deltaX}px) rotate(${rotation}deg)`;
                // Légère réduction d'opacité si déplacement important
                const absRatio  = Math.min(Math.abs(deltaX) / 200, 1);
                activeCard.style.opacity = String(1 - absRatio * 0.4);
            });

            deck.addEventListener('pointerup', function (e) {
                if (!isDragging || !e.isPrimary || !activeCard) { return; }

                isDragging = false;
                const deltaX = currentX - startX;
                activeCard.releasePointerCapture(e.pointerId);

                if (Math.abs(deltaX) >= SWIPE_THRESHOLD_PX) {
                    // Seuil atteint → on confirme le swipe
                    if (deltaX > 0) {
                        // Swipe droite = favoris
                        const resourceId = activeCard.dataset.resourceId;
                        const csrfToken  = activeCard.dataset.csrfToken;
                        animateAndAdvance('right', null);
                        postFavorite(resourceId, csrfToken, activeCard);
                    } else {
                        // Swipe gauche = passer
                        animateAndAdvance('left', null);
                    }
                } else {
                    // En dessous du seuil → on remet la carte à sa place (spring-back)
                    activeCard.style.transition = `transform ${ANIM_DURATION_MS}ms ease, opacity ${ANIM_DURATION_MS}ms ease`;
                    activeCard.style.transform  = '';
                    activeCard.style.opacity    = '';

                    // Nettoie la transition après le retour
                    setTimeout(() => {
                        if (activeCard) {
                            activeCard.style.transition = '';
                        }
                    }, ANIM_DURATION_MS);
                }

                activeCard = null;
            });

            // Annulation du drag si le pointeur sort de la fenêtre
            deck.addEventListener('pointercancel', function () {
                isDragging = false;
                if (activeCard) {
                    activeCard.style.transition = '';
                    activeCard.style.transform  = '';
                    activeCard.style.opacity    = '';
                    activeCard = null;
                }
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DÉMARRAGE
    // ═══════════════════════════════════════════════════════════════════════════

    // Initialisation après chargement du DOM.
    // DOMContentLoaded suffit : on n'a pas besoin d'images chargées (pas de dimensions).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM déjà prêt (script chargé en defer ou en bas de page)
        init();
    }

}());

/* ============================================================================
   ESPACE PROJETS : interactions côté navigateur (ADR-0037)
   ----------------------------------------------------------------------------
   JavaScript « vanilla » (aucune bibliothèque), chargé uniquement par
   templates/admin/projects/_layout.html.twig.

   Tout fonctionne SANS JavaScript (formulaires classiques) ; ce script ajoute :
     1. les fenêtres modales (<dialog>) : ajout rapide de tâche, raccourci « N » ;
     2. la visite guidée (onboarding) ;
     3. le glisser-déposer des tâches (Kanban, Par personne, Par priorité,
        Calendrier), à la souris ET au doigt (appui long) ;
     4. le sélecteur / navigateur Google Drive ;
     5. les cases de checklist cochées sans rechargement.

   SÉCURITÉ :
     - chaque requête qui MODIFIE des données envoie le jeton CSRF (en-tête
       X-CSRF-Token), lu dans <meta name="pm-csrf"> ;
     - les noms de fichiers venant du Drive sont insérés avec textContent
       (jamais innerHTML) : impossible d'injecter du HTML via un nom de fichier.
   ========================================================================== */

(function () {
    'use strict';

    const root = document.getElementById('pm-root');
    if (!root) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="pm-csrf"]')?.getAttribute('content') || '';

    // ═══════════════════════════════════════════════════════════════════════
    // Utilitaires
    // ═══════════════════════════════════════════════════════════════════════

    /** Petit message flottant en bas à droite (erreurs de glisser-déposer, Drive…). */
    const toastEl = document.getElementById('pm-toast');
    let toastTimer = null;
    function toast(message, isError = false) {
        if (!toastEl) { return; }
        toastEl.textContent = message;
        toastEl.classList.toggle('pm-toast--error', isError);
        toastEl.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('is-visible'), 4000);
    }

    /**
     * fetch() + JSON, avec le jeton CSRF. Renvoie toujours un objet
     * {ok: bool, ...} même si le serveur ou le réseau échoue.
     */
    async function requestJson(url, options = {}) {
        const headers = Object.assign({
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        }, options.headers || {});
        if (options.body && !(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
        }
        try {
            const response = await fetch(url, Object.assign({credentials: 'same-origin'}, options, {headers}));
            const data = await response.json().catch(() => ({}));
            if (!response.ok && data.ok === undefined) {
                data.ok = false;
            }
            if (!response.ok && !data.error) {
                data.error = 'Le serveur a répondu avec une erreur (' + response.status + ').';
            }
            return data;
        } catch (e) {
            return {ok: false, error: 'Connexion impossible. Vérifie ta connexion internet.'};
        }
    }

    /** L'utilisatrice est-elle en train d'écrire (champ de saisie actif) ? */
    function isTyping(target) {
        return target instanceof HTMLElement
            && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1. Fenêtres modales
    // ═══════════════════════════════════════════════════════════════════════

    function openDialog(dialog) {
        if (dialog && !dialog.open) {
            dialog.showModal();
        }
    }

    // Boutons [data-pm-close] : ferment la fenêtre qui les contient
    document.addEventListener('click', (event) => {
        const closer = event.target.closest('[data-pm-close]');
        if (closer) {
            closer.closest('dialog')?.close();
        }
    });

    // Clic sur le fond assombri (en dehors du contenu) : ferme la fenêtre
    document.querySelectorAll('.pm-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    // Ajout rapide de tâche
    const quickTask = document.getElementById('pm-quick-task');
    function openQuickTask(projectId) {
        if (!quickTask) { return; }
        const projectSelect = quickTask.querySelector('[name="projectId"]');
        if (projectSelect && projectId) {
            projectSelect.value = projectId;
        }
        openDialog(quickTask);
        quickTask.querySelector('[name="title"]')?.focus();
    }
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-pm-quick-task]');
        if (trigger) {
            event.preventDefault();
            openQuickTask(trigger.dataset.project || new URLSearchParams(location.search).get('project'));
        }
    });

    // Raccourci clavier « N » = nouvelle tâche (hors champ de saisie et hors fenêtre ouverte)
    document.addEventListener('keydown', (event) => {
        if (event.key.toLowerCase() !== 'n' || event.ctrlKey || event.metaKey || event.altKey) { return; }
        if (isTyping(event.target) || document.querySelector('dialog[open]')) { return; }
        event.preventDefault();
        openQuickTask(new URLSearchParams(location.search).get('project'));
    });

    // Afficher / masquer un bloc (ex. formulaire de modification d'une note)
    document.addEventListener('click', (event) => {
        const toggler = event.target.closest('[data-pm-toggle]');
        if (!toggler) { return; }
        const target = document.getElementById(toggler.dataset.pmToggle);
        if (target) {
            target.hidden = !target.hidden;
            toggler.setAttribute('aria-expanded', String(!target.hidden));
            if (!target.hidden) {
                target.querySelector('textarea, input:not([type="hidden"])')?.focus();
            }
        }
    });

    // ═══════════════════════════════════════════════════════════════════════
    // 2. Visite guidée (onboarding)
    // ═══════════════════════════════════════════════════════════════════════

    const tour = document.getElementById('pm-tour');
    if (tour) {
        const steps = Array.from(tour.querySelectorAll('.pm-tour__step'));
        const dots = tour.querySelector('[data-pm-tour-dots]');
        const prevBtn = tour.querySelector('[data-pm-tour-prev]');
        const nextBtn = tour.querySelector('[data-pm-tour-next]');
        let index = 0;

        steps.forEach(() => dots.appendChild(document.createElement('span')));

        const render = () => {
            steps.forEach((step, i) => step.classList.toggle('is-active', i === index));
            Array.from(dots.children).forEach((dot, i) => dot.classList.toggle('is-active', i === index));
            prevBtn.hidden = index === 0;
            nextBtn.textContent = index === steps.length - 1 ? 'C\'est parti !' : 'Suivant';
        };

        // Mémorise côté serveur que la visite a été vue (une seule fois suffit).
        let saved = root.dataset.showTour !== '1';
        const markDone = () => {
            if (!saved) {
                saved = true;
                requestJson(root.dataset.urlTour, {method: 'POST', body: '{}'});
            }
        };

        const open = () => { index = 0; render(); openDialog(tour); nextBtn.focus(); };
        const finish = () => { markDone(); tour.close(); };

        nextBtn.addEventListener('click', () => {
            if (index < steps.length - 1) { index++; render(); } else { finish(); }
        });
        prevBtn.addEventListener('click', () => { if (index > 0) { index--; render(); } });
        tour.querySelectorAll('[data-pm-tour-skip]').forEach((btn) => btn.addEventListener('click', finish));
        tour.addEventListener('cancel', markDone); // touche Échap = visite passée
        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-pm-tour-open]')) {
                event.preventDefault();
                open();
            }
        });

        // Première visite : ouverture automatique
        if (root.dataset.showTour === '1') {
            open();
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. Glisser-déposer des tâches
    // ═══════════════════════════════════════════════════════════════════════
    //
    // POURQUOI ne pas utiliser l'API HTML5 « draggable » ?
    //   Elle ne fonctionne pas au doigt sur la plupart des téléphones. On gère donc
    //   nous-mêmes les « pointer events » (souris, doigt et stylet avec le même code) :
    //     - souris : le glisser démarre après 6 px de mouvement ;
    //     - doigt  : il faut un APPUI LONG (350 ms) ; un geste rapide fait défiler
    //                la page normalement.
    //
    // Balisage attendu :
    //   [data-pm-drop data-field="status" data-value="in_progress"]  → zone de dépôt
    //     [data-pm-drop-list]                                         → conteneur des cartes
    //       [data-pm-drag data-task-id data-move-url]                 → carte déplaçable

    const DRAG_THRESHOLD = 6;
    const LONG_PRESS_MS = 350;
    let drag = null;

    function zoneList(zone) {
        return zone.querySelector('[data-pm-drop-list]') || zone;
    }

    function updateCounts() {
        document.querySelectorAll('[data-pm-drop]').forEach((zone) => {
            const counter = zone.querySelector('[data-pm-count]');
            if (counter) {
                counter.textContent = zoneList(zone).querySelectorAll(':scope > [data-pm-drag]').length;
            }
        });
    }

    function startDrag(x, y) {
        const item = drag.item;
        const rect = item.getBoundingClientRect();
        drag.started = true;
        drag.offsetX = x - rect.left;
        drag.offsetY = y - rect.top;
        drag.origin = {parent: item.parentNode, next: item.nextSibling};

        // Le « fantôme » est une copie visuelle qui suit le pointeur.
        const ghost = item.cloneNode(true);
        ghost.removeAttribute('data-pm-drag');
        Object.assign(ghost.style, {
            position: 'fixed', zIndex: 1000, pointerEvents: 'none', margin: 0,
            width: rect.width + 'px', left: rect.left + 'px', top: rect.top + 'px',
            transform: 'rotate(2deg)', boxShadow: '6px 6px 0 #0E0D0B', opacity: '.95',
        });
        document.body.appendChild(ghost);
        drag.ghost = ghost;

        item.classList.add('pm-task--dragging');
        document.body.style.userSelect = 'none';
        if (navigator.vibrate && drag.pointerType !== 'mouse') {
            navigator.vibrate(15);
        }
    }

    function moveGhost(x, y) {
        drag.ghost.style.left = (x - drag.offsetX) + 'px';
        drag.ghost.style.top = (y - drag.offsetY) + 'px';
    }

    /** Déplace la carte réelle (qui sert de repère) à l'endroit où elle serait déposée. */
    function updateDropTarget(x, y) {
        const under = document.elementFromPoint(x, y);
        const zone = under?.closest('[data-pm-drop]');
        document.querySelectorAll('.pm-col--over').forEach((el) => el !== zone && el.classList.remove('pm-col--over'));
        if (!zone) {
            drag.zone = null;
            return;
        }
        zone.classList.add('pm-col--over');
        drag.zone = zone;

        const list = zoneList(zone);
        const siblings = Array.from(list.querySelectorAll(':scope > [data-pm-drag]')).filter((el) => el !== drag.item);
        const before = siblings.find((el) => {
            const r = el.getBoundingClientRect();
            return y < r.top + r.height / 2;
        });
        if (before) {
            if (before.previousSibling !== drag.item) { list.insertBefore(drag.item, before); }
        } else if (list.lastElementChild !== drag.item) {
            list.appendChild(drag.item);
        }
    }

    /** Défilement automatique quand on approche du bord (tableau large, page longue). */
    function autoScroll(x, y) {
        const board = drag.item.closest('.pm-board');
        if (board) {
            const r = board.getBoundingClientRect();
            if (x < r.left + 50) { board.scrollLeft -= 14; }
            if (x > r.right - 50) { board.scrollLeft += 14; }
        }
        if (y < 60) { window.scrollBy(0, -14); }
        if (y > window.innerHeight - 60) { window.scrollBy(0, 14); }
    }

    function revert() {
        const {parent, next} = drag.origin;
        parent.insertBefore(drag.item, next && next.parentNode === parent ? next : null);
    }

    function endDrag(commit) {
        const current = drag;
        drag = null;
        clearTimeout(current.timer);
        document.body.style.userSelect = '';
        document.querySelectorAll('.pm-col--over').forEach((el) => el.classList.remove('pm-col--over'));
        if (!current.started) {
            return;
        }
        current.ghost.remove();
        current.item.classList.remove('pm-task--dragging');

        // Empêche le « clic » qui suit le relâchement d'ouvrir la tâche.
        const swallow = (e) => { e.preventDefault(); e.stopPropagation(); };
        document.addEventListener('click', swallow, {capture: true, once: true});
        setTimeout(() => document.removeEventListener('click', swallow, {capture: true}), 50);

        const zone = current.zone;
        if (!commit || !zone) {
            drag = current;
            revert();
            drag = null;
            return;
        }
        commitMove(current, zone);
    }

    async function commitMove(current, zone) {
        const item = current.item;
        const field = zone.dataset.field;
        const source = current.sourceZone;
        const sameZone = source === zone;

        // Dans une même colonne, seul le Kanban (ordre manuel) a quelque chose à enregistrer.
        if (sameZone && field !== 'status') {
            return;
        }

        const payload = {
            field: field,
            value: zone.dataset.value,
            fromUserId: field === 'assignee' && source && source.dataset.value !== 'none' ? source.dataset.value : null,
            orderedIds: field === 'status'
                ? Array.from(zoneList(zone).querySelectorAll(':scope > [data-pm-drag]')).map((el) => Number(el.dataset.taskId))
                : [],
        };

        const result = await requestJson(item.dataset.moveUrl, {method: 'POST', body: JSON.stringify(payload)});
        if (!result.ok) {
            drag = current;
            revert();
            drag = null;
            updateCounts();
            toast(result.error || 'Déplacement impossible.', true);
            return;
        }

        // ── Mise à jour de l'affichage après succès ──
        const taskId = item.dataset.taskId;
        if (field === 'assignee') {
            // Vue « Par personne » : une tâche partagée apparaît dans plusieurs colonnes.
            if (zone.dataset.value === 'none') {
                // Plus personne n'est assigné : on retire les autres exemplaires.
                document.querySelectorAll('[data-pm-drag][data-task-id="' + taskId + '"]').forEach((el) => el !== item && el.remove());
            } else if (zoneList(zone).querySelectorAll('[data-task-id="' + taskId + '"]').length > 1) {
                // La personne cible l'avait déjà : pas de doublon dans sa colonne.
                item.remove();
            }
        }
        if (field === 'status' && item.classList.contains('pm-task')) {
            item.classList.toggle('pm-task--done', Boolean(result.done));
        }
        // Calendrier : une carte du bac « Sans échéance » posée sur un jour (ou l'inverse)
        // doit changer d'apparence : on recharge simplement la page.
        if (field === 'dueDate' && item.classList.contains('pm-task') !== zone.classList.contains('pm-undated')) {
            location.reload();
            return;
        }
        updateCounts();
    }

    document.addEventListener('pointerdown', (event) => {
        const item = event.target.closest('[data-pm-drag]');
        if (!item || event.button !== 0 || drag) { return; }
        // Les boutons et champs à l'intérieur d'une carte restent utilisables normalement.
        // (Les LIENS, eux, peuvent servir de poignée : un simple clic sans mouvement
        // ouvre toujours la page, le glisser ne démarre qu'après quelques pixels.)
        if (event.target.closest('button, input, select, textarea, form')) { return; }
        // Souris : on empêche la sélection de texte et le « glisser de lien » natif du navigateur.
        if (event.pointerType === 'mouse') { event.preventDefault(); }

        drag = {
            item: item,
            pointerId: event.pointerId,
            pointerType: event.pointerType,
            startX: event.clientX,
            startY: event.clientY,
            started: false,
            sourceZone: item.closest('[data-pm-drop]'),
            zone: null,
        };
        if (event.pointerType !== 'mouse') {
            const x = event.clientX;
            const y = event.clientY;
            drag.timer = setTimeout(() => {
                if (drag && !drag.started) {
                    startDrag(x, y);
                    updateDropTarget(x, y);
                }
            }, LONG_PRESS_MS);
        }
    });

    // Le navigateur a son propre glisser-déposer « natif » (liens, images) et la
    // sélection de texte au clic-glissé : on les neutralise sur les cartes, sinon ils
    // prennent le dessus sur notre glisser (événement pointercancel).
    document.addEventListener('dragstart', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-pm-drag]')) { event.preventDefault(); }
    });
    document.addEventListener('mousedown', (event) => {
        if (event.button === 0 && event.target.closest('[data-pm-drag]') && !event.target.closest('button, input, select, textarea, form')) {
            event.preventDefault();
        }
    });

    document.addEventListener('pointermove', (event) => {
        if (!drag || event.pointerId !== drag.pointerId) { return; }
        const distance = Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY);
        if (!drag.started) {
            if (drag.pointerType === 'mouse' && distance > DRAG_THRESHOLD) {
                startDrag(event.clientX, event.clientY);
            } else {
                // Au doigt, bouger AVANT la fin de l'appui long = faire défiler la page.
                if (drag.pointerType !== 'mouse' && distance > 10) {
                    clearTimeout(drag.timer);
                    drag = null;
                }
                return;
            }
        }
        event.preventDefault();
        moveGhost(event.clientX, event.clientY);
        updateDropTarget(event.clientX, event.clientY);
        autoScroll(event.clientX, event.clientY);
    }, {passive: false});

    document.addEventListener('pointerup', (event) => {
        if (drag && event.pointerId === drag.pointerId) { endDrag(true); }
    });
    document.addEventListener('pointercancel', (event) => {
        if (drag && event.pointerId === drag.pointerId) { endDrag(false); }
    });
    // Au doigt, pendant un glisser, on bloque le défilement de la page.
    document.addEventListener('touchmove', (event) => {
        if (drag && drag.started) { event.preventDefault(); }
    }, {passive: false});
    // Pas de menu contextuel (appui long sur un lien) pendant un glisser.
    document.addEventListener('contextmenu', (event) => {
        if (drag) { event.preventDefault(); }
    });
    // Échap annule le glisser en cours.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drag && drag.started) { endDrag(false); }
    });

    // ═══════════════════════════════════════════════════════════════════════
    // 4. Checklist : cocher sans recharger la page
    // ═══════════════════════════════════════════════════════════════════════

    document.querySelectorAll('form[data-pm-subtask]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const checkbox = form.querySelector('input[type="checkbox"]');
            const result = await requestJson(form.action, {method: 'POST', body: '{}'});
            if (!result.ok) {
                checkbox.checked = !checkbox.checked;
                toast(result.error || 'Impossible de cocher cet élément.', true);
                return;
            }
            form.closest('.pm-subtask')?.classList.toggle('pm-subtask--done', Boolean(result.done));
            // Met à jour « 2/5 » et la barre de progression
            const all = document.querySelectorAll('form[data-pm-subtask] input[type="checkbox"]');
            const done = Array.from(all).filter((c) => c.checked).length;
            const counter = document.querySelector('[data-pm-subtask-count]');
            const bar = document.querySelector('[data-pm-subtask-progress]');
            if (counter) { counter.textContent = done + '/' + all.length; }
            if (bar) { bar.style.width = Math.round(done * 100 / Math.max(1, all.length)) + '%'; }
        });
    });

    // ═══════════════════════════════════════════════════════════════════════
    // 5. Google Drive : sélecteur (fenêtre) et navigateur (page Drive)
    // ═══════════════════════════════════════════════════════════════════════

    /** Icônes par famille de fichier (mêmes tracés que la macro Twig pm.icon). */
    const KIND_ICONS = {
        folder: '<path d="M3 6h6l2 2h10v11H3z"/>',
        doc: '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h8M9 17h8"/>',
        sheet: '<rect x="4" y="3" width="16" height="18"/><path d="M4 9h16M4 15h16M10 3v18"/>',
        slides: '<rect x="3" y="4" width="18" height="12"/><path d="M12 16v4M8 20h8"/>',
        pdf: '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/><path d="M9 15h6"/>',
        image: '<rect x="3" y="4" width="18" height="16"/><circle cx="9" cy="10" r="2"/><path d="m21 17-5-5-9 8"/>',
        video: '<rect x="3" y="5" width="13" height="14"/><path d="m16 10 5-3v10l-5-3"/>',
        audio: '<path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/>',
        file: '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/>',
    };

    function kindIcon(kind) {
        const span = document.createElement('span');
        span.className = 'pm-file__icon pm-kind--' + (KIND_ICONS[kind] ? kind : 'file');
        // Contenu SVG statique (constantes ci-dessus) : aucune donnée externe injectée.
        span.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + (KIND_ICONS[kind] || KIND_ICONS.file) + '</svg>';
        return span;
    }

    function formatDate(iso) {
        if (!iso) { return ''; }
        const d = new Date(iso);
        return isNaN(d) ? '' : d.toLocaleDateString('fr-FR', {day: 'numeric', month: 'short', year: 'numeric'});
    }

    /**
     * Composant Drive réutilisable.
     *   mode 'attach' : cocher des fichiers/dossiers puis « Joindre la sélection »
     *   mode 'folder' : naviguer puis « Choisir ce dossier » (dossier du projet)
     *   mode 'browse' : simple navigation (page Drive) ; un clic ouvre le fichier
     */
    class DriveBrowser {
        constructor(container, options) {
            this.el = container;
            this.options = options;
            this.list = container.querySelector('[data-pm-picker-list]');
            this.crumbsEl = container.querySelector('[data-pm-picker-crumbs]');
            this.searchForm = container.querySelector('[data-pm-picker-search]');
            this.moreBtn = container.querySelector('[data-pm-picker-more]');
            this.tabs = Array.from(container.querySelectorAll('[data-pm-picker-tab]'));
            this.selected = new Map();
            this.tab = 'browse';
            this.folderId = 'root';
            this.nextPageToken = null;
            this.query = '';

            this.tabs.forEach((btn) => btn.addEventListener('click', () => this.switchTab(btn.dataset.pmPickerTab)));
            this.searchForm?.addEventListener('submit', (event) => {
                event.preventDefault();
                this.query = this.searchForm.querySelector('input').value.trim();
                if (this.query !== '') { this.load(false); }
            });
            this.moreBtn?.addEventListener('click', () => this.load(true));
        }

        reset(mode, startFolder) {
            this.options.mode = mode;
            this.selected.clear();
            this.folderId = startFolder || 'root';
            this.switchTab('browse');
        }

        switchTab(tab) {
            this.tab = tab;
            this.tabs.forEach((btn) => btn.setAttribute('aria-selected', String(btn.dataset.pmPickerTab === tab)));
            if (this.searchForm) {
                this.searchForm.hidden = tab !== 'search';
            }
            this.crumbsEl.hidden = tab !== 'browse';
            if (tab === 'search') {
                this.list.replaceChildren(this.status('Tape le nom d\'un fichier ou d\'un dossier.'));
                this.moreBtn.hidden = true;
                this.searchForm?.querySelector('input').focus();
                this.options.onChange?.(this);
                return;
            }
            this.load(false);
        }

        openFolder(id) {
            this.folderId = id;
            if (this.tab !== 'browse') {
                this.switchTab('browse');
            } else {
                this.load(false);
            }
        }

        status(text) {
            const li = document.createElement('li');
            li.className = 'pm-picker__status';
            li.textContent = text;
            return li;
        }

        async load(append) {
            const params = new URLSearchParams();
            let url;
            if (this.tab === 'browse') {
                url = root.dataset.urlDriveList;
                params.set('id', this.folderId);
            } else if (this.tab === 'recent') {
                url = root.dataset.urlDriveRecent;
            } else {
                url = root.dataset.urlDriveSearch;
                params.set('q', this.query);
            }
            if (append && this.nextPageToken) {
                params.set('pageToken', this.nextPageToken);
            }
            if (!append) {
                this.list.replaceChildren(this.status('Chargement…'));
            }
            this.moreBtn.hidden = true;

            const data = await requestJson(url + '?' + params.toString(), {method: 'GET'});
            if (!data.ok) {
                const li = this.status(data.error || 'Le Drive ne répond pas.');
                if (data.notConnected) {
                    const link = document.createElement('a');
                    link.href = root.dataset.urlDrivePage;
                    link.className = 'pm-link';
                    link.textContent = ' Connecter le Drive';
                    li.appendChild(link);
                }
                this.list.replaceChildren(li);
                return;
            }

            if (this.tab === 'browse' && data.breadcrumb) {
                this.renderCrumbs(data.breadcrumb);
            }
            if (!append) {
                this.list.replaceChildren();
            }
            const items = data.items || [];
            if (!append && items.length === 0) {
                this.list.appendChild(this.status(this.tab === 'search' ? 'Aucun résultat.' : 'Ce dossier est vide.'));
            }
            items.forEach((item) => this.list.appendChild(this.renderItem(item)));
            this.nextPageToken = data.nextPageToken || null;
            this.moreBtn.hidden = !this.nextPageToken;
            this.options.onChange?.(this);
        }

        renderCrumbs(crumbs) {
            this.crumbsEl.replaceChildren();
            crumbs.forEach((crumb, i) => {
                if (i > 0) { this.crumbsEl.append(' › '); }
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = crumb.name;
                btn.addEventListener('click', () => this.openFolder(crumb.id));
                this.crumbsEl.appendChild(btn);
            });
        }

        renderItem(item) {
            const mode = this.options.mode;
            const li = document.createElement('li');
            li.className = 'pm-picker__item';

            // Case à cocher : uniquement en mode « joindre »
            if (mode === 'attach') {
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = this.selected.has(item.id);
                checkbox.setAttribute('aria-label', 'Sélectionner ' + item.name);
                checkbox.addEventListener('change', () => {
                    checkbox.checked ? this.selected.set(item.id, item) : this.selected.delete(item.id);
                    li.classList.toggle('is-selected', checkbox.checked);
                    this.options.onChange?.(this);
                });
                li.classList.toggle('is-selected', checkbox.checked);
                li.appendChild(checkbox);
            }

            li.appendChild(kindIcon(item.kind));

            const name = document.createElement('button');
            name.type = 'button';
            name.className = 'pm-picker__name';
            name.textContent = item.name; // textContent : aucun HTML interprété
            name.title = item.isFolder ? 'Ouvrir le dossier' : item.name;
            name.addEventListener('click', () => {
                if (item.isFolder) {
                    this.openFolder(item.id);
                } else if (mode === 'attach') {
                    li.querySelector('input[type="checkbox"]')?.click();
                } else {
                    window.open(item.webViewLink, '_blank', 'noopener');
                }
            });
            if (mode === 'folder' && !item.isFolder) {
                name.disabled = true;
                li.style.opacity = '.5';
            }
            li.appendChild(name);

            const meta = document.createElement('span');
            meta.className = 'pm-picker__open';
            meta.textContent = item.isFolder ? item.kindLabel : formatDate(item.modifiedTime);
            li.appendChild(meta);

            const open = document.createElement('a');
            open.href = item.webViewLink;
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.className = 'pm-picker__open';
            open.textContent = 'Ouvrir ↗';
            open.setAttribute('aria-label', 'Ouvrir ' + item.name + ' dans Google Drive');
            li.appendChild(open);

            return li;
        }
    }

    // ── Fenêtre « sélecteur » (joindre des fichiers / choisir le dossier du projet) ──
    const pickerDialog = document.getElementById('pm-drive-picker');
    if (pickerDialog) {
        const confirmBtn = pickerDialog.querySelector('[data-pm-picker-confirm]');
        const folderBtn = pickerDialog.querySelector('[data-pm-picker-folder]');
        const hint = pickerDialog.querySelector('[data-pm-picker-hint]');
        let context = null;

        const picker = new DriveBrowser(pickerDialog, {
            mode: 'attach',
            onChange: (browser) => {
                if (browser.options.mode === 'attach') {
                    confirmBtn.disabled = browser.selected.size === 0;
                    confirmBtn.textContent = browser.selected.size > 1 ? 'Joindre les ' + browser.selected.size + ' éléments' : 'Joindre la sélection';
                } else {
                    folderBtn.disabled = browser.tab !== 'browse' || browser.folderId === 'root';
                }
            },
        });

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-pm-drive-open]');
            if (!trigger) { return; }
            event.preventDefault();
            context = {
                mode: trigger.dataset.mode === 'folder' ? 'folder' : 'attach',
                target: trigger.dataset.target,
                targetId: Number(trigger.dataset.targetId),
                folderUrl: trigger.dataset.folderUrl,
            };
            confirmBtn.hidden = context.mode !== 'attach';
            folderBtn.hidden = context.mode !== 'folder';
            hint.textContent = context.mode === 'folder'
                ? 'Ouvre le dossier voulu, puis « Choisir ce dossier ».'
                : 'Coche un ou plusieurs éléments. Clique sur un dossier pour l\'ouvrir.';
            picker.reset(context.mode, trigger.dataset.startFolder);
            openDialog(pickerDialog);
        });

        confirmBtn.addEventListener('click', async () => {
            if (!context || picker.selected.size === 0) { return; }
            confirmBtn.disabled = true;
            const result = await requestJson(root.dataset.urlAttach, {
                method: 'POST',
                body: JSON.stringify({target: context.target, targetId: context.targetId, fileIds: Array.from(picker.selected.keys())}),
            });
            if (result.ok) {
                location.reload();
            } else {
                confirmBtn.disabled = false;
                toast(result.error || 'Impossible de joindre ces fichiers.', true);
            }
        });

        folderBtn.addEventListener('click', async () => {
            if (!context || !context.folderUrl) { return; }
            folderBtn.disabled = true;
            const result = await requestJson(context.folderUrl, {method: 'POST', body: JSON.stringify({folderId: picker.folderId})});
            if (result.ok) {
                location.reload();
            } else {
                folderBtn.disabled = false;
                toast(result.error || 'Impossible de rattacher ce dossier.', true);
            }
        });
    }

    // ── Navigateur intégré à la page « Drive » ──
    const browserSection = document.querySelector('[data-pm-drive-browser]');
    if (browserSection) {
        new DriveBrowser(browserSection, {mode: 'browse'}).reset('browse', 'root');
    }
})();

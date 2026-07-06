/**
 * matching-form.js — Formulaire multi-étapes de matching (section home)
 *
 * RÔLE :
 *   Gère la navigation entre les 3 étapes du formulaire de matching affiché
 *   sur la page d'accueil aux utilisateurs CONNECTÉS avec un profil incomplet.
 *
 * ADR-0033 (2026-07-06) : ce script gérait auparavant DEUX publics (visiteurs
 * non connectés + utilisateurs connectés). Depuis cet ADR, un visiteur non
 * connecté ne voit plus jamais ce formulaire (mur de connexion à la place,
 * cf. matching/_matching_login_wall.html.twig) — toute la logique spécifique
 * aux visiteurs (redirection vers /register, modal "crée ton compte") a donc
 * été retirée de ce fichier.
 *
 * PROGRESSIVE ENHANCEMENT :
 *   Sans ce script, le formulaire est entièrement fonctionnel :
 *     - Les fieldsets avec [hidden] sont masqués par HTML5 nativement.
 *     - Le bouton "Trouver mes matchs" (type="submit") est visible et soumettable.
 *     - Toutes les étapes sont soumises en une seule fois via POST.
 *     - Note : [hidden] masque les étapes 2 et 3 sans JS, donc l'utilisateur
 *       ne peut remplir que l'étape 1. Pour le fallback complet sans JS,
 *       la balise <noscript> guide l'utilisateur.
 *
 *   Avec ce script :
 *     - Navigation étape par étape (Retour / Continuer)
 *     - Barre de progression mise à jour dynamiquement
 *     - Validation locale avant de passer à l'étape suivante
 *     - Sauvegarde AJAX de chaque étape en session (POST /matching/step)
 *
 * GESTION DU CHAMP "AUTRE" :
 *   Le toggle de la zone textuelle "Autre" dans les options lookingFor
 *   est géré par ce script (délégation d'événement sur le formulaire),
 *   car le partial matching/_step_looking_for.html.twig peut être inclus
 *   dans des contextes différents.
 *
 * prefers-reduced-motion :
 *   Les transitions d'étapes sont instantanées si cette préférence est active.
 *
 * Compatibilité : ES2020+. Pas de transpilation.
 */
(function () {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // CONFIGURATION
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Durée de la transition entre étapes (ms).
     * Mise à 0 si prefers-reduced-motion est actif.
     */
    var TRANSITION_MS = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180;

    /**
     * Nombre total d'étapes dans le formulaire.
     */
    var TOTAL_STEPS = 3;

    // ═══════════════════════════════════════════════════════════════════════════
    // INITIALISATION
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Point d'entrée — appelé quand le DOM est prêt.
     */
    function init() {
        var wrap = document.getElementById('matching-form-wrap');
        if (!wrap) {
            // Le formulaire n'est pas présent sur cette page (artiste avec profil complet,
            // utilisateur non artiste connecté sans besoin de formulaire, ou visiteur non
            // connecté — qui voit le mur de connexion depuis ADR-0033) — rien à faire.
            return;
        }

        var form      = document.getElementById('matching-multi-form');
        var progress  = document.getElementById('mf-progress');
        var btnPrev   = document.getElementById('mf-btn-prev');
        var btnNext   = document.getElementById('mf-btn-next');
        var btnSubmit = document.getElementById('mf-btn-submit');

        if (!form) { return; }

        // Étape courante (1-indexée)
        var currentStep = 1;

        // ── Affichage initial ────────────────────────────────────────────────
        // On retire le [hidden] de l'étape 1 et on configure les boutons.
        // shouldFocus = false : PAS de focus au chargement (sinon la page
        // défilerait automatiquement jusqu'au formulaire matching).
        showStep(1, form, btnPrev, btnNext, btnSubmit, false);

        // ── Bouton "Continuer" ───────────────────────────────────────────────
        // ADR-0033 : ce formulaire n'est plus jamais affiché à un visiteur non
        // connecté — on ne gère donc plus qu'un seul cas : sauvegarde best-effort
        // en session (pour un éventuel rechargement de page) + navigation normale.
        if (btnNext) {
            btnNext.addEventListener('click', function () {
                // Validation de l'étape courante avant de continuer
                if (!validateStep(currentStep, form)) {
                    return;
                }

                saveStepAjax(currentStep, form);
                currentStep++;
                showStep(currentStep, form, btnPrev, btnNext, btnSubmit, true);
                updateProgress(currentStep, progress);
            });
        }

        // ── Bouton "Retour" ──────────────────────────────────────────────────
        if (btnPrev) {
            btnPrev.addEventListener('click', function () {
                if (currentStep <= 1) { return; }
                currentStep--;
                showStep(currentStep, form, btnPrev, btnNext, btnSubmit, true);
                updateProgress(currentStep, progress);
            });
        }

        // ── Soumission du formulaire ─────────────────────────────────────────
        // L'utilisateur est forcément connecté (cf. ADR-0033) : on laisse le POST
        // s'effectuer normalement (MatchingFormController::submit(), Flux B) après
        // validation locale de la dernière étape.
        if (btnSubmit) {
            btnSubmit.addEventListener('click', function (e) {
                // Validation de la dernière étape avant soumission
                if (!validateStep(currentStep, form)) {
                    e.preventDefault();
                    return;
                }
                // Aucune interception : la soumission continue normalement (POST classique)
            });
        }

        // ── Délégation d'événement pour le toggle "Autre" ───────────────────
        // On écoute les changements sur toutes les checkboxes .mf-cb-autre
        // du formulaire (pattern de délégation : efficace et résilient).
        form.addEventListener('change', function (e) {
            var target = e.target;
            if (target && target.classList.contains('mf-cb-autre')) {
                // On cherche le .mf-autre-field le plus proche dans le même fieldset
                var fieldset = target.closest('fieldset') || form;
                var autreField = fieldset.querySelector('.mf-autre-field');
                var autreInput = fieldset.querySelector('.mf-other-input');
                if (autreField) {
                    if (target.checked) {
                        autreField.classList.add('visible');
                        if (autreInput) { autreInput.setAttribute('required', 'required'); }
                    } else {
                        autreField.classList.remove('visible');
                        if (autreInput) {
                            autreInput.removeAttribute('required');
                            autreInput.value = '';
                        }
                    }
                }
            }
        });

        // ── Filtre de disciplines ────────────────────────────────────────────
        var discFilter = document.getElementById('mf-disc-filter');
        if (discFilter) {
            discFilter.addEventListener('input', function () {
                filterDisciplines(discFilter.value, form);
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // NAVIGATION ENTRE ÉTAPES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Affiche l'étape demandée et masque les autres.
     * Configure la visibilité des boutons Retour/Continuer/Soumettre.
     *
     * @param {number} step - Numéro de l'étape à afficher (1-indexé)
     * @param {HTMLFormElement} form
     * @param {HTMLButtonElement|null} btnPrev
     * @param {HTMLButtonElement|null} btnNext
     * @param {HTMLButtonElement|null} btnSubmit
     */
    function showStep(step, form, btnPrev, btnNext, btnSubmit, shouldFocus) {
        // Afficher/masquer les fieldsets
        var fieldsets = form.querySelectorAll('.mf-step');
        fieldsets.forEach(function (fs, idx) {
            var isTarget = (idx + 1) === step;
            if (isTarget) {
                fs.removeAttribute('hidden');
                // Focus sur le premier champ de l'étape (accessibilité) — MAIS
                // UNIQUEMENT lors d'une navigation entre étapes (clic Continuer/Retour),
                // PAS au chargement initial. Sinon le focus ferait défiler la page
                // jusqu'au formulaire dès l'ouverture/l'actualisation de la home
                // (bug « la page saute à la section matching »). shouldFocus vaut
                // false à l'init, true lors des navigations.
                if (shouldFocus) {
                    setTimeout(function () {
                        var firstInput = fs.querySelector('input, button, select, textarea');
                        if (firstInput) { firstInput.focus(); }
                    }, TRANSITION_MS);
                }
            } else {
                fs.setAttribute('hidden', '');
            }
        });

        // Bouton "Retour" : caché à l'étape 1
        if (btnPrev) {
            if (step > 1) { btnPrev.removeAttribute('hidden'); }
            else          { btnPrev.setAttribute('hidden', ''); }
        }

        // Bouton "Continuer" : visible aux étapes 1 et 2
        if (btnNext) {
            if (step < TOTAL_STEPS) { btnNext.removeAttribute('hidden'); }
            else                    { btnNext.setAttribute('hidden', ''); }
        }

        // Bouton "Soumettre" : visible uniquement à la dernière étape
        if (btnSubmit) {
            if (step >= TOTAL_STEPS) { btnSubmit.removeAttribute('hidden'); }
            else                     { btnSubmit.setAttribute('hidden', ''); }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MISE À JOUR DE LA BARRE DE PROGRESSION
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Met à jour visuellement la barre de progression.
     *
     * @param {number} step - Étape courante (1-indexée)
     * @param {HTMLElement|null} progress - Conteneur de la barre de progression
     */
    function updateProgress(step, progress) {
        if (!progress) { return; }

        var dots = progress.querySelectorAll('.mf-step-dot');
        dots.forEach(function (dot, idx) {
            dot.classList.remove('mf-step-dot--active', 'mf-step-dot--done');
            if (idx + 1 < step) {
                dot.classList.add('mf-step-dot--done');
            } else if (idx + 1 === step) {
                dot.classList.add('mf-step-dot--active');
            }
        });

        // Mise à jour du label texte et des attributs ARIA
        var label = progress.querySelector('.mf-progress__label');
        if (label) {
            label.textContent = 'Étape ' + step + ' / ' + TOTAL_STEPS;
        }
        progress.setAttribute('aria-valuenow', String(step));
        progress.setAttribute('aria-label', 'Étape ' + step + ' sur ' + TOTAL_STEPS);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // VALIDATION LOCALE (côté client)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Valide les données de l'étape courante avant de passer à la suivante.
     * Affiche un message d'erreur si la validation échoue.
     *
     * @param {number} step - Numéro de l'étape à valider
     * @param {HTMLFormElement} form
     * @returns {boolean} true si valide, false sinon
     */
    function validateStep(step, form) {
        clearStepError(step);

        if (step === 1) {
            // Étape 1 (Option B) : nom + localisation requis, puis au moins une discipline.
            // Ces deux champs sont indispensables pour qu'un profil soit « complet »
            // côté serveur (MatchingProfileChecker), donc on les exige dès l'étape 1.
            var nameInput = form.querySelector('input[name="display_name"]');
            if (nameInput && nameInput.value.trim() === '') {
                showStepError(step, 'Indique ton nom d\'artiste pour continuer.');
                nameInput.focus();
                return false;
            }
            // Le champ localisation est un <select> (pas un <input>) : on utilise un
            // sélecteur sans préfixe de balise pour matcher <input>, <select> ET <textarea>.
            // Avec 'input[name="location"]', querySelector renvoyait null → la localisation
            // n'était jamais validée ni envoyée en session (bug carryover matching).
            var locInput = form.querySelector('[name="location"]');
            if (locInput && locInput.value.trim() === '') {
                showStepError(step, 'Indique ta localisation (ville, pays) pour continuer.');
                locInput.focus();
                return false;
            }
            // Au moins une discipline cochée
            var checked = form.querySelectorAll('input[name="disciplines[]"]:checked');
            if (checked.length === 0) {
                showStepError(step, 'Coche au moins une discipline artistique pour continuer.');
                return false;
            }
        }

        if (step === 2) {
            // Étape 2 : au moins un lookingFor coché
            var checkedLF = form.querySelectorAll('input[name="looking_for[]"]:checked');
            if (checkedLF.length === 0) {
                showStepError(step, 'Coche au moins une option pour continuer.');
                return false;
            }
            // Si "autre" est coché, le champ texte est requis
            var autreChecked = form.querySelector('input[name="looking_for[]"][value="autre"]:checked');
            if (autreChecked) {
                var otherInput = form.querySelector('.mf-other-input');
                if (otherInput && otherInput.value.trim() === '') {
                    showStepError(step, 'Précise ce que tu recherches d\'autre dans le champ texte.');
                    if (otherInput) { otherInput.focus(); }
                    return false;
                }
            }
        }

        // Étape 3 : pas de validation obligatoire (statut optionnel)
        return true;
    }

    /**
     * Affiche un message d'erreur pour une étape donnée.
     *
     * @param {number} step
     * @param {string} message
     */
    function showStepError(step, message) {
        var errEl = document.getElementById('mf-step' + step + '-error');
        if (errEl) {
            errEl.textContent = message;
            errEl.style.display = 'block';
            errEl.focus(); // accessibilité : focus sur le message d'erreur
        }
    }

    /**
     * Efface le message d'erreur d'une étape.
     *
     * @param {number} step
     */
    function clearStepError(step) {
        var errEl = document.getElementById('mf-step' + step + '-error');
        if (errEl) {
            errEl.textContent = '';
            errEl.style.display = 'none';
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SAUVEGARDE AJAX EN SESSION
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Sauvegarde les données de l'étape en session via AJAX.
     * Appel best-effort : en cas d'échec réseau, on continue quand même
     * (les données seront sauvegardées à la soumission finale de toute façon).
     *
     * L'URL de l'endpoint est lue depuis data-step-url sur #matching-form-wrap,
     * avec un repli sur '/matching/step' si l'attribut est absent.
     * Cette approche évite de coder l'URL en dur dans le JS (fragile si le
     * préfixe de route change ou si Symfony est servi dans un sous-dossier).
     *
     * @param {number} step - Numéro de l'étape
     * @param {HTMLFormElement} form
     * @param {Function|null} callback - Fonction appelée après la réponse (succès ou échec)
     */
    function saveStepAjax(step, form, callback) {
        // Construction des données du FormData de l'étape en cours
        var fd = new FormData();
        fd.append('step', String(step));

        // Récupère le token CSRF depuis le champ caché du formulaire
        var csrfInput = form.querySelector('input[name="_csrf_token"]');
        if (csrfInput) {
            fd.append('_csrf_token', csrfInput.value);
        }

        // Collecte des données selon l'étape
        if (step === 1) {
            // Nom + localisation (Option B) — envoyés en session pour le carryover
            var nameInput = form.querySelector('input[name="display_name"]');
            if (nameInput) {
                fd.append('display_name', nameInput.value.trim());
            }
            // Le champ localisation est un <select> (pas un <input>) : on utilise un
            // sélecteur sans préfixe de balise pour matcher <input>, <select> ET <textarea>.
            // Avec 'input[name="location"]', querySelector renvoyait null → la localisation
            // n'était jamais validée ni envoyée en session (bug carryover matching).
            var locInput = form.querySelector('[name="location"]');
            if (locInput) {
                fd.append('location', locInput.value.trim());
            }
            // Disciplines cochées
            form.querySelectorAll('input[name="disciplines[]"]:checked').forEach(function (cb) {
                fd.append('disciplines[]', cb.value);
            });
        } else if (step === 2) {
            // LookingFor cochées
            form.querySelectorAll('input[name="looking_for[]"]:checked').forEach(function (cb) {
                fd.append('looking_for[]', cb.value);
            });
            // Champ libre "Autre"
            var otherInput = form.querySelector('.mf-other-input');
            if (otherInput && otherInput.value.trim() !== '') {
                fd.append('looking_for_other', otherInput.value.trim());
            }
        } else if (step === 3) {
            // Statut juridique
            var lsChecked = form.querySelector('input[name="legal_status"]:checked');
            if (lsChecked) {
                fd.append('legal_status', lsChecked.value);
            }
        }

        // Lecture de l'URL depuis data-step-url (posé par le template Twig via path()).
        // Repli sur '/matching/step' si le data-attribute est absent ou vide (défensif).
        var wrap    = document.getElementById('matching-form-wrap');
        var stepUrl = (wrap && wrap.dataset.stepUrl) ? wrap.dataset.stepUrl : '/matching/step';

        // Appel AJAX — endpoint POST (URL résolue dynamiquement)
        fetch(stepUrl, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        })
        .then(function (response) {
            // On logge l'erreur AJAX mais on n'interrompt pas l'UX.
            // Les données seront sauvegardées à la soumission finale.
            if (!response.ok) {
                console.warn('[matching-form] Sauvegarde étape ' + step + ' échouée :', response.status);
            }
            if (callback) { callback(); }
        })
        .catch(function (err) {
            // Erreur réseau : on continue silencieusement
            console.warn('[matching-form] Erreur réseau lors de la sauvegarde :', err);
            if (callback) { callback(); }
        });
    }

    // ADR-0033 : showSignupCta()/closeSignupCta() (modal "crée ton compte pour
    // aller plus loin", visiteurs uniquement) ont été retirées — ce cas ne se
    // produit plus jamais, le formulaire n'étant plus rendu pour un visiteur
    // non connecté (mur de connexion à la place, cf. HomeController::index()).

    // ═══════════════════════════════════════════════════════════════════════════
    // FILTRE DE DISCIPLINES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Filtre la liste de disciplines selon le texte saisi.
     * Masque les éléments dont le label ne contient pas le terme de recherche.
     *
     * @param {string} query - Texte de recherche (insensible à la casse)
     * @param {HTMLFormElement} form
     */
    function filterDisciplines(query, form) {
        var normalized = query.toLowerCase().trim();
        var items = form.querySelectorAll('.ob-disc-item');
        items.forEach(function (item) {
            var label = item.querySelector('label');
            var text  = label ? label.textContent.toLowerCase() : '';
            item.style.display = (normalized === '' || text.indexOf(normalized) !== -1)
                ? ''
                : 'none';
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DÉMARRAGE
    // ═══════════════════════════════════════════════════════════════════════════

    // On attend que le DOM soit complètement chargé avant d'initialiser.
    // Si le script est chargé en <script defer>, DOMContentLoaded est déjà passé.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());

/**
 * association-projects-editor.js : éditeur de texte enrichi « Le projet en détail »
 * du back-office des fiches « Projets en cours » (ADR-0039, complément d'octobre 2026).
 *
 * MAISON, SANS DÉPENDANCE : une zone « contenteditable » et les commandes de mise
 * en forme du navigateur (document.execCommand). Ces commandes sont marquées
 * « obsolètes » dans la documentation mais restent prises en charge par tous les
 * navigateurs ; aucune alternative native ne les remplace à ce jour.
 *
 * CE QUE FAIT CE FICHIER :
 *   - remplace le <textarea data-rich-editor> par une barre d'outils et une zone
 *     de saisie mise en forme ;
 *   - nettoie ce qui est collé (Word, Google Docs, pages web) : ne garde que les
 *     intertitres, paragraphes, gras, italique, listes, citations et liens ;
 *   - envoie les images au serveur dès qu'on les insère (bouton, glisser-déposer
 *     ou capture d'écran collée), puis les place dans le texte ;
 *   - au moment d'enregistrer, recopie le texte dans le <textarea> d'origine.
 *
 * LA SÉCURITÉ NE REPOSE PAS SUR CE FICHIER : le serveur nettoie à nouveau tout le
 * texte (AssociationProjectRichText, liste blanche) à l'enregistrement et à
 * l'affichage. Le nettoyage d'ici sert au confort : voir tout de suite le texte
 * tel qu'il sera publié.
 */
(function () {
    'use strict';

    var KEPT = { P: 1, BR: 1, STRONG: 1, EM: 1, H2: 1, H3: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, A: 1, IMG: 1 };
    var RENAMED = { B: 'STRONG', I: 'EM', H1: 'H2', H4: 'H3', H5: 'H3', H6: 'H3', DIV: 'P' };
    var DROPPED = {
        SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, NOSCRIPT: 1, TEMPLATE: 1, SVG: 1, MATH: 1,
        FORM: 1, INPUT: 1, BUTTON: 1, SELECT: 1, TEXTAREA: 1, HEAD: 1, TITLE: 1, META: 1, LINK: 1,
        VIDEO: 1, AUDIO: 1, CANVAS: 1
    };
    var BLOCKS = { P: 1, H2: 1, H3: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, DIV: 1, H1: 1, H4: 1, H5: 1, H6: 1 };
    var IMAGE_NAME = /^[a-f0-9]{32}\.(jpg|png|webp)$/;
    var ACCEPTED_IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    var BUTTONS = [
        { command: 'block', value: 'P', label: 'Paragraphe', title: 'Paragraphe normal' },
        { command: 'block', value: 'H2', label: 'Intertitre', title: 'Intertitre' },
        { command: 'block', value: 'H3', label: 'Sous-titre', title: 'Sous-intertitre' },
        { separator: true },
        { command: 'bold', label: 'G', title: 'Gras (Ctrl+B)', className: 'is-bold' },
        { command: 'italic', label: 'I', title: 'Italique (Ctrl+I)', className: 'is-italic' },
        { separator: true },
        { command: 'insertUnorderedList', label: '• Liste', title: 'Liste à puces' },
        { command: 'insertOrderedList', label: '1. Liste', title: 'Liste numérotée' },
        { command: 'block', value: 'BLOCKQUOTE', label: '« Citation »', title: 'Citation' },
        { separator: true },
        { command: 'link', label: 'Lien', title: 'Ajouter un lien sur le texte sélectionné' },
        { command: 'unlink', label: 'Retirer le lien', title: 'Retirer le lien' },
        { command: 'image', label: 'Image', title: 'Insérer une image (JPEG, PNG ou WebP, 5 Mo maximum)' },
        { separator: true },
        { command: 'undo', label: '↶', title: 'Annuler (Ctrl+Z)' },
        { command: 'redo', label: '↷', title: 'Rétablir (Ctrl+Maj+Z)' }
    ];

    /* ── Nettoyage (même liste blanche que le serveur) ─────────────────────── */

    function styleOf(element) {
        return (element.getAttribute('style') || '').toLowerCase();
    }

    function safeHref(href) {
        href = (href || '').trim();
        return /^(https?:\/\/|mailto:)/i.test(href) ? href : null;
    }

    /** Image gardée seulement si elle vient de cet éditeur (nom de fichier connu). */
    function keptImage(source) {
        var name = source.getAttribute('data-ap-image') || '';
        if (!IMAGE_NAME.test(name)) {
            return null;
        }
        var image = document.createElement('img');
        image.setAttribute('data-ap-image', name);
        image.setAttribute('alt', (source.getAttribute('alt') || '').replace(/\s+/g, ' ').trim().slice(0, 200));
        ['width', 'height'].forEach(function (dimension) {
            var value = source.getAttribute(dimension);
            if (/^\d{1,5}$/.test(value || '')) {
                image.setAttribute(dimension, value);
            }
        });
        // Adresse : seulement une adresse de ce back-office (jamais une image externe).
        var src = source.getAttribute('src') || '';
        if (src.indexOf('/admin/fiches-projets/') === 0 || src.indexOf(window.location.origin + '/admin/fiches-projets/') === 0) {
            image.setAttribute('src', src);
        }
        return image;
    }

    function hasBlockChild(element) {
        for (var i = 0; i < element.children.length; i++) {
            if (BLOCKS[element.children[i].tagName.toUpperCase()]) {
                return true;
            }
        }
        return false;
    }

    function cleanInto(source, target) {
        Array.prototype.forEach.call(source.childNodes, function (child) {
            if (child.nodeType === Node.TEXT_NODE) {
                target.appendChild(document.createTextNode(child.nodeValue));
                return;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) {
                return; // commentaires, etc.
            }
            var tag = child.tagName.toUpperCase();
            if (DROPPED[tag]) {
                return;
            }
            var style = styleOf(child);

            // Google Docs entoure tout le texte copié d'un <b style="font-weight:normal">.
            if (tag === 'B' && /font-weight:\s*(normal|400)/.test(style)) {
                cleanInto(child, target);
                return;
            }
            // Google Docs et Word marquent le gras et l'italique par des styles sur des <span>.
            if (tag === 'SPAN') {
                var holder = target;
                if (/font-weight:\s*(bold|[6-9]00)/.test(style)) {
                    holder = holder.appendChild(document.createElement('strong'));
                }
                if (/font-style:\s*italic/.test(style)) {
                    holder = holder.appendChild(document.createElement('em'));
                }
                cleanInto(child, holder);
                return;
            }
            if (tag === 'DIV' && hasBlockChild(child)) {
                cleanInto(child, target);
                return;
            }

            var name = RENAMED[tag] || tag;
            if (!KEPT[name]) {
                cleanInto(child, target);
                return;
            }
            if (name === 'IMG') {
                var image = keptImage(child);
                if (image) {
                    target.appendChild(image);
                }
                return;
            }
            var element = document.createElement(name);
            if (name === 'A') {
                var href = safeHref(child.getAttribute('href'));
                if (!href) {
                    cleanInto(child, target);
                    return;
                }
                element.setAttribute('href', href);
            }
            if (name !== 'BR') {
                cleanInto(child, element);
            }
            target.appendChild(element);
        });
    }

    /** HTML nettoyé à partir d'un HTML quelconque (collé, ou contenu de l'éditeur). */
    function cleanHtml(html) {
        // DOMParser crée un document INERTE : aucun script n'y tourne, aucune image
        // n'y est chargée. On n'en recopie ensuite que ce qui est autorisé.
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var container = document.createElement('div');
        cleanInto(parsed.body, container);
        return container.innerHTML;
    }

    /** Texte vide (seulement des espaces ou des sauts de ligne), sans image. */
    function isEmpty(element) {
        return element.textContent.replace(/[\s ]+/g, '') === '' && !element.querySelector('img');
    }

    /* ── Éditeur ────────────────────────────────────────────────────────────── */

    function setup(textarea) {
        var uploadUrl = textarea.dataset.uploadUrl || '';
        var uploadToken = textarea.dataset.uploadToken || '';
        var max = parseInt(textarea.dataset.max || '0', 10);
        var labelId = textarea.id + '-label';
        var savedRange = null;

        var wrapper = document.createElement('div');
        wrapper.className = 'adm-ap-editor';

        var toolbar = document.createElement('div');
        toolbar.className = 'adm-ap-editor__toolbar';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Mise en forme du texte');

        var editor = document.createElement('div');
        editor.className = 'adm-ap-editor__area adm-ap-article';
        editor.contentEditable = 'true';
        editor.setAttribute('role', 'textbox');
        editor.setAttribute('aria-multiline', 'true');
        if (document.getElementById(labelId)) {
            editor.setAttribute('aria-labelledby', labelId);
        }
        if (textarea.getAttribute('aria-describedby')) {
            editor.setAttribute('aria-describedby', textarea.getAttribute('aria-describedby'));
        }

        var status = document.createElement('p');
        status.className = 'adm-ap-editor__status';
        status.setAttribute('aria-live', 'polite');

        var counter = document.createElement('span');
        counter.className = 'adm-ap-counter';

        var help = document.createElement('p');
        help.className = 'adm-ap-hint';
        help.textContent = uploadUrl
            ? 'Image : cliquez dessus puis Suppr pour la retirer, double-cliquez pour modifier sa description.'
            : '';

        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = ACCEPTED_IMAGES.join(',');
        fileInput.hidden = true;

        BUTTONS.forEach(function (definition) {
            if (definition.separator) {
                var separator = document.createElement('span');
                separator.className = 'adm-ap-editor__separator';
                separator.setAttribute('aria-hidden', 'true');
                toolbar.appendChild(separator);
                return;
            }
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'adm-ap-editor__button' + (definition.className ? ' ' + definition.className : '');
            button.textContent = definition.label;
            button.title = definition.title;
            button.setAttribute('aria-label', definition.title);
            button.dataset.command = definition.command;
            if (definition.value) {
                button.dataset.value = definition.value;
            }
            if (definition.command === 'image' && !uploadUrl) {
                button.disabled = true;
                button.title = "Créez d'abord la fiche pour pouvoir y ajouter des images.";
                button.setAttribute('aria-label', button.title);
            }
            // mousedown + preventDefault : le clic ne fait pas perdre la sélection du texte.
            button.addEventListener('mousedown', function (event) { event.preventDefault(); });
            button.addEventListener('click', function () { run(definition.command, definition.value); });
            toolbar.appendChild(button);
        });

        editor.innerHTML = textarea.value.trim() !== '' ? cleanHtml(textarea.value) : '';
        if (isEmpty(editor)) {
            editor.innerHTML = '<p><br></p>';
        }

        wrapper.appendChild(toolbar);
        wrapper.appendChild(editor);
        wrapper.appendChild(status);
        textarea.parentNode.insertBefore(wrapper, textarea);
        textarea.parentNode.insertBefore(help, textarea);
        textarea.parentNode.insertBefore(counter, textarea);
        textarea.parentNode.insertBefore(fileInput, textarea);
        textarea.hidden = true;

        // Cliquer sur le libellé du champ place le curseur dans l'éditeur.
        var label = document.getElementById(labelId);
        if (label) {
            label.addEventListener('click', function (event) { event.preventDefault(); editor.focus(); });
        }

        try {
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand('styleWithCSS', false, false);
        } catch (ignored) { /* navigateur ancien : sans effet gênant */ }

        function say(message, isError) {
            status.textContent = message;
            status.classList.toggle('is-error', Boolean(isError));
        }

        function updateCounter() {
            if (!max) { return; }
            var length = editor.textContent.length;
            counter.textContent = length.toLocaleString('fr-FR') + ' / ' + max.toLocaleString('fr-FR') + ' caractères';
            counter.classList.toggle('is-over', length > max);
        }

        function saveSelection() {
            var selection = window.getSelection();
            if (selection.rangeCount > 0 && editor.contains(selection.getRangeAt(0).commonAncestorContainer)) {
                savedRange = selection.getRangeAt(0).cloneRange();
            }
        }

        function restoreSelection() {
            editor.focus();
            if (savedRange) {
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(savedRange);
            }
        }

        function currentBlock() {
            var value = (document.queryCommandValue('formatBlock') || '').toUpperCase().replace(/[<>]/g, '');
            return value === 'DIV' || value === '' ? 'P' : value;
        }

        function run(command, value) {
            editor.focus();
            if (command === 'block') {
                // Cliquer à nouveau sur « Intertitre » dans un intertitre le remet en paragraphe.
                var target = currentBlock() === value ? 'P' : value;
                document.execCommand('formatBlock', false, '<' + target.toLowerCase() + '>');
            } else if (command === 'link') {
                addLink();
            } else if (command === 'image') {
                saveSelection();
                fileInput.click();
            } else {
                document.execCommand(command, false, null);
            }
            refreshButtons();
            updateCounter();
        }

        function addLink() {
            var selection = window.getSelection();
            var text = selection ? selection.toString() : '';
            var answer = window.prompt('Adresse du lien (https://… ou adresse e-mail) :', 'https://');
            if (answer === null) { return; }
            answer = answer.trim();
            if (answer === '' || answer === 'https://') { return; }
            if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(answer)) {
                answer = 'mailto:' + answer;
            } else if (!/^(https?:\/\/|mailto:)/i.test(answer)) {
                answer = 'https://' + answer.replace(/^\/+/, '');
            }
            if (text.trim() === '') {
                var link = document.createElement('a');
                link.href = answer;
                link.textContent = answer.replace(/^mailto:/i, '');
                document.execCommand('insertHTML', false, link.outerHTML);
            } else {
                document.execCommand('createLink', false, answer);
            }
        }

        function refreshButtons() {
            var block = currentBlock();
            toolbar.querySelectorAll('button').forEach(function (button) {
                var command = button.dataset.command;
                var active = false;
                if (command === 'block') {
                    active = block === button.dataset.value;
                } else if (command === 'bold' || command === 'italic' || command === 'insertUnorderedList' || command === 'insertOrderedList') {
                    try { active = document.queryCommandState(command); } catch (ignored) { active = false; }
                }
                if (command === 'block' || command === 'bold' || command === 'italic' || command === 'insertUnorderedList' || command === 'insertOrderedList') {
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                }
            });
        }

        /* Images : envoi au serveur, puis insertion à l'endroit du curseur. */
        function uploadAndInsert(file) {
            if (!uploadUrl) {
                say("Créez d'abord la fiche pour pouvoir y ajouter des images.", true);
                return;
            }
            if (ACCEPTED_IMAGES.indexOf(file.type) === -1) {
                say('Format non accepté. Formats acceptés : JPEG, PNG, WebP.', true);
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                say("L'image ne doit pas dépasser 5 Mo.", true);
                return;
            }
            say('Envoi de l’image…', false);
            var data = new FormData();
            data.append('image', file);
            data.append('_token', uploadToken);
            fetch(uploadUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { error: "L'image n'a pas pu être envoyée (fichier trop lourd ?)." };
                    });
                })
                .then(function (result) {
                    if (!result || result.error || !result.name) {
                        say((result && result.error) || "L'image n'a pas pu être envoyée.", true);
                        return;
                    }
                    var alt = window.prompt("Décrivez l'image en quelques mots (pour les personnes qui ne la voient pas) :", '');
                    var image = document.createElement('img');
                    image.setAttribute('data-ap-image', result.name);
                    image.setAttribute('src', result.url);
                    image.setAttribute('alt', (alt || '').trim().slice(0, 200));
                    if (result.width) { image.setAttribute('width', String(result.width)); }
                    if (result.height) { image.setAttribute('height', String(result.height)); }
                    restoreSelection();
                    document.execCommand('insertHTML', false, '<p>' + image.outerHTML + '</p>');
                    say("Image ajoutée. Elle sera visible par les partenaires une fois la fiche enregistrée.", false);
                    updateCounter();
                })
                .catch(function () {
                    say("L'image n'a pas pu être envoyée (connexion interrompue ?).", true);
                });
        }

        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                uploadAndInsert(fileInput.files[0]);
            }
            fileInput.value = '';
        });

        /* Collage : texte nettoyé ; une capture d'écran collée est envoyée comme image. */
        editor.addEventListener('paste', function (event) {
            var clipboard = event.clipboardData;
            if (!clipboard) { return; }
            event.preventDefault();
            var files = Array.prototype.filter.call(clipboard.files || [], function (file) {
                return ACCEPTED_IMAGES.indexOf(file.type) !== -1;
            });
            var html = clipboard.getData('text/html');
            if (files.length > 0 && !html) {
                saveSelection();
                uploadAndInsert(files[0]);
                return;
            }
            if (html) {
                document.execCommand('insertHTML', false, cleanHtml(html));
            } else {
                document.execCommand('insertText', false, clipboard.getData('text/plain'));
            }
            updateCounter();
        });

        /* Glisser-déposer : une image déposée est envoyée ; jamais de fichier inséré tel quel. */
        editor.addEventListener('drop', function (event) {
            var files = event.dataTransfer ? event.dataTransfer.files : null;
            if (!files || files.length === 0) { return; }
            event.preventDefault();
            saveSelection();
            uploadAndInsert(files[0]);
        });

        /* Image : un clic la sélectionne (Suppr la retire), un double-clic modifie sa description. */
        editor.addEventListener('click', function (event) {
            if (event.target.tagName === 'IMG') {
                var range = document.createRange();
                range.selectNode(event.target);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            }
        });
        editor.addEventListener('dblclick', function (event) {
            if (event.target.tagName === 'IMG') {
                var alt = window.prompt("Description de l'image :", event.target.getAttribute('alt') || '');
                if (alt !== null) {
                    event.target.setAttribute('alt', alt.trim().slice(0, 200));
                }
            }
        });

        editor.addEventListener('input', function () {
            if (editor.innerHTML === '' || editor.innerHTML === '<br>') {
                editor.innerHTML = '<p><br></p>';
            }
            updateCounter();
        });
        editor.addEventListener('keyup', refreshButtons);
        editor.addEventListener('mouseup', refreshButtons);
        editor.addEventListener('blur', saveSelection);

        /* Enregistrement : le texte nettoyé repart dans le <textarea> d'origine. */
        var form = textarea.form;
        if (form) {
            form.addEventListener('submit', function () {
                textarea.value = isEmpty(editor) ? '' : cleanHtml(editor.innerHTML);
            });
        }

        updateCounter();
        refreshButtons();
    }

    function init() {
        document.querySelectorAll('textarea[data-rich-editor]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());

/**
 * resource-filters.js — Filtrage automatique de la barre de filtres /resources
 *
 * RÔLE :
 *   Sur la page catalogue d'opportunités (templates/resource/index.html.twig),
 *   soumet automatiquement le formulaire de filtres dès qu'un <select> change
 *   de valeur (type, discipline, année de deadline, pays, tri). Gaëlle ne veut
 *   plus que l'utilisateur ait à cliquer sur "FILTRER" après chaque sélection.
 *
 * PROGRESSIVE ENHANCEMENT :
 *   Sans ce script (JS désactivé ou erreur réseau) :
 *     - Le formulaire reste un <form method="get"> classique, entièrement
 *       fonctionnel : chaque select garde sa valeur, le bouton "FILTRER"
 *       reste visible et soumet le formulaire au clic.
 *   Avec ce script :
 *     - Chaque <select data-auto-submit> déclenche form.submit() à l'événement
 *       "change" (donc dès la sélection d'une option, sans clic supplémentaire).
 *     - Le bouton "FILTRER" est masqué (voir classe "filter-bar--js-active",
 *       posée uniquement si ce script s'exécute) puisqu'il devient redondant.
 *
 * CE QUI N'EST PAS AUTO-SOUMIS :
 *   Le champ recherche texte (name="q") est volontairement laissé de côté :
 *   on ne veut pas soumettre le formulaire à chaque frappe clavier. Il reste
 *   soumis via la touche Entrée (comportement natif du <form>) ou le bouton.
 *   Techniquement, on ne sélectionne que les <select data-auto-submit> — le
 *   champ recherche n'a pas cet attribut, donc il n'est jamais concerné.
 *
 * STRUCTURE HTML ATTENDUE :
 *   <form id="resource-filter-form">        → un seul formulaire de filtres
 *     <select data-auto-submit>             → un select par filtre auto-soumis
 *     <button id="resource-filter-submit">  → repli sans JS
 *
 * COMPATIBILITÉ AVEC LE TOGGLE CATALOGUE/MATCHING (ADR-0031) :
 *   Ce script cible explicitement #resource-filter-form et les <select
 *   data-auto-submit> qu'il contient. Il n'attache aucun écouteur global sur
 *   la page, donc il n'interfère pas avec d'éventuels autres formulaires ou
 *   contrôles (ex. le toggle Catalogue/Matching) ajoutés ailleurs sur la page.
 *
 * Compatibilité : ES2020+ (tout navigateur depuis 2020). Pas de transpilation.
 */
(function () {
    'use strict';

    /**
     * Point d'entrée — appelé quand le DOM est prêt.
     */
    function init() {
        var form = document.getElementById('resource-filter-form');

        // Si le formulaire n'est pas présent sur la page courante, on ne fait rien.
        // (Ce script est chargé uniquement sur resource/index.html.twig, mais on
        // reste défensif au cas où le template évoluerait.)
        if (!form) {
            return;
        }

        // On pose la classe qui permet au CSS de masquer le bouton "FILTRER"
        // UNIQUEMENT maintenant que l'on sait que le JS s'exécute réellement.
        // Sans cette ligne (JS désactivé), la classe n'existe jamais et le
        // bouton reste visible — c'est le repli progressive enhancement.
        form.classList.add('filter-bar--js-active');

        // Seuls les selects marqués data-auto-submit déclenchent la soumission
        // automatique. Le champ recherche "q" n'a pas cet attribut : il n'est
        // donc jamais concerné, quelle que soit la frappe de l'utilisateur.
        var autoSubmitSelects = form.querySelectorAll('select[data-auto-submit]');

        autoSubmitSelects.forEach(function (select) {
            select.addEventListener('change', function () {
                // requestSubmit() (plutôt que submit()) redéclenche la validation
                // native du formulaire et respecte l'attribut "action"/"method"
                // sans avoir à re-cliquer sur un bouton — comportement identique
                // à un clic utilisateur sur "FILTRER".
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    // Repli pour les tres anciens navigateurs sans requestSubmit().
                    form.submit();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // Le DOM est déjà prêt (script chargé en "defer" après un DOM déjà parsé
        // dans de rares cas de navigation type bfcache) — on initialise direct.
        init();
    }
})();

---
name: feedback_twig_resource_patterns
description: Patterns et anti-patterns identifiés dans les templates Twig du module Ressources (mai 2026)
metadata:
  type: feedback
---

Anti-patterns récurrents repérés dans resource/*.html.twig (relecture mai 2026) :

**1. CSRF absent sur submit.html.twig**
Le formulaire POST `/resources/submit` n'a aucun `{{ csrf_token() }}` ni `{{ form_start(form) }}`. Le contrôleur ne valide pas non plus de token côté back (contrairement à alerts et favorite_toggle qui valident). C'est un oubli documenté dans le code lui-même (commentaire ligne 435-438 du template).
**Why:** Anti-pattern critique récurrent : les formulaires HTML manuels oublient le CSRF. Alertes et favoris l'ont correctement, submit non.
**How to apply:** Signaler en Critique dès qu'un formulaire POST manuel n'a pas de champ `_token` et que le contrôleur ne valide pas.

**2. Variable statusFilter non transmise par le contrôleur my()**
`my.html.twig` utilise `statusFilter` (filtres par statut, liens GET) mais le contrôleur `my()` ne passe ni `statusFilter` ni ne filtre les ressources par statut. Les boutons de filtre sont rendus mais entièrement non-fonctionnels.
**Why:** Décalage template/contrôleur — fonctionnalité incomplète silencieuse.
**How to apply:** Vérifier systématiquement que chaque variable utilisée dans le template est bien passée par le render() du contrôleur.

**3. N+1 disciplines dans favorites.html.twig**
`findFavoritesByUser()` ne charge pas la relation `disciplines` (pas de `leftJoin('r.disciplines')`). Or `favorites.html.twig` accède à `resource.disciplines|slice(0,2)` pour chaque carte → 1 requête SQL par ressource favorite. findPublished() fait le bon leftJoin.
**Why:** Pattern N+1 identique à celui signalé dans le Forum (voir [[feedback_forum_module_patterns]]).
**How to apply:** Sur toute page de grille/liste qui affiche les disciplines, vérifier que le repository charge la relation en eager.

**4. Badge CSS status-badge--archived non défini dans my.html.twig**
L'enum ResourceStatus a `Archived = 'archived'`. La classe dynamique `status-badge--{{ resource.status.value }}` génèrera `status-badge--archived` mais ce style n'est pas déclaré dans my.html.twig (seuls published/pending/rejected/draft sont stylisés).
**Why:** Le badge "Archivé" s'afficherait sans couleur ni bordure.

**5. Pagination template/bundle désynchronisés dans index.html.twig**
`knp_pagination_render()` est appelé dans index.html.twig mais KnpPaginatorBundle n'est pas installé. Le contrôleur passe un array PHP simple (pas un objet SlidingPagination). La condition `resources.pageCount is defined` est false → bloc ignoré → pas d'erreur fatale mais aucune pagination réelle.
**How to apply:** Signaler comme Avertissement : soit installer KnpPaginatorBundle et paginer dans le contrôleur, soit retirer les références de pagination du template.

**6. Commentaire orphelin (index.html.twig)**
Le commentaire ligne 683-686 décrit un formulaire favori en position absolue dans les cartes, mais ce formulaire n'est pas implémenté dans index.html.twig. Architecture décrite mais non réalisée.

**Ce qui est correct :**
- `{{ parent() }}` présent dans tous les blocs `stylesheets`
- CSRF présent et validé côté contrôleur pour alerts et favorite_toggle
- `rel="noopener noreferrer"` sur le lien externalUrl
- `filter_var(FILTER_VALIDATE_URL)` bloque `javascript:` URLs côté service
- `|nl2br` dans show.html.twig (échappe d'abord, puis convertit \n en <br>) — XSS safe
- Aucun `|raw` sur des données utilisateur dans les 6 templates
- is_granted() utilisé correctement dans base_app.html.twig (pas getRoles())
- Blocs title/page_title/content présents dans tous les templates
- N+1 évité dans findPublished() et findFavoritesByUser() pour resourceType/organization

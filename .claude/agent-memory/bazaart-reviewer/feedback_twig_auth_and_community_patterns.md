---
name: feedback_twig_auth_and_community_patterns
description: Patterns et anti-patterns identifiés dans les templates Twig auth + artist_profile + post/feed (mai 2026)
metadata:
  type: feedback
---

Relecture des 6 templates thème Street — 23 mai 2026.

**1. CSRF register : token présent dans le template, non validé dans le contrôleur (CRITIQUE)**
`register.html.twig` émet `{{ csrf_token('registration') }}` dans `_csrf_token`. Mais `AuthController::register()` passe directement par `RegisterDTO::fromArray($request->request->all())` sans jamais appeler `$this->isCsrfTokenValid('registration', ...)`. Le token est ignoré.
**Why:** Anti-pattern documenté dans le template lui-même (commentaire ligne 649-654). Formulaire public sans protection CSRF effective.
**How to apply:** Signaler en Critique. Correction : ajouter `isCsrfTokenValid('registration', $request->request->get('_csrf_token'))` dans `AuthController::register()` avant le `RegisterDTO::fromArray()`.

**2. edit.html.twig : formulaire sans action= et sans CSRF (CRITIQUE)**
`<form method="post" enctype="multipart/form-data" novalidate>` n'a pas d'attribut `action=`. Le formulaire poste en POST sur l'URL courante — ça fonctionnerait en pratique, mais c'est fragile. Plus grave : aucun token CSRF dans ce formulaire (le commentaire d'en-tête l'admet : "le contrôleur ne valide pas de token CSRF pour l'instant").
**Why:** Formulaire authentifié POST sans CSRF = vulnérable. L'admission dans le commentaire montre que c'est connu mais non traité.
**How to apply:** Signaler en Critique. Ajouter `<input type="hidden" name="_token" value="{{ csrf_token('artist_profile_edit') }}">` et la validation côté contrôleur.

**3. register.html.twig : variable $error affichée sans échappement suffisant (AVERTISSEMENT)**
`{{ error }}` dans register.html.twig est auto-échappé par Twig (pas de `|raw`) — techniquement sûr. Mais la variable `$error` est une string construite dans le contrôleur depuis des valeurs hardcodées, jamais depuis l'input utilisateur. Pas de risque XSS en l'état, mais à surveiller si la logique change.

**4. post/feed.html.twig : URL AJAX codée en dur (AVERTISSEMENT)**
Le script JS utilise `fetch('/community/post/' + postId + '/like', ...)` (ligne 924) au lieu de `fetch('{{ path('app_post_like', {id: 'PLACEHOLDER'}) }}'.replace('PLACEHOLDER', postId))`. Si le préfixe de route change, le JS silences sans erreur de compilation Twig.
**Why:** Duplication entre la définition de route Symfony et l'URL JS hardcodée.

**5. directory.html.twig : paramètre de route `id` correct (OK)**
`path('app_artist_profile_public', {id: profile.id})` correspond bien au contrôleur `publicShow(int $id)` avec `#[Route('/{id}', name: 'public', requirements: ['id' => '\\d+'])]`. Pas d'alerte.

**Ce qui est correct dans cette série :**
- `_username`, `_password`, `_csrf_token` + `authenticate` dans login.html.twig : conforme Symfony Security
- `{{ parent() }}` présent dans tous les blocs `stylesheets` des 6 templates
- `{% extends 'base.html.twig' %}` sur login et register, `base_app.html.twig` sur les 4 autres
- `|nl2br` sur `post.content`, `comment.content`, `profile.bio` — jamais `|raw`
- Attributs AJAX (`data-post-id`, `data-token`, `data-liked`) conservés sur les boutons like
- Un seul bloc `{% block javascripts %}` dans feed.html.twig
- Erreur login via `error.messageKey|trans(error.messageData, 'security')` — correct
- `is_granted('ROLE_ADMIN')` utilisé dans feed.html.twig (pas getRoles())
- CSRF présent et correctement nommé sur les formulaires post_new, post_delete, comment, comment_delete

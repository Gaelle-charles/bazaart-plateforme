---
name: feedback_twig_admin_article_org_patterns
description: Templates Admin/Article/OrgProfile — patterns et anti-patterns identifiés (mai 2026)
metadata:
  type: feedback
---

Patterns relevés lors de la relecture des 12 templates admin + article + org_profile.

**Pourquoi :** Revue QA ciblée du 24 mai 2026.

**How to apply :** Vérifier ces points sur tous les futurs templates de ces groupes.

---

## Anti-patterns récurrents

### Filtre enum partiel dans le contrôleur — CORRIGÉ (24 mai 2026)
`resourcesAll()` dans AdminController ne traitait que `pending_validation` et `published`.
Le cas `rejected` tombait dans le else (→ toutes les ressources). Correction : branche `elseif`
ajoutée pour `ResourceStatus::Rejected`, avec limite 100 sur les `findBy()` sans filtre réducteur.
Pattern : toujours vérifier que chaque valeur d'onglet Twig a un branchement `elseif` côté contrôleur.

### Formulaire HTML natif sans `action=`
`article/form.html.twig` et `admin/users.html.twig` : `<form method="post">` sans `action`.
Fonctionne par hasard car la route GET et POST sont la même URL. Fragile en cas de proxy
ou de route partagée new/edit. Toujours spécifier `action="{{ path(...) }}"`.

### CSRF absent sur formulaire article — CORRIGÉ (24 mai 2026)
`ArticleController::new()` et `edit()` manquaient de vérification CSRF côté contrôleur.
Le token `article_form` était déjà généré dans `article/form.html.twig` mais jamais validé.
Correction : `isCsrfTokenValid('article_form', ...)` ajouté en première instruction du bloc POST
dans les deux méthodes. Redirect vers `app_article_new` ou `app_article_edit` (avec `id`) en cas d'échec.

### `is defined` sur propriété d'entité Doctrine
`organization_profile/show.html.twig` : `{% if profile.isStructurePartner is defined %}`.
`is defined` en Twig teste les variables Twig, pas les méthodes d'objet. Ce test est toujours
`true` et ne protège pas contre un champ manquant. Supprimer et utiliser directement la propriété.

### `updatedAt` nullable sur brouillon non modifié
`article/my.html.twig` : `{{ article.updatedAt|date('Y-m-d') }}` peut exploser si updatedAt est null.
Pattern : toujours coalescent `(article.updatedAt ?? article.createdAt)|date(...)`.

### `app.user` non vérifié avant comparaison avec entité
`article/show.html.twig` : `app.user == article.author` sans guard `app.user`.
Twig ne plante pas (null == object = false) mais lisibilité et robustesse gagnent à :
`{% if app.user and (app.user == article.author ...) %}`.

## Bon patterns confirmés
- `|nl2br` sans `|raw` = safe en Twig (autoescaping actif avant nl2br).
- `|e('js')` dans les `onsubmit="return confirm(...)"` → XSS JS correctement neutralisé.
- CSRF par ressource avec granularité `resource_action_{id}` = bonne pratique.
- `flash--info` désormais rendu dans `base_admin.html.twig` (corrigé vs mémoire précédente).

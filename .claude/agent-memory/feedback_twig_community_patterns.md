---
name: feedback_twig_community_patterns
description: Templates Twig Forum/Messagerie/Notifications/Dashboard (thème Street) — patterns et anti-patterns identifiés lors de la relecture du 23 mai 2026
metadata:
  type: feedback
---

## Anti-patterns récurrents repérés dans la relecture du 23 mai 2026

### 1. `DateTime.timestamp` inexistant en Twig — CRITIQUE
`reply.updatedAt.timestamp` lève une erreur Twig : DateTime n'expose pas `.timestamp` comme propriété accessible.
Correction : `reply.updatedAt > reply.createdAt` ou `reply.updatedAt|date('U') != reply.createdAt|date('U')`.
Fichier : `forum/thread.html.twig`.

**Why:** Erreur d'exécution visible dès qu'un reply a été modifié.
**How to apply:** Toute comparaison de DateTime en Twig doit utiliser `>`, `<`, les filtres `date()`, ou `|date('U')`.

### 2. Pipeline `|escape|nl2br|raw` — CRITIQUE
Dans `messaging/show.html.twig`, le pipeline `message.content|escape|nl2br|raw` est erroné avec l'auto-escaping Twig activé : double-escaping des entités HTML + `|raw` inutilement risqué.
Correction idiomatique : `{{ message.content|nl2br }}` (nl2br échappe avant d'insérer les `<br>`).
Fichier : `messaging/show.html.twig`.

**Why:** nl2br de Twig échappe le HTML automatiquement — pas besoin de |escape explicite. L'ajouter avec auto-escaping produit du double-escaping visible (`&amp;lt;` etc.).
**How to apply:** Ne jamais combiner `|escape` + auto-escaping. Utiliser `|nl2br` seul pour le contenu utilisateur multiligne.

### 3. `is defined` sur propriété d'entité au lieu de `is not null`
`resource.resourceType is defined` ne teste pas la nullabilité d'une relation Doctrine.
`is defined` teste l'existence de la variable dans le contexte Twig, pas la nullabilité d'une propriété d'objet.
Correction : `resource.resourceType is not null`.
Fichier : `dashboard/index.html.twig`.

**Why:** Bug silencieux — si la relation est null, Twig lève "Impossible to access attribute" sur `.name`.
**How to apply:** Pour toute propriété d'entité optionnelle, toujours tester `is not null`.

### 4. Double-escaping dans les `{% set %}` + auto-escaping à l'affichage
`{% set notifText = 'Texte ' ~ someVar|e %}` puis `{{ notifText }}` : le `|e` est appliqué dans la concaténation, puis Twig re-échappe à l'affichage.
Correction : supprimer le `|e` préventif dans le `{% set %}`, laisser l'auto-escaping gérer à l'affichage.
Fichier : `notifications/index.html.twig`.

**Why:** Double-escaping produit des entités HTML visibles (`&amp;`, `&lt;`) dans le texte rendu.
**How to apply:** Ne jamais pré-escaper dans un `{% set %}` qui sera affiché via `{{ }}` avec auto-escaping.

### 5. Format de date ICU dans le filtre `date` Twig
`"now"|date("d MMMM Y")` utilise des codes ICU incompatibles avec `date()` standard Twig (qui attend du format PHP).
Correction : `"now"|date("d F Y")` pour le mois long en PHP.
Fichier : `dashboard/index.html.twig`.

**Why:** `MMMM` et `Y` sont rendus littéralement par le filtre date standard.
**How to apply:** Vérifier si l'extension Intl Twig est configurée avant d'utiliser des codes ICU.

### 6. `<script>` inline dans `{% block content %}` au lieu de `{% block javascripts %}`
Scripts JS insérés dans le bloc content au lieu de javascripts.
Problème : incompatibilité avec Turbo (scripts non ré-exécutés à la navigation).
Correction : placer dans `{% block javascripts %}{{ parent() }}...{% endblock %}`.
Fichier : `forum/new_thread.html.twig`.

**How to apply:** Tout `<script>` doit aller dans `{% block javascripts %}` avec `{{ parent() }}`.

### 7. Ordre des branches `elseif` — cas edge non couvert
Dans `thread.html.twig`, l'ordre `{% if canReply and app.user %} / {% elseif thread.locked %} / {% elseif not app.user %}` masque l'invitation à se connecter pour un thread non-verrouillé + utilisateur non connecté.
Correction : mettre `not app.user` en premier elseif.

---
name: feedback-pagination-antipatterns
description: Anti-patterns pagination Doctrine à vérifier systématiquement lors de toute feature de listage paginé
metadata:
  type: feedback
---

Patterns identifiés lors de la relecture de la fonctionnalité pagination `/resources` (2026-06-13).

1. **setFirstResult + fetch-join collection = doublons potentiels** — Quand on combine `setFirstResult()`/`setMaxResults()` avec un `leftJoin` sur une collection ManyToMany (`addSelect`), Doctrine fait la pagination en SQL sur le résultat du JOIN (qui peut contenir plusieurs lignes par entité). Si une Resource a 3 disciplines, elle apparaît 3 fois dans le résultat SQL brut. `setMaxResults(12)` peut alors ne retourner que 4 entités (12 lignes / 3 disciplines en moyenne). Solution canonique : utiliser `Doctrine\ORM\Tools\Pagination\Paginator` avec `fetchJoinCollection: true`, qui génère une sous-requête avec les IDs corrects avant d'appliquer les JOINs de chargement. À signaler systématiquement si `setFirstResult` + `leftJoin collection` + `addSelect` sont combinés sans Paginator.

2. **countPublished() avec orderBy inutile** — Un `orderBy` dans le QueryBuilder de base (partagé pour COUNT et SELECT) est ignoré par le COUNT mais représente un overhead SQL minime. Pas bloquant, mais à noter comme suggestion de nettoyage.

3. **Bornage silencieux vs 404** — Si `?page=99` avec seulement 3 pages, borner silencieusement à la dernière page (pattern utilisé ici) évite de casser les bookmarks. Alternative : retourner une 404. Le choix dépend de la politique UX du projet.

4. **Retour à la page 1 au changement de filtre** — Le formulaire GET ne réinitialise pas `?page=` lors d'un changement de filtre. L'utilisateur sur page 3 avec filtre "type=2" qui change le filtre reste sur page 3 du nouveau résultat. Si page 3 n'existe pas dans le nouveau filtre, le bornage corrige, mais c'est non-intuitif. Suggestion : ajouter un champ caché `<input type="hidden" name="page" value="1">` dans le formulaire de filtre, ou gérer en JS.

5. **Variable `total` non définie dans le template état vide** — Si `resources is empty`, le template entre dans un bloc `{% else %}` mais `total` est quand même disponible (elle vaut 0 et est passée par le controller). Pas de risque d'erreur Twig, mais vérifier que `total` est toujours passé même en cas de résultat vide.

**Why:** Patterns identifiés lors de la relecture de pagination sur ResourceRepository + ResourceController.
**How to apply:** Checker point 1 (setFirstResult + fetch-join) systématiquement sur toute nouvelle pagination Doctrine.

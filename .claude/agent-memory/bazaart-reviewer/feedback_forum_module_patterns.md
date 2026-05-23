---
name: feedback_forum_module_patterns
description: Patterns et anti-patterns identifiés lors de la relecture du module Forum (2026-05-23)
metadata:
  type: feedback
---

Relecture du module Forum (ForumCategory, ForumThread, ForumReply, ForumService, ForumController, ForumVoter, templates) effectuée le 2026-05-23.

**Points propres à retenir (ne pas signaler en fausse alerte) :**
- Le `ForumVoter` utilise correctement `$this->security->isGranted()` — aucun `getRoles()` brut.
- Les `instanceof ForumThread / instanceof ForumReply` dans `supports()` et `voteOnAttribute()` sont la bonne façon de remplacer les anciens `method_exists()`.
- `canDelete()` délègue à `canEdit()` intentionnellement (mutualisation documentée).
- Le retour `ForumThread|string` de `ForumService::createThread()` est le pattern déjà identifié comme fragile (voir [[feedback_apply_union_type_antipattern]]) — signaler à chaque occurrence.
- La vérification d'idempotence dans `SeedForumCategoriesCommand` (count > 0 avant insertion) est la bonne approche.

**Anti-patterns trouvés dans ce module :**
1. XSS dans `thread.html.twig` ligne ~341 : `{{ thread.content }}` sans filtre `|nl2br|escape` ni `|e`. `white-space: pre-wrap` en CSS ne suffit pas à empêcher l'injection HTML. Même problème sur `reply.content` ligne ~424.
2. N+1 subtil dans `ForumController::index()` : boucle `foreach ($categories as $category)` avec `findLatestByCategory()` — N requêtes pour N catégories. La solution propre serait une requête unique avec sous-requête ou GROUP BY.
3. CSRF vérifié APRÈS `denyAccessUnlessGranted()` dans les actions `lock`, `pin`, `delete_thread`, `delete_reply` — inversion de l'ordre recommandé (CSRF d'abord, autorisation ensuite).
4. Collision de slug non couverte inter-catégories : `findBySlugAndCategory()` ne détecte pas les collisions si le même slug existe dans une AUTRE catégorie. Or le slug est UNIQUE globalement en BDD.
5. `base_app.html.twig` ligne ~289 : `'ROLE_ADMIN' in app.user.roles` utilise les rôles bruts — anti-pattern cohérent avec le bug déjà signalé dans base_admin (voir [[feedback_getRoles_hierarchy]]).
6. `ForumCategory::getThreadsCount()` charge toute la collection si elle n'est pas encore initialisée — potentiel N+1 sur la page index du forum.

**Why:** Ces points sont à surveiller dans tout nouveau module de la plateforme.
**How to apply:** Vérifier systématiquement l'ordre CSRF/authorization, l'échappement du contenu utilisateur, et les boucles sur des repositories.

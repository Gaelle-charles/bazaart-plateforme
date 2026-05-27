---
name: feedback_forum_module_patterns
description: Patterns et anti-patterns identifiés lors de la relecture du module Forum (mise à jour 2026-05-25 — relecture complète V2)
metadata:
  type: feedback
---

Relecture complète du module Forum effectuée le 2026-05-25 (ForumCategory, ForumThread, ForumReply, ForumService, ForumController, ForumVoter, SeedForumCategoriesCommand, migration, 4 templates).

**Points propres à retenir (ne pas signaler en fausse alerte) :**
- Le `ForumVoter` utilise correctement `$this->security->isGranted()` — aucun `getRoles()` brut.
- Les `instanceof ForumThread / instanceof ForumReply` dans `supports()` et `voteOnAttribute()` sont la bonne façon de vérifier le type du sujet.
- `canDelete()` délègue à `canEdit()` intentionnellement (mutualisation documentée).
- Le retour `ForumThread|string` de `ForumService::createThread()` est le pattern fragile déjà identifié (voir [[feedback_apply_union_type_antipattern]]).
- La vérification d'idempotence dans `SeedForumCategoriesCommand` (count > 0 avant insertion) est la bonne approche.
- `{{ thread.content|nl2br }}` est SAFE : Twig 3 avec autoescape activé (par défaut sur .html.twig) échappe d'abord le HTML PUIS nl2br convertit les \n en <br>. NE PAS signaler comme XSS — c'était une fausse alerte mémorisée en 2026-05-23.
- CSRF avant denyAccessUnlessGranted dans lock/pin/delete : c'est l'ordre CORRECT (intentionnel, commenté). NE PAS signaler comme inversion.
- `findBySlugAndCategory()` filtre bien par catégorie même si le slug est unique globalement — redondance intentionnelle.
- Les préfixes CSS .fi- / .fc- / .ft- / .fn- sont correctement appliqués.
- Aucun token CSS interdit (--accent-pink, --accent-blue, --accent-yellow) trouvé.
- Aucun border-radius positif (toutes les valeurs sont 0 ou var(--btn-radius) = 0).
- Les templates étendent bien `base_app.html.twig` (correct pour le forum public).

**Anti-patterns et bugs trouvés dans ce module (relecture 2026-05-25) :**

1. COLLISION DE ROUTE "nouveau" : un thread dont le slug serait "nouveau" (ex : titre "Nouveau") est définitivement INACCESSIBLE. La route `/forum/{categorySlug}/nouveau` (new_thread) prend priorité sur `/forum/{categorySlug}/{threadSlug}` pour le segment statique "nouveau". Le slugify() peut produire "nouveau" depuis le titre "Nouveau" (5+ caractères → passe la validation). À corriger en liste noire dans slugify() ou en réservant le mot.

2. NULLS FIRST BUG PostgreSQL : `addOrderBy('t.lastReplyAt', 'DESC')` en DQL Doctrine ne génère PAS `NULLS LAST`. PostgreSQL par défaut sur ORDER BY DESC → NULLS FIRST (les threads sans réponse remontent en tête). Le commentaire `// NULLS LAST en PostgreSQL` est FAUX. Correct : utiliser une requête SQL native ou l'expression NULLS LAST.

3. ROUTE SIGNALEMENT (report) ABSENTE : la route `POST /forum/thread/{id}/report` est dans le CDC §5.3 mais n'est pas implémentée. Les templates ne proposent aucun bouton "Signaler". Module de modération communautaire incomplet.

4. CDC EXIGE 5 CATÉGORIES, SEED EN CRÉE 4 : le CDC §8 ligne 213 exige "Au moins 5 catégories de forum avec un thread d'amorce chacun". La commande seed crée 4 catégories (Actualités, Ressources, Projets, Vie artistique). Pas de thread d'amorce non plus.

5. RACE CONDITION incrementViews() : l'implémentation via `$thread->incrementViews(); $em->flush()` génère un UPDATE SET views_count = N (valeur lue en PHP) au lieu d'un UPDATE SET views_count = views_count + 1. En cas de requêtes concurrentes, des incréments peuvent se perdre. Non critique pour la V1 à faible charge, mais commenté "pas de déduplication" — la doc est juste mais le bug concurrent n'est pas mentionné.

6. (CORRIGÉ 2026-05-26) : 5 tests E2E créés et passants — voir relecture tests/E2E/. La route "nouveau" avait un bug de routage corrigé avec requirements: ['threadSlug' => '(?!nouveau$).+']. La protection dans ForumService (RESERVED_SLUGS) subsiste en complément.

7. FORMDATA.CONTENT non échappé dans textarea : `{{ formData.content ?? '' }}` dans new_thread.html.twig est rendu dans un `<textarea>`. L'autoescape Twig échappe les entités HTML, ce qui est correct pour un textarea. Pas de XSS mais à vérifier si Twig encode bien > en &gt; dans ce contexte (oui, autoescape par défaut).

**Why:** Ces points sont à surveiller dans tout nouveau module de la plateforme.
**How to apply:** Vérifier systématiquement : mots réservés dans les slugs, NULLS LAST PostgreSQL, existence de toutes les routes CDC, nombre d'éléments de fixtures vs CDC, et race conditions sur les compteurs dénormalisés.

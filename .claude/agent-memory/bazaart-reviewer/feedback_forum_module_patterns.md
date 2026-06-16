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

8. COMPOSITEUR INLINE (quickPost — juin 2026) : ajout de la route POST /forum/nouveau (app_forum_quick_post). Points VALIDES à ne pas signaler :
   - PHPStan niveau 6 : 0 erreur sur ForumController.php
   - lint:twig : syntaxe valide
   - Routage : POST /forum/nouveau résolu vers app_forum_quick_post sans collision (méthode distincte du GET capté par app_forum_category)
   - GET /forum/nouveau → app_forum_category (slug "nouveau") → catégorie introuvable → 404 propre
   - `data-show-when-open hidden` est HTML5 valide : `hidden` est un attribut booléen, `data-*` est un data attribute — les deux coexistent sans conflit
   - `el.hidden = false` retire bien l'attribut `hidden` via IDL — conforme à la spec DOM
   - `window.fiComposerOpen / window.fiComposerClose` : risque de collision faible (namespace spécifique "fiComposer"), documenté et acceptable en V1
   - is_numeric() + cast (int) sur $rawCategoryId : correct pour PHPStan niveau 6, pas de cast aveugle
   - isActive() vérifié ligne 127 avant toute utilisation de la catégorie
   - Le bouton "Nouveau post" du header pointe toujours vers app_forum_new_thread (ligne 708)
   - JS de tabs intact (bloc script en bas de template)

   Points MINEURS à signaler :
   - $rawCategoryId = $request->request->get('categoryId') retourne `string|null`. La vérification `is_numeric()` accepte les strings représentant des floats comme "1.5" → (int)"1.5" = 1. Sûr en pratique (PostgreSQL trouve l'ID ou retourne null) mais mérite un cast plus strict via `$request->request->getInt('categoryId', 0)` qui retourne 0 si absent ou non-numérique.
   - Le formulaire étendu (`fi-composer-form`) ne porte pas d'attribut `novalidate` : la validation HTML5 (`required`) se déclenchera même si le formulaire est soumis depuis un état non-affiche (JS désactivé). Pas de bug, c'est une feature de sécurité (dégradation gracieuse), mais à documenter.
   - Duplication CSS : `.fi-composer` est défini deux fois dans le bloc `<style>` (une fois avec `display:flex`, une fois avec `display:block` qui l'override). La première règle est morte code — lisibilité dégradée.
   - `fi-composer__submit` (CSS ligne 502-504) n'a aucune propriété — commentaire "hérite des styles .btn" mais le sélecteur est présent et vide → dead code CSS mineur.

9. FEATURE images + réactions (juin 2026 — relecture 2026-06-15) — points valides à ne pas re-signaler :
   - PHPStan niveau 6 : 0 erreur (validé par Gaëlle avant merge).
   - `{{ thread.content|nl2br }}` et `{{ reply.content|nl2br }}` : pas de XSS, autoescape actif.
   - `asset(thread.imagePath)` sans `|raw` et sans contenu utilisateur dans src= : sûr.
   - CSRF vérifié EN PREMIER dans react() : conforme au pattern lock/pin/delete.
   - `countForThreadAndReplies()` et `findUserReactionsForThreadAndReplies()` : batch anti-N+1 correct.
   - La contrainte UniqueConstraint Doctrine (portant sur les 3 colonnes including NULL) diverge de l'index UNIQUE SQL partiel (WHERE thread_id IS NOT NULL / WHERE reply_id IS NOT NULL). Le mapping indique lui-même que c'est attendu et sans danger. `doctrine:schema:validate` peut signaler cela — ne pas bloquer sur ce point en relecture.
   - Tous les appelants de createThread/addReply (quickPost, newThread, reply dans ForumController) passent correctement le paramètre optionnel ?UploadedFile — signature rétrocompatible.
   - Tests E2E : le test ForumThreadTest ne passe pas de fichier image (paramètre optionnel absent) → pas de régression de signature.

   Points NEUFS identifiés dans cette relecture :
   - [MAJEUR] `countForThreadAndReplies()` : la clause `->orWhere('r.reply IN (:replyIds)')` utilise `orWhere()` au lieu de `->andWhere(...)`. Le DQL résultant est `WHERE (r.thread = :thread) OR (r.reply IN (:replyIds))` — il peut inclure des réactions sur des réponses d'autres threads si leurs IDs coïncident. Correct pour la logique actuelle (les replyIds fournis viennent du même thread), mais fragile si l'appelant est modifié. Pattern `andWhere` avec sous-condition explicite serait plus sûr.
   - [MAJEUR] `findUserReactionsForThreadAndReplies()` : `->orWhere('r.user = :user AND r.reply IN (:replyIds)')` — le paramètre `:user` est déjà défini par `->andWhere('r.user = :user')`. L'`orWhere` ajoute un branchement OR au lieu d'être un AND. Le DQL est `WHERE (r.user=:user AND r.thread=:thread) OR (r.user=:user AND r.reply IN (:replyIds))` — la logique est correcte ici (l'utilisateur connecté est le même dans les deux branches) mais la lisibilité est trompeuse.
   - [MINEUR] Aucun test E2E pour les réactions (toggle, compteur, CSRF invalide) ni pour l'upload image (format invalide, trop lourd). Couverture du chemin critique manquante.
   - [MINEUR] `ForumImageService::handleUpload()` ne gère pas l'exception `FileException` levée par `$file->move()` — elle remonte au controller qui n'en fait rien (500 si disque plein ou droits insuffisants). À wrapper dans try/catch avec retour de message d'erreur.
   - [NIT] `ForumReactionType::Heart->label()` retourne 'Coeur' sans accent (devrait être 'Cœur'). Cosmétique mais visible dans les tooltips.

**Why:** Ces points sont à surveiller dans tout nouveau module de la plateforme.
**How to apply:** Vérifier systématiquement : mots réservés dans les slugs, NULLS LAST PostgreSQL, existence de toutes les routes CDC, nombre d'éléments de fixtures vs CDC, race conditions sur les compteurs dénormalisés, et la logique `orWhere` vs `andWhere` dans les DQL multi-critères. Pour les uploads, toujours wrapper `file->move()` dans un try/catch.

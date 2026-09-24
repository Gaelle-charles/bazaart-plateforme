---
name: feedback_espace_projets_adr0037_patterns
description: Module Espace projets (ADR-0037, gestion de projet interne ROLE_PROJECT) — patterns et anti-patterns identifiés lors de la relecture du 24 septembre 2026
metadata:
  type: feedback
---

## Contexte
Module volumineux (~7700 lignes, 12 tables) ajouté hors du périmètre public V1/V2 du
cahier des charges (outil interne à 3 personnes, `/admin/projets`, ROLE_PROJECT non
hérité de ROLE_ADMIN). Documenté par ADR-0037 (`docs/decisions/0037-...md`), statut
« proposé » au moment de la relecture — CLAUDE.md n'était volontairement pas encore
mis à jour (conditionné à la validation de Gaëlle). **Ne pas signaler « hors scope /
pas d'ADR » sans vérifier `docs/decisions/` : ici l'ADR existe et est bien écrit.**
Vérifier systématiquement le statut de l'ADR (proposé vs accepté) plutôt que son
absence.

Qualité générale : la meilleure relue à ce jour sur ce projet. PHPStan niveau 6 = 0
nouvelle erreur, 37/37 tests E2E+unitaires PASS, JOIN FETCH partout (0 N+1 repéré),
CSRF/IDOR/XSS/OAuth tous corrects avec défense en profondeur et commentaires citant
explicitement les erreurs des relectures précédentes (ordre CSRF avant Voter, etc.).

## Anti-pattern réel repéré

### 1. `position` (Kanban) partagé globalement par statut, pas par projet — CORRIGÉ (commit e3e73a7)
**Statut : résolu.** `reorder()` recharge désormais la colonne COMPLÈTE du statut, replace les cartes
affichées dans leurs emplacements et renumérote 0..n-1 (test `testReorderingInProjectBoardKeepsOtherProjectsInPlace`).
`position` global par statut est donc VOULU ; `nextPosition()` (MAX+1 du statut) reste correct, y compris pour
les imports en masse. Ne plus signaler. Constat d'origine conservé ci-dessous pour mémoire.

`ProjectTaskRepository::nextPosition()` et `ProjectTaskService::reorder()` traitent
`position` comme une séquence unique par **statut** (`WHERE t.status = :status`), sans
tenir compte du projet. Or le Kanban existe en deux contextes qui n'affichent pas la
même « colonne » : la page globale « Tâches » (toutes les tâches, tous projets
confondus) ET la mini-Kanban de la fiche projet (`project_show.html.twig`, tâches
filtrées sur CE projet). Glisser-déposer dans la vue par-projet envoie un
`orderedIds` qui ne contient QUE les tâches de ce projet → `reorder()` réindexe
0..N-1 uniquement ce sous-ensemble, ce qui peut faire entrer en collision les
`position` avec des tâches d'autres projets partageant le même statut, et mélanger
l'ordre affiché dans la vue globale ensuite.
Fichiers : `src/Service/Project/ProjectTaskService.php` (`reorder()`, `moveAssignee()`
appelle indirectement), `src/Repository/ProjectTaskRepository.php::nextPosition()`.
**Correction proposée** : soit scinder `position` par (status, project_id) avec une
valeur sentinelle pour "sans projet" et gérer le tri de la vue globale autrement
(ex. trier par updatedAt en cas d'égalité), soit faire en sorte que `reorder()`
recharge TOUTES les tâches de ce statut (pas seulement celles reçues) et ne réindexe
que les positions manquantes en les insérant à la fin.
**Sévérité** : Avertissement (pas de crash, pas de faille de sécurité, juste un ordre
de cartes qui peut devenir incohérent entre les deux vues ; équipe de 3 personnes qui
utilisera probablement surtout une seule des deux vues au quotidien).

## Pattern confirmé sain (ne pas re-signaler comme suspect)

### 2. Chaînage `|pm_autolink|nl2br` avec `pre_escape:'html'` sur les deux filtres
Vérifié empiriquement (script Twig autonome) : Twig 3.23 ne ré-échappe PAS la sortie
d'un filtre `is_safe:['html']` quand elle est passée à un filtre suivant déclaré
`pre_escape:'html'` — donc `contenu|pm_autolink|nl2br` ne produit AUCUN double-escaping
et les `<a href>` générés par `pm_autolink` restent intacts après `nl2br`. C'est le
même mécanisme que le filtre `nl2br` natif de Twig (`CoreExtension::nl2br`, déclaré
avec les mêmes options). Ne pas confondre avec l'anti-pattern connu
`escape|nl2br|raw` (cf. feedback_twig_community_patterns.md #2) qui lui reste erroné.

### 3. Défense en profondeur bien appliquée
- `ProjectVoter` : vérifie `Security::isGranted('ROLE_PROJECT')` (jamais `getRoles()`
  brut) + IDOR notes/commentaires (autrice uniquement) + PROJECT_DELETE
  (créatrice/responsable/admin).
- Ordre CSRF **avant** `denyAccessUnlessGranted()` partout, avec commentaire citant
  explicitement la mémoire relecteur (`AdminProjectController::deleteProject`).
- `access_control` : `^/admin/projets` (ROLE_PROJECT) placé AVANT `^/admin`
  (ROLE_ADMIN) — sinon la règle générale aurait exigé ROLE_ADMIN. Rôle non ajouté à
  `role_hierarchy` (donc pas hérité par ROLE_ADMIN) : testé explicitement
  (`testAdminWithoutProjectRoleIsForbidden`).
- OAuth Drive : `state` aléatoire + `hash_equals()`, compte connecté revérifié auprès
  de Google (`/about`) et révoqué si ce n'est pas le bon compte, jeton chiffré
  libsodium (XSalsa20-Poly1305 authentifié), stocké hors de `app_settings`.
- `GoogleDriveService::escapeQueryValue()` échappe `'` et `\` dans le paramètre `q`
  de l'API Drive ; IDs Drive validés par regex avant tout appel.
- CSV : `csvSafe()` préfixe d'une apostrophe les cellules commençant par `= + - @`.
- Couleurs (`Project.color`, `ProjectLabel.color`) toujours validées contre une
  palette fermée (`ProjectService::COLORS`) côté DTO → pas d'injection CSS possible
  même si affichées sans échappement dans un `style="border-left: ... {{ color }}"`.
- Un test dédié (`testTemplatesContainNoEmDash`) fait respecter la convention
  « pas de tiret cadratin visible » au niveau des templates — bon réflexe à retenir
  comme exemple à proposer sur d'autres modules.

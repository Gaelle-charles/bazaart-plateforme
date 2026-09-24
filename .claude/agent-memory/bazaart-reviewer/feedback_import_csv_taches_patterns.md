---
name: feedback_import_csv_taches_patterns
description: Import CSV de tâches (ProjectTaskImporter, ADR-0037) + prénom modifiable + liens retour ROLE_PROJECT — relecture du 24 septembre 2026 (commit 44af20a)
metadata:
  type: feedback
---

## Contexte
Trois retours d'équipe après mise en service de l'Espace projets (ADR-0037) :
prénom/nom modifiables dans « Équipe & réglages » + étape checklist, liens
« Espace projets » pour ROLE_PROJECT sur le site public (base.html.twig, sidebar
artiste, dashboard structure), et import CSV de tâches (Google Sheets/Excel →
ProjectTaskImporter). PHPStan niveau 6 : 0 nouvelle erreur (3 préexistantes
DiscoverSourcesCommand/NotificationService, non liées). Tests : 7/7 PASS
(ProjectImportAndProfileTest), 16/16 PASS (ProjectSpaceTest, non-régression).
Qualité globale solide (CSRF avant action, hash SHA-256 + hash_equals() pour lier
aperçu/confirmation, isSafeLinkUrl() réutilisé proprement, auto-échappement Twig
partout, access_control /admin/projets déjà en place donc pas de nouvelle règle
nécessaire pour les sous-routes /importer*).

## Anti-pattern réel repéré

### 1. Incohérence : erreur bloquante en saisie manuelle vs perte silencieuse à l'import
`ProjectTaskData::validate()` (création manuelle d'une tâche) REJETTE explicitement
le cas `startDate > dueDate` avec un message d'erreur (« L'échéance doit être
postérieure à la date de début. »). `ProjectTaskImporter::analyze()` (import CSV),
face au même cas (colonne « Date prévue » postérieure à la colonne « Échéance »),
abandonne silencieusement la date prévue (`$row->startDate = null`) SANS ajouter de
ligne dans `$report->warnings` — alors que tous les autres cas limites du même
service (statut/priorité inconnus, titre tronqué, personne non trouvée, lien non
sûr, sous-tâches en trop) génèrent systématiquement un avertissement affiché à
l'étape « Vérifier ». Fichier : `src/Service/Project/ProjectTaskImporter.php`
(ligne ~230, bloc `// ── Dates : « Date prévue » sert d'échéance...`).
**Réflexe pour la prochaine relecture d'un import/parsing en masse** : pour
CHAQUE règle de validation qui bloque en saisie manuelle, vérifier que l'import en
masse produit au moins un avertissement visible dans l'aperçu (jamais un silence).
**Correctif proposé** : `if ($due !== null && $planned !== null && $planned > $due) { $report->warnings[] = sprintf('Ligne %d : date prévue (%s) postérieure à l\'échéance, ignorée.', $line, $planned->format('d/m/Y')); }`.
**Sévérité** : Avertissement (perte de donnée silencieuse, pas de crash ni faille).

### 2. Positions Kanban à l'import : PAS un bug (ne pas signaler)
`ProjectTaskImporter::persist()` utilise `nextPosition()` (MAX+1 par statut) puis incrémente : positions
uniques dans la colonne. Le constat « position partagé par statut » de `feedback_espace_projets_adr0037_patterns.md`
est résolu depuis le commit e3e73a7 (reorder sur la colonne complète) ; la position globale par statut est voulue.

### Suite donnée
Point #1 corrigé le 24/09/2026 (commit 35b14aa) : avertissement « date prévue après l'échéance, seule l'échéance
est gardée » + test `testPlannedDateAfterDueDateIsReportedAndDueDateKept`. `ProjectImportRow::projectLabel()`
(code mort) supprimée.

## Pattern confirmé sain (ne pas re-signaler comme suspect)

### 3. Parsing CSV robuste et bien testé
BOM UTF-8 retiré, fallback Windows-1252 si non-UTF-8, détection auto du séparateur
(`,`/`;`/tab par comptage sur la 1re ligne), rejet propre des `.xlsx` (signature
`PK\x03\x04`), bornes strictes (1 Mo, 500 lignes utiles, 5000 lignes brutes,
20 000 caractères de description, 50 sous-tâches, 10 étiquettes) — toutes
vérifiées par test. Correspondance de personnes par email exact/prénom/nom/
fragment ≥4 lettres avec désambiguïsation (`exact` prioritaire sur `partial`,
null si ambigu) : solide, testé avec un cas volontairement ambigu (« Inconnue »).
Détection de doublons (même titre + même projet, y compris entre nouvelles lignes
du même fichier via `$seen`) : clé unique cohérente entre tâches déjà en base
(`findAllTitles()`) et nouveaux projets (préfixe `new:`), pas de collision avec
projectId réel. Confirmation liée par SHA-256 + `hash_equals()` : bon réflexe
contre la resoumission d'un fichier différent de celui vérifié à l'aperçu (testé
explicitement : `testImportConfirmationExpiresWithAnotherFileHash`).
Aucune migration nécessaire pour `ProjectActivity::TASKS_IMPORTED` : nouvelle
valeur d'une colonne `string` existante (`$action`), pas un nouveau champ.

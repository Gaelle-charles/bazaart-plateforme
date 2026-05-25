---
name: feedback_formation_module_patterns
description: Module Formation (mai 2026) — patterns et anti-patterns identifiés lors de la relecture
metadata:
  type: feedback
---

# Module Formation — patterns et anti-patterns (relecture 25 mai 2026)

## Points bien faits (ne pas signaler en fausse alerte)
- `#[IsGranted('ROLE_ADMIN')]` sur la **classe** AdminCourseController : couvre toutes les routes sans oubli.
- CSRF présent sur TOUS les formulaires POST (enroll, update, module_add, lesson_add, publish).
- Route progression AJAX retourne `JsonResponse` avec code HTTP explicite (403, 404, 500).
- Anti-IDOR sur `lessonAction()` : la leçon est cherchée dans les modules du cours, pas directement en base.
- Gestion doublon enrollment via `LogicException` en PHP + contrainte UNIQUE en SQL.
- Division par zéro évitée dans `recalculateProgress()` (guard `$totalLessons === 0`).
- Préfixes CSS conformes : `.cf-` public, `.adc-` admin.
- `border-radius: 0` systématique, tokens Street corrects.
- Migration `down()` cohérente avec `up()`, ordre FK respecté.

## Bugs / avertissements à retenir
- `AdminCourseController::publish()` utilise `addFlash('info', ...)` pour la formation déjà publiée → `base_admin.html.twig` rend bien 'info' (corrigé depuis la PR Structure) — NE PAS signaler.
- Route CDC §5.7 utilise `{lessonSlug}` dans le CDC mais l'implémentation utilise `{lessonId<\d+>}` — divergence documentaire acceptable, l'ID est plus robuste. Signaler comme question ouverte.
- `lesson.html.twig` : bouton "Marquer comme terminée" met à jour le bouton visuellement après 500ms hardcodé, sans attendre la réponse HTTP réelle (race condition UX si réseau lent).
- N+1 potentiel dans `admin/course/index.html.twig` : itération Twig sur `course.modules` puis `module.lessons` sans FETCH JOIN dans `CourseRepository::findBy()`. À surveiller si le catalogue admin grossit.
- `videoUrl` n'est pas validée côté serveur (domaine YouTube/Vimeo uniquement) : un admin pourrait injecter n'importe quelle URL en `src` d'iframe.
- `recalculateProgress()` : double flush (un dans `updateLessonProgress` pour la progression, un second dans `recalculateProgress` pour le percent) — mineur mais inefficace.
- Bunny Stream : `videoBunnyId` affiché brut dans le placeholder Twig (`lesson.html.twig` l.448) — pas de risque XSS (escaping `|e`), mais fuite d'info interne si formation publique.
- `trailerVideoUrl` et `videoUrl` : pas de validation des domaines autorisés côté PHP (seulement `type="url"` HTML côté form). Un admin mal intentionné peut pointer vers n'importe quel domaine.

**Why:** La route AJAX progress sans token CSRF est acceptable car `SameSite=Lax` + `IS_AUTHENTICATED_FULLY` protègent en same-origin.
**How to apply:** Ne pas signaler l'absence de CSRF sur la route AJAX `/progress` comme blocant — la justification est documentée dans le template.

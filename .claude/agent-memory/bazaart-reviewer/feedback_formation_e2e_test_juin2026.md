---
name: feedback_formation_e2e_test_juin2026
description: Test E2E module Formation (ADR-0013 + ADR-0014 phases 1-3) — 18 juin 2026 — résultats et points résiduels
metadata:
  type: feedback
---

# Test E2E Module Formation — 18 juin 2026

## PHPStan
- Niveau 6, 199 fichiers : 0 erreur. Confirme la stabilité du codebase.

## Bugs de relectures précédentes confirmés CORRIGÉS (ne plus signaler)
- Route `app_courses_show` (avec "s") dans CoursePaymentController : corrigé, toutes les occurrences utilisent `app_course_show`.
- Flash 'warning' et 'danger' invisibles dans base_admin.html.twig : corrigés (lignes 683-687).
- Backing string enum littéral `'event'` dans `findUpcomingEventsByUser()` : corrigé, utilise `CourseType::EVENEMENT->value`.
- Incohérence `|first`/`|last` pour eventLocation dans show.html.twig : corrigé, `|last` partout.
- `from` email sans display name dans EventRegistrationService : corrigé, `new Address('noreply@bazaart.fr', 'Bazaart')`.
- Erreur email silencieuse sans logger dans `registerForEvent()` : corrigé, `$this->logger->warning(...)` ajouté.
- Bug BLOQUANT cancelEventByAdmin : corrigé, `if (!$paymentFailed)` conditionne l'annulation.
- Flash "remboursé" absent dans cancelByMember : corrigé, pre-lecture avant l'appel.
- `|e|raw` dans les `confirm()` JS admin : corrigé, `|json_encode` utilisé.
- REFUND_WITHDRAWAL_DAYS sans fallback services.yaml : corrigé, `env(REFUND_WITHDRAWAL_DAYS): '14'`.
- Garde serveur CONTENU (module+leçon) absente dans `publish()` : corrigée, implémentation complète.
- Règle 6 (date passée) non implémentée dans CourseEventValidationService : corrigée, avertissement non bloquant ajouté.
- Commentaire trompeur AdminCourseController::edit() sur "inscriptions actives ET annulées" : corrigé.

## Points résiduels confirmés en E2E (à signaler dans les prochaines relectures)

### AVERTISSEMENT résiduel — Duplication calcul refundDeadline
`isEligibleForRefund()` (ligne 474) et `buildRefundNotEligibleMessage()` (ligne 610) dans EventCancellationService recalculent chacun `$refundDeadline` avec la même formule. Toujours non refactorisé vers une méthode privée `calculateRefundDeadline()`. À extraire en V2.

### SUGGESTION résiduelle — Index manquant sur course_enrollments.status
Confirmé en E2E : pas d'index sur `status` dans course_enrollments. `countActiveByCourse()` fait un scan. À ajouter avant V2 si l'audience grossit.

### POINT NOUVEAU — Bouton annulation absent de show.html.twig pour l'inscrit
La page de détail d'un événement (show.html.twig) affiche "Inscription confirmée" + lien vers le dashboard pour les inscrits, mais ne propose PAS de bouton d'annulation directement sur la page. L'annulation se fait uniquement depuis le dashboard.
- Ce comportement est intentionnel (cohérence UX : le dashboard est le point unique de gestion).
- Acceptable en V1. À reconsidérer si les retours UX montrent une confusion.
- Ne pas signaler comme bug.

### POINT NOUVEAU — Catalogue /formations : pas de chip "Événement" vs "Formation"
Le catalogue public affiche des chips de prix (Gratuit / 39,00 €) et de niveau (Débutant / Intermédiaire) mais pas de chip distinguant "Événement" vs "Formation contenu". Un utilisateur ne sait pas a priori si une carte correspond à un atelier en présentiel ou à un cours en ligne. Mineur en V1 (les sous-titres sont explicites) mais à surveiller.

### POINT NOUVEAU — CourseProposalController vide mais classe présente dans autoload
Le controller est intentionnellement vide (aucune route). Symfony charge et introspect quand même la classe. Sans impact fonctionnel, mais génère une classe inutile dans le DI container. Acceptable pour V1 (approche non-destructive documentée).

### POINT CONNU — Clés Stripe absentes en local (STRIPE_SECRET_KEY non configurée)
Les clés Stripe ne sont pas configurées dans l'environnement local. Le flux payant s'arrête proprement (flash 'error', pas de 500, pas d'inscription créée). Ce comportement est correct. Le test E2E ne peut pas tester le flux complet ; nécessite un compte Stripe en mode test.

**Why:** Ce test E2E a confirmé que tous les bugs critiques identifiés dans les relectures statiques Phase 1/2/3 ont été corrigés avant merge. Le module est stable pour V1.
**How to apply:** Pour les prochaines relectures, le module Formation peut être traité comme une référence de bonne implémentation (Voter, CSRF, IDOR, anti-double-inscription, fenêtre remboursement). Se concentrer sur les points résiduels listés ci-dessus.

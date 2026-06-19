---
name: feedback_event_cancellation_phase3_patterns
description: ADR-0014 Phase 3 — annulation et remboursement Stripe formations-événements (juin 2026) — patterns et anti-patterns identifiés. Bugs critiques corrigés confirmés en E2E le 18 juin 2026.
metadata:
  type: feedback
---

# ADR-0014 Phase 3 — Annulation + remboursement Stripe (relecture 18 juin 2026 + E2E 18 juin 2026)

## PHPStan
- Niveau 6 : 0 erreur sur tous les fichiers Phase 3 (--memory-limit=512M requis).

## Points bien faits (ne pas signaler en fausse alerte)
- Idempotence processRefund() : garde `$payment->isRefunded()` AVANT appel Stripe — protection double remboursement solide.
- CSRF présent sur TOUS les POST (membre `cancel_enrollment_{id}`, admin individuel `admin_cancel_enrollment_{enrollmentId}`, admin masse `admin_cancel_event_{courseId}`).
- Protection IDOR membre : `enrollment.getUser()->getId() !== $user->getId()` → 403, pas 404 — bonne pratique.
- Protection IDOR admin : vérification `enrollment.getCourse()->getId() !== $courseId` — empêche annulation d'inscription d'un autre cours.
- Autorisation : `#[IsGranted('IS_AUTHENTICATED_FULLY')]` membre, `#[IsGranted('ROLE_ADMIN')]` admin — correct.
- Noms de routes corrects : `app_admin_course_` (préfixe) + `enrollment_cancel`, `event_cancel` → `app_admin_course_enrollment_cancel`, `app_admin_course_event_cancel`. Ne pas signaler fausse alerte.
- Vérification côté serveur de la fenêtre de remboursement : le contrôle dans `cancelByMember()` reporte `RefundNotEligibleException` même si le bouton est masqué côté frontend.
- flush() immédiat dans `processRefund()` après l'appel Stripe : minimise la fenêtre de crash (argent remboursé sans trace en base).
- Webhook `charge.refunded` : idempotent (`isRefunded()` avant update), vérifie la signature via StripeService, ignore les charges sans CoursePayment associé.
- Migration non destructive : `DEFAULT 'active'` pour les inscriptions existantes — rétro-compatibilité assurée.
- countActiveByCourse() filtre sur `status = 'active'` → les inscriptions annulées libèrent bien leur place.
- EnrollmentStatus : backed enum PHP 8.1, valeur stockée en VARCHAR avec DEFAULT SQL — cohérent avec l'entité.
- REFUND_WITHDRAWAL_DAYS : paramétrable via .env (valeur 14), injecté via `env(int:...)` → configurable sans modifier le code.

## Bugs / avertissements retenus

### [CORRIGÉ E2E 18/06/2026] ~~BLOQUANT~~ — État incohérent dans cancelEventByAdmin() si Stripe échoue
Dans la boucle `foreach ($enrollments as $enrollment)` de `cancelEventByAdmin()` :
```php
try {
    $this->processRefund($payment);
    $refundedCount++;
} catch (\Throwable $e) {
    $this->logger->error('Remboursement Stripe échoué...', [...]);
    // PROBLÈME : pas de 'continue' ni de skip → le code continue
}
// TOUJOURS exécuté, même si processRefund() a échoué :
$enrollment->setStatus(EnrollmentStatus::CANCELLED);
$enrollment->setCancelledAt(new \DateTime());
```
→ Si Stripe échoue pour un inscrit, son inscription est quand même marquée CANCELLED.
→ Il perd sa place ET son argent (remboursement non effectué, inscription annulée).
Correction : conditionner l'annulation de l'inscription au succès du remboursement :
```php
$paymentFailed = false;
if ($payment !== null) {
    try {
        $this->processRefund($payment);
        $refundedCount++;
    } catch (\Throwable $e) {
        $this->logger->error('Remboursement Stripe échoué...', [...]);
        $paymentFailed = true;
    }
}
if (!$paymentFailed) {
    $enrollment->setStatus(EnrollmentStatus::CANCELLED);
    $enrollment->setCancelledAt(new \DateTime());
    $cancelledCount++;
    // email...
}
```

### [CORRIGÉ E2E 18/06/2026] ~~AVERTISSEMENT~~ — Flash message "remboursé" absent après cancelByMember réussi
Dans `EventCancellationController::cancelEnrollment()`, après `cancelByMember()` :
```php
$payment = $this->paymentRepository->findCompletedPaymentForRefund($user, $course);
// → retourne NULL ! processRefund() a déjà passé status à 'refunded', donc status≠'completed'
$wasRefunded = $payment !== null && $payment->isRefunded(); // → false
```
→ Le message flash affiche "Votre inscription a bien été annulée" au lieu de "... et votre paiement sera remboursé".
L'identité Map Doctrine ne compense pas car la requête SQL `WHERE status='completed'` exclut l'entité modifiée.
Correction : lire l'état remboursé AVANT d'appeler cancelByMember(), ou passer un flag de retour depuis le service :
```php
$hadPendingPayment = $this->paymentRepository->findCompletedPaymentForRefund($user, $course) !== null;
$this->cancellationService->cancelByMember($enrollment);
// Maintenant on sait si un remboursement a eu lieu :
$wasRefunded = $hadPendingPayment && $enrollment->isCancelled();
```

### [CORRIGÉ E2E 18/06/2026] ~~AVERTISSEMENT~~ — |e|raw dans les attributs onsubmit JavaScript
Dans `edit.html.twig` lignes 987 et 1039 :
```twig
onsubmit="return confirm('... {{ course.title|e|raw }} ...');"
onsubmit="return confirm('... {{ enrollment.user.email|e|raw }} ...');"
```
`|e|raw` est une combinaison incohérente : `|e` applique l'HTML escape, puis `|raw` désactive l'auto-escape (redondant ici car déjà appliqué manuellement). Le résultat est HTML-encodé mais dans un contexte JS string délimitée par `'`. Si `course.title` contient une apostrophe (`L'été`), la chaîne HTML-encodée (`L&#039;été`) est décodée par le navigateur en `L'été` avant parsing JS → casse la syntaxe `confirm('L'été')`.
Impact : confirmation JS absente → formulaire soumis sans confirmation sur les titres avec apostrophes.
Ce n'est PAS un XSS car les routes sont `ROLE_ADMIN` uniquement.
Correction : utiliser `|json_encode` pour un contexte JS sûr :
```twig
onsubmit="return confirm('Annuler l\\'événement ' + {{ course.title|json_encode }} + ' ?');"
```

### [CORRIGÉ E2E 18/06/2026] ~~AVERTISSEMENT~~ — REFUND_WITHDRAWAL_DAYS sans valeur par défaut dans services.yaml
ADMIN_EMAIL a `env(ADMIN_EMAIL): 'bonjourbazaart@gmail.com'` dans services.yaml (fallback).
REFUND_WITHDRAWAL_DAYS n'a PAS de `env(REFUND_WITHDRAWAL_DAYS): 14` dans services.yaml.
Si .env est absent ou incomplet en production → container plantera avec "Variable not found".
Correction : ajouter dans services.yaml `parameters:` section :
```yaml
env(REFUND_WITHDRAWAL_DAYS): '14'
```

### SUGGESTION — Duplication du calcul refundDeadline
`isEligibleForRefund()` et `buildRefundNotEligibleMessage()` recalculent tous deux `$refundDeadline` (même formule `createdAt + P{N}D`). L'erreur de mutation DateTime avec `->add()` sur un objet mutable est contournée par `createFromInterface()` dans les deux méthodes, mais si la règle change, il faut modifier 2 endroits.
Correction V2 : extraire `calculateRefundDeadline(CoursePayment $payment): \DateTimeImmutable` en méthode privée.

### SUGGESTION — Index manquant sur course_enrollments.status
countActiveByCourse() fait un `WHERE status = 'active'` sans index sur la colonne `status`.
Pour les événements populaires (> 100 inscrits), un sequential scan sur course_enrollments est coûteux.
À ajouter dans la migration ou en V2 :
```sql
CREATE INDEX idx_course_enrollments_status ON course_enrollments (status);
```

### SUGGESTION — Commentaire trompeur dans AdminCourseController::edit()
Ligne 230 : "on charge les inscriptions (actives ET annulées)" mais on utilise `findActiveEnrollmentsByCourse()` qui ne charge que les ACTIVES.
Correction : aligner le commentaire avec le code.

### SUGGESTION — N+1 dans cancelEventByAdmin pour les paiements
Pour chaque inscription active, `findCompletedPaymentForRefund($user, $course)` fait une requête SQL individuelle. Documenté comme acceptable en V1 (< 50 inscrits). En V2 : charger tous les paiements en une requête avec `findCompletedPaymentsByCourse($course)` puis filtrer en PHP par user.

**Why:** La Phase 3 touche à de l'argent réel. L'état incohérent dans cancelEventByAdmin est le bug le plus grave (inscrit annulé sans remboursement). Le flash message est une régression UX mais pas de sécurité. Le |e|raw dans JS est une fragilité faible (accès ROLE_ADMIN).
**How to apply:** Toujours vérifier que dans les boucles de remboursement en masse, une erreur Stripe partielle ne provoque pas d'annulation d'inscription sans remboursement.

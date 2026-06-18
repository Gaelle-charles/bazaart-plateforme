---
name: feedback-payment-stripe-antipatterns
description: Anti-patterns récurrents sur les flux de paiement Stripe et les redirections de route dans le module Formation Bazaart
metadata:
  type: feedback
---

Patterns à contrôler lors de toute relecture d'un flux de paiement Stripe ou d'un contrôleur impliquant des redirections :

1. **Incohérence de nom de route dans les blocs catch** — CoursePaymentController utilise `app_courses_show` (pluriel, inexistant) dans deux blocs `catch` et un bloc CSRF, alors que la route réelle est `app_course_show`. Les blocs de chemin heureux utilisent `app_course_show` correctement. L'erreur se produit **uniquement sur les chemins d'erreur** (token invalide, RuntimeException Stripe, ApiErrorException), donc pas visible en test nominal. À vérifier systématiquement : toutes les redirections dans les `catch` et les gardes de validation doivent utiliser le même nom de route que le chemin principal.

2. **Erreur d'email silencieuse sans logger** — EventRegistrationService::registerForEvent() swallows les exceptions d'email dans un `catch (\Throwable)` vide avec seulement un commentaire. En production, aucune trace d'un email non envoyé. Le service n'a pas de LoggerInterface injecté. Corriger en injectant LoggerInterface et en loguant en `warning` (pas `error` — l'inscription est OK, seul l'email échoue). À contraster avec StripeWebhookController qui lui logue correctement les échecs d'email.

3. **Survente webhook non notifiée à l'utilisateur** — Le scénario de survente (webhook Stripe reçu, paiement ok, événement complet) crée un CoursePayment orphelin sans inscription, et ne déclenche qu'un `logger->error`. L'utilisateur a payé mais n'a aucun retour. Acceptable en Phase 2 (Phase 3 prévoit le remboursement automatique), mais à documenter comme dette technique tracée.

4. **Idempotence webhook sur payment_intent null** — Dans handleCoursePaymentCheckoutCompleted(), si `payment_intent` est null (mode link ou erreur), on saute la vérification d'idempotence. Un webhook rejoué sans payment_intent crée un doublon de CoursePayment. Pour V1 c'est un edge case très rare (Stripe ajoute toujours payment_intent en mode=payment), mais à noter.

5. **countActiveByCourse ne filtre pas sur un statut** — La méthode compte TOUTES les inscriptions sans filtrer sur un statut actif, car l'entité CourseEnrollment n'a pas de champ `status` en V1. Le commentaire le documente clairement. À surveiller lorsqu'on ajoutera les annulations (Phase 3) : penser à filtrer sur status='active' pour ne pas compter les places annulées dans la capacité.

**Why:** Identifiés lors de la relecture Phase 2 du module Formation-événement payant (ADR-0014), 2026-06-18.
**How to apply:** Vérifier ces 5 points sur toute PR impliquant des paiements Stripe, des redirections depuis des blocs catch, ou des contrôles de capacité événement.

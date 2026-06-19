---
name: feedback_event_registration_phase2_patterns
description: ADR-0014 Phase 2 — inscription formations-événements (juin 2026) — patterns et anti-patterns identifiés
metadata:
  type: feedback
---

# ADR-0014 Phase 2 — Flux inscription événements (relecture 18 juin 2026)

## Points bien faits (ne pas signaler en fausse alerte)
- PHPStan niveau 6 : 0 erreur sur les fichiers modifiés.
- `EventRegistrationService` : service dédié, logique métier hors controller, principe SRP respecté.
- Anti-double-inscription : vérification PHP (`findByUserAndCourse`) + contrainte SQL UNIQUE (user_id, course_id) — double filet.
- Contrôle de capacité via COUNT SQL (`countActiveByCourse`) — pas de chargement collection N+1.
- Idempotence webhook : lookup par `paymentIntentId` avant création CoursePayment — conforme pattern Stripe.
- Re-vérification capacité au webhook (après paiement) avec log `error` clair pour survente — alerte manuelle Bazaart.
- Fuite d'information show.html.twig : `eventExternalUrl` et `eventLocation` absents du rendu public — conformité ADR-0014 §4.
- `eventExternalUrl` dans dashboard : `rel="noopener noreferrer"` + `target="_blank"` — sécurité tab-napping OK.
- CSRF présent sur les deux formulaires POST (enroll gratuit `enroll_{id}`, payant `course_pay_{slug}`).
- Route `/formations/{slug}/enroll` et `/formations/{slug}/payer` protégées `IS_AUTHENTICATED_FULLY` / `ROLE_USER`.
- `CoursePaymentController::pay()` : garde anti-double-achat ajoutée (`findCompletedByUserAndCourse`) — amélioration vs relecture Stripe initiale.
- Backin string `'event'` dans `findUpcomingEventsByUser` : conforme à `CourseType::EVENEMENT = 'event'`. Ne pas signaler.
- Email confirmation post-paiement : try/catch ne fait pas échouer le webhook — correct.
- `addFlash('info', ...)` dans `CoursePaymentController` : `base_app.html.twig` rend bien le type 'info' (ligne 162). Ne pas signaler.
- Le `currentPeriodEnd` abonnement : désormais récupéré via `\Stripe\Subscription::retrieve()` — CRITIQUE de la relecture précédente CORRIGÉ.

## Bugs / avertissements retenus

### [CORRIGÉ E2E 18/06/2026] ~~CRITIQUE~~ — Nom de route incorrect dans CoursePaymentController (lignes 71, 149, 153)
`app_courses_show` (avec "s") est appelé à 3 endroits alors que la vraie route est `app_course_show`.
Ces redirections lèveront une `InvalidArgumentException` Symfony au moment de l'exécution :
- L.71 : après token CSRF invalide
- L.149 : après `RuntimeException` Stripe (formation sans prix)
- L.153 : après `ApiErrorException` Stripe

L'utilisateur voit une page d'erreur 500 au lieu d'une redirection vers la fiche formation.
Correction : remplacer `'app_courses_show'` par `'app_course_show'` aux trois occurrences.

### AVERTISSEMENT — Race condition survente non résolue pour événements payants
Deux utilisateurs simultanés sur la dernière place peuvent tous deux passer le contrôle
de capacité dans `pay()` et créer deux sessions Checkout Stripe distinctes.
L'un paiera, le webhook créera son inscription. Le second paiera aussi : le webhook
détectera la survente, loguera `error`, créera le `CoursePayment` sans inscription.
L'équipe devra rembourser manuellement via le dashboard Stripe.
Ce comportement est documenté dans le code et acceptable en V1 (trafic faible).
Signaler comme risque connu, pas comme blocant.
Correction V2 : `SELECT FOR UPDATE` sur la ligne Course au moment du contrôle de capacité, ou verrou Redis.

### [CORRIGÉ E2E 18/06/2026] ~~AVERTISSEMENT~~ — Backing string enum en littéral dans le repository
`findUpcomingEventsByUser()` utilise `->setParameter('eventType', 'event')` au lieu de
`CourseType::EVENEMENT->value`. Si l'enum est renommé ou sa valeur backing changée,
le repository ne sera pas mis à jour automatiquement.
Correction : importer `CourseType` et passer `CourseType::EVENEMENT->value`.

### [CORRIGÉ E2E 18/06/2026] ~~AVERTISSEMENT~~ — Incohérence affichage `eventLocation` sur show.html.twig
L.195 : `course.eventLocation|split(',')|first|trim` → affiche la **première** partie (rue + numéro).
L.434 : `course.eventLocation|split(',')|last|trim` → affiche la **dernière** partie (ville).
Les deux blocs sont sur la même page mais extraient des parties différentes de l'adresse.
Pour "12 rue de la Roquette, 75011 Paris", le bloc hero affiche "12 rue de la Roquette" (trop précis)
et le bloc infos pratiques affiche "75011 Paris" (correct — ville/CP).
Le hero doit aussi utiliser `|last` pour ne montrer que la ville.
Correction : L.195 remplacer `|first` par `|last`.

### [CORRIGÉ E2E 18/06/2026] ~~AVERTISSEMENT~~ — `from` email hardcodé sans objet Address (sans display name)
`EventRegistrationService::sendConfirmationEmail()` utilise `->from('noreply@bazaart.fr')`.
Les autres services (`LiveService`) utilisent `->from(new Address('noreply@bazaart.fr', 'Bazaart'))`.
L'absence du display name donne "noreply@bazaart.fr" brut dans le client mail au lieu de "Bazaart".
Correction : `->from(new Address('noreply@bazaart.fr', 'Bazaart'))`.

### [CORRIGÉ E2E 18/06/2026] ~~AVERTISSEMENT~~ — Erreur email silencieuse sans logger dans `registerForEvent()`
`catch (\Throwable $e)` dans `registerForEvent()` avale l'exception sans log ni trace.
En production, un email qui échoue sera invisible.
Correction : injecter `LoggerInterface` dans `EventRegistrationService` et ajouter
`$this->logger->warning('Envoi email confirmation événement échoué', ['error' => $e->getMessage()])`.

### SUGGESTION — Validation URL `eventExternalUrl` absente côté PHP
`eventExternalUrl` est stockée sans validation de schéma (http/https uniquement).
Si l'admin saisit `javascript:alert(1)` ou une URL arbitraire, elle sera affichée brut
dans `dashboard/index.html.twig` via `href="{{ event.eventExternalUrl|e }}"`.
Le filtre `|e` échappe le HTML mais pas le schéma de l'URL en attribut `href` — vecteur XSS via `javascript:`.
Correction : ajouter `#[Assert\Url]` sur `Course::$eventExternalUrl` dans l'entité,
et/ou un preflight côté Twig : `{% if event.eventExternalUrl starts with 'http' %}`.

### SUGGESTION — Pas de nettoyage après inscription si flux payant redirige vers enroll
Le bloc "Cas 1 : Événement PAYANT" dans `enroll()` utilise `addFlash('info', ...)` et redirige
vers `show`. Le flash 'info' est rendu par `base_app.html.twig` (ligne 162 — confirmé).
Pas de bug, mais le message "Cet événement est payant. Veuillez utiliser le bouton de paiement."
peut dérouter si l'utilisateur arrive ici via un lien direct (ex : share de l'URL de soumission).
Acceptable en V1.

**Why:** La route `app_courses_show` (avec "s") est le bug le plus impactant car il plantera en production
dès qu'une erreur Stripe survient ou qu'un token CSRF est invalide pendant un paiement.
**How to apply:** Toujours vérifier la cohérence des noms de route après renommage.
Les routes nommées `app_course_` (sans "s") + suffixe sont le pattern de ce controller.

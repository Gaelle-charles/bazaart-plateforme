---
name: feedback_stripe_integration_patterns
description: Module Stripe (juin 2026) — patterns et anti-patterns identifiés lors de la relecture des entités, services et contrôleurs Stripe
metadata:
  type: feedback
---

# Module Stripe — patterns et anti-patterns (relecture 15 juin 2026)

## Points bien faits (ne pas signaler en fausse alerte)
- Signature webhook vérifiée EN PREMIER dans StripeWebhookController avant tout traitement (pattern correct).
- `#[IsGranted('PUBLIC_ACCESS')]` sur le webhook — seul endpoint Stripe légitime sans auth.
- CSRF présent sur `subscribe()` et `pay()` (tokens distincts par plan/slug).
- Routes abonnement et paiement protégées avec `#[IsGranted('ROLE_USER')]` individuellement.
- Aucune clé API en dur dans le code — tout passe par `%env(STRIPE_*)%` dans services.yaml.
- Idempotence sur les deux flux webhook : lookup par `stripeSubscriptionId` (abonnements) et `stripePaymentIntentId` (formations).
- `isActive()` : double garde (status IN active/trialing ET currentPeriodEnd > now) — filet solide contre webhook retardé.
- PHPStan niveau 6 : 0 erreur.
- Schéma Doctrine en sync avec les entités (doctrine:schema:validate OK).
- Index sur colonnes de lookup fréquent : `idx_subscriptions_user`, `idx_subscriptions_stripe_id`, `idx_course_payments_user_course`.
- Redirection 303 (HTTP_SEE_OTHER) après POST — conforme au pattern PRG.
- `onDelete: 'CASCADE'` explicite sur les FK User et Course dans CoursePayment.

## Bugs / avertissements à retenir

### CRITIQUE — currentPeriodEnd approximé (StripeWebhookController ligne 246-249)
Sur `checkout.session.completed` (mode=subscription), `currentPeriodEnd` est calculé localement
(`new \DateTime('+1 year')`) au lieu d'être lu depuis l'objet Subscription Stripe.
En cas de promotion (période d'essai, premier mois offert), la date de fin sera fausse et
`isActive()` accordera ou refusera l'accès à tort.
Correction : récupérer l'abonnement Stripe complet via `\Stripe\Subscription::retrieve($stripeSubscriptionId)`
et lire `current_period_end` depuis l'objet retourné.

### AVERTISSEMENT — Route app_pricing manquante dans debug:router
La route `/tarifs` (app_pricing) apparaît bien dans SubscriptionController mais n'est pas
listée par le grep `stripe|subscription|course_pay`. C'est normal — le grep ne couvre pas
cette route. Pas de bug mais à vérifier manuellement si la page tarifaire est inaccessible.

### AVERTISSEMENT — Pas de vérification d'achat existant dans CoursePaymentController
`CoursePaymentController::pay()` ne vérifie pas si l'utilisateur a déjà acheté la formation
avant de créer une nouvelle session Checkout. Un double-clic ou une navigation arrière peut
générer deux sessions actives simultanément. Le webhook est idempotent sur `paymentIntentId`
mais un utilisateur peut quand même payer deux fois s'il a deux sessions Checkout ouvertes.
Correction : ajouter un appel `CoursePaymentRepository::findCompletedByUserAndCourse()` avant
`createCourseCheckoutSession()` et rediriger vers la formation si déjà achetée.

### AVERTISSEMENT — onDelete: 'CASCADE' sur CoursePayment.course (note comptable)
Si une formation est supprimée physiquement, ses CoursePayment sont supprimés en cascade —
perte des preuves d'achat pour la comptabilité. Le commentaire le note (V2 soft-delete)
mais c'est un risque légal à dater. À bloquer avant mise en prod avec des formations payantes.

### AVERTISSEMENT — flash 'info' dans SubscriptionController
`subscribe()` et `manage()` utilisent `addFlash('info', ...)`. Vérifier que base.html.twig
(template public) rend bien le type 'info' (base_admin.html.twig est corrigé, mais base.html.twig
est distinct). Si le rendu est absent, l'utilisateur ne verra pas le message "abonnement déjà actif".

### SUGGESTION — Plan stocké comme string libre dans Subscription
`plan` est un `string(20)` sans contrainte d'enum. Si une valeur inattendue entre en BDD
(via webhook avec metadata corrompues), `isActive()` reste correct mais les templates
Twig affichant le plan (`manage.html.twig`) pourraient afficher une valeur inconnue.
Correction : convertir `plan` en enum PHP `SubscriptionPlan` avec cases `Monthly` et `Annual`.

### SUGGESTION — Status string libre dans Subscription et CoursePayment
Même remarque que pour `plan` : les statuts Stripe ('active', 'past_due', etc.) sont des
strings libres. Un enum `SubscriptionStatus` / `CoursePaymentStatus` éviterait les typos
et documenterait les cas supportés.

### SUGGESTION — $sigHeader cast silencieux si header absent
`$request->headers->get('Stripe-Signature', '')` retourne `''` (string vide) si absent.
La garde suivante (`if ($sigHeader === '')`) l'attrape correctement. Pas de bug, mais la
doc Symfony précise que `get()` peut retourner `null` sur certaines versions — le cast en
`string` via la valeur par défaut `''` est la bonne approche. Ne pas signaler.

**Why:** Le bug critique du currentPeriodEnd est potentiellement invisible en test (les dates
+1 mois/+1 an coïncident avec la réalité la plupart du temps en dehors des promos),
mais devient dangereux dès qu'une période d'essai ou un coupon est configuré côté Stripe.
**How to apply:** Signaler CRITIQUE en priorité absolue sur ce point à chaque relecture Stripe.

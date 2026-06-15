---
name: project-stripe
description: Intégration Stripe Bazaart — IDs produits, prix, webhook, architecture paiements V1
metadata:
  type: project
---

Intégration Stripe démarrée le 2026-06-15. Lancement V1 le 2026-06-22.

**Why:** Gaëlle a demandé Stripe avant le lancement (initialement prévu V2 dans le CDC). Décision arbitrée le 15 juin : Option B (pas de Connect, reversements manuels).

**How to apply:** Toujours utiliser ces IDs dans le code et les variables d'env. Ne pas recréer de produits.

## Compte Stripe

- Compte LIVE : `acct_1TihOvFSLekF5f8u` — association, France, charges_enabled ✅
- Compte TEST (dev local) : `acct_1TihPXJthK6FlBBJ` — compte séparé, non vérifié (normal pour les tests)

## Produits et prix

| Élément | ID LIVE | ID TEST |
|---|---|---|
| Produit abonnement | `prod_Ui7uEbS2SOADxH` | (non enregistré) |
| Prix mensuel 9,90€ | `price_1TiheoFSLekF5f8uFrBSp3SA` | `price_1Tihf3JthK6FlBBJltRQQYny` |
| Prix annuel 79€ | `price_1TihepFSLekF5f8umCro8cas` | `price_1Tihf4JthK6FlBBJLHSS9gNL` |

## Webhook

- Endpoint ID : `we_1TihkPFSLekF5f8ubeukk1zm`
- URL : `https://app.bazaart.fr/stripe/webhook`
- Événements : `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`
- Secret (`whsec_`) : dans `.env.local` dev et à mettre dans `.env.local` prod

## Variables d'env prod à ajouter sur le serveur

```
STRIPE_PUBLIC_KEY=pk_live_***  (voir .env.local sur le serveur)
STRIPE_SECRET_KEY=sk_live_***  (voir .env.local sur le serveur)
STRIPE_WEBHOOK_SECRET=whsec_*** (voir .env.local sur le serveur)
STRIPE_PRICE_MONTHLY=price_1TiheoFSLekF5f8uFrBSp3SA
STRIPE_PRICE_ANNUAL=price_1TihepFSLekF5f8umCro8cas
```

## Architecture choisie (Option B — pas de Connect)

- **Abonnements** (mensuel 9,90€ / annuel 79€) : Stripe Billing, accès communauté + ressourcerie premium
- **Formations** : paiement unique par formation, prix défini par l'admin sur chaque `Course` (champ `priceInCents`)
- **L'abonnement N'inclut PAS les formations** — toujours payées séparément
- **Commission formateurs** : reversement manuel par Bazaart (Connect = V2)

## Entités créées

- `Subscription` — abonnement actif d'un user (stripe_subscription_id, plan, status, currentPeriodEnd)
- `CoursePayment` — preuve d'achat d'une formation (stripe_payment_intent_id, amountInCents, status)
- `Course` — 3 nouveaux champs : `priceInCents`, `stripeProductId`, `stripePriceId`

## Services/Contrôleurs créés

- `StripeService` — sessions Checkout, création produits, vérification webhooks
- `StripeWebhookController` — POST /stripe/webhook
- `SubscriptionController` — /tarifs, /subscribe/{plan}, /subscription/success|cancel|manage
- `CoursePaymentController` — /formations/{slug}/payer, /formations/paiement-succes|annule

## SDK

- `stripe/stripe-php` v20.2.1 installé via Composer

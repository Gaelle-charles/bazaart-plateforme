# ADR-0028 — Essai gratuit d'un mois automatique à l'inscription

- **Date** : 2026-06-27
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le modèle freemium initial (ADR-0022) prévoit qu'un artiste qui s'inscrit accède
immédiatement à la version gratuite, limitée (en V1 : 3 consultations de matching
par jour, cf. ADR-0022 mis à jour juin 2026). Les fonctionnalités premium (matching
illimité, catalogue complet, messagerie, alertes) sont réservées aux abonnés Stripe.

Pour favoriser l'adoption au lancement (15 juin 2026), on souhaite que **tout artiste
qui s'inscrit bénéficie d'un mois d'accès complet à toutes les fonctionnalités**, à
compter de son inscription. Passé ce mois, il bascule automatiquement vers la version
gratuite (3 matchings/jour), sauf s'il a souscrit un abonnement payant entre-temps.

Le paywall repose entièrement sur `SubscriptionChecker::isSubscribed()` : tous les
verrous premium (matching, catalogue, messagerie, alertes) passent par ce point unique.
L'essai doit donc être branché à cet endroit pour débloquer tout d'un coup.

Contrainte clé : l'essai est **automatique et sans paiement** (pas de checkout Stripe à
l'inscription). Le statut `trialing` natif de l'entité `Subscription` ne convient pas,
car il suppose un abonnement Stripe créé via checkout.

## Options envisagées

1. **Option A — Champ `trialEndsAt` sur le compte, vérifié dans `isSubscribed()`**
   - À l'inscription, on stocke `trialEndsAt = inscription + 1 mois`.
     `isSubscribed()` renvoie aussi `true` tant que `maintenant < trialEndsAt`.
   - ✅ Simple, automatique, aucune dépendance Stripe, une seule migration.
   - ✅ Débloque toutes les fonctionnalités premium via un seul point de modification.
   - ✅ Stripe conserve son rôle pour les abonnements payants après l'essai.
   - ⚠️ Concept d'accès parallèle à Stripe ; messages de paywall à adapter si on veut
     un libellé spécifique « essai » (reporté, cf. Conséquences).

2. **Option B — Essai natif Stripe (`trial_period_days=30`)**
   - Créer un abonnement Stripe en mode essai à chaque inscription.
   - ✅ Source de vérité unique (Stripe), `isActive()` gère déjà `trialing`.
   - ❌ Crée un client + abonnement Stripe à chaque inscription (appels API au signup,
     risque de blocage de l'inscription en cas d'incident Stripe).
   - ❌ Les essais Stripe attendent généralement une carte → incompatible avec
     « tout le monde, sans payer ».
   - ❌ Lourd et fragile pour un simple « 1 mois offert à tous ».

## Décision

**Option A.** L'essai est automatique, sans paiement et au niveau du compte : un champ
date `User::$trialEndsAt` vérifié dans `SubscriptionChecker::isSubscribed()` est la
solution la plus légère et la plus robuste. Stripe reste dédié aux abonnements payants.

Paramètres arbitrés :
- **Démarrage** : à l'inscription (`createdAt`), durée 1 mois.
- **Artistes déjà inscrits** : on leur offre 1 mois frais **à partir de la date de
  déploiement** (geste de lancement), via un backfill SQL ponctuel en production.
- **Périmètre** : l'essai débloque **tout** le premium (matching illimité, catalogue
  complet, messagerie, alertes). À la fin de l'essai, retour **exact** à la version
  gratuite actuelle (3 matchings/jour, rien d'autre ne change).
- **Bandeau dashboard** « Il te reste X jours d'essai » : **non inclus** pour l'instant
  (finition ultérieure). Comme `isSubscribed()` vaut `true` pendant l'essai, le paywall
  ne s'affiche jamais durant le mois : aucun message à modifier dans l'immédiat.

## Conséquences

- **Entité `User`** : nouveau champ `trialEndsAt` (datetime, nullable), initialisé à
  `createdAt + 1 mois` à la création du compte. Migration schéma (colonne nullable).
- **`SubscriptionChecker::isSubscribed()`** : ajoute la condition d'essai. La méthode
  signifie désormais « a un accès premium » (admin, abonnement actif, **ou** essai en
  cours). Tous les verrous premium en héritent automatiquement.
- **Backfill production** : `UPDATE users SET trial_ends_at = now() + interval '1 month'
  WHERE trial_ends_at IS NULL`, exécuté une fois après la migration (date = jour du
  déploiement, conforme à la décision). Hors migration pour garder celle-ci schéma-only.
- **Écart au CDC / ADR-0022** : le parcours freemium change au lancement (1 mois complet
  avant la limite 3/jour). ADR-0022 reste valable pour l'état « gratuit » post-essai.
- **À surveiller** : à l'expiration de l'essai, vérifier que le paywall matching
  réapparaît correctement (3/jour) et que les autres verrous premium se réactivent.
- **Évolution possible (V2)** : bandeau « X jours d'essai restants » + email J-3 avant
  expiration pour inciter à l'abonnement.

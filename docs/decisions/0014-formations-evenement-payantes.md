# ADR-0014 — Deux types de formation : « contenu » et « événement » (visio/présentiel) payant

- **Date** : 2026-06-18
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le module Formation V1 gère des formations **« contenu »** (texte + vidéo, modules → leçons,
suivi de progression), avec paiement Stripe déjà fonctionnel (`Course.priceInCents`,
`stripeProductId/PriceId`, entité `CoursePayment`, `CoursePaymentController`, checkout).

Gaëlle souhaite ajouter un **second type de formation : « événement »**, en **visio ou en
présentiel**, géré par les admins, avec paiement possible. Les membres pourront s'inscrire et
payer.

⚠️ **Écart au CDC V3 (avancement de périmètre V2)** : le CDC classe explicitement en V2 la
« Billetterie événements (admin-only) » (Module 6) et le « paiement à la formation à l'unité »
(évolutions Formation V2). La plateforme étant lancée (15 juin 2026) et Gaëlle pilotant le
produit, elle décide d'avancer ce périmètre. L'infra de paiement existant rend l'effort
raisonnable. À refléter dans le CDC.

Distinction importante : les entités `Live` / `LiveAttendee` (lives **communautaires gratuits**
créés par les membres, lien externe) ne sont **pas** réutilisées — concept différent.

## Options envisagées

1. **Option A — Champ `type` sur `Course` + champs événementiels nullable (retenue)**
   - ✅ Réutilise catalogue, back-office, slug et surtout le **paiement Stripe + `CoursePayment`** ; effort minimal ; un seul catalogue.
   - ❌ Champs nullable conditionnels selon le type ; affichage à brancher par type.
2. **Option B — Entité dédiée `FormationEvent`** — modèle propre mais duplication (catalogue, paiement, admin), beaucoup plus de code. Écartée.
3. **Option C — Réutiliser `Live`/`LiveAttendee`** — dénature le concept communautaire gratuit, pas de catalogue/admin propre. Écartée.

## Décision

**Option A.** Un champ `type` (CONTENU | EVENEMENT) sur `Course` + champs événementiels, en
réutilisant le paiement existant.

Réponses produit arbitrées par Gaëlle :
1. **Gratuit OU payant** : une formation-événement peut être gratuite (prix nul) ou payante.
2. **Places limitées** : capacité maximale par événement (pas de liste d'attente en V1 → V2).
3. **Visio = lien externe** (Meet/Zoom/Jitsi), comme les lives V1. Présentiel = adresse/lieu.
4. **Email d'inscription** : à l'inscription (payée ou gratuite), l'utilisateur reçoit un email
   qui le **renvoie vers son dashboard** où se trouve le lien visio / l'adresse.
   → Choix produit assumé : **ne PAS mettre le lien direct dans l'email**, pour inciter les
   membres à revenir sur la plateforme. Le lien/adresse vit dans le dashboard.
5. **Annulation + remboursement** : comme le paiement passe par Stripe, on met en place
   l'annulation d'inscription avec **remboursement Stripe** (refund API).

   **Précision (2026-06-18) — règle de remboursement Phase 3 :**
   - **Membre** : remboursement intégral si annulation **dans les 14 jours suivant l'achat
     ET avant le début de l'événement** (le délai légal de rétractation est appliqué comme
     fenêtre d'annulation, par choix de Gaëlle). Le plafond « avant le début de l'événement »
     est un garde-fou indispensable (empêche d'assister puis de se faire rembourser). Au-delà
     de l'un ou l'autre seuil : plus de remboursement automatique. Événement gratuit :
     annulation simple, sans remboursement. La durée (14 j) est **paramétrable** (paramètre
     applicatif) pour pouvoir l'ajuster sans modifier le code.
   - **Admin** : peut annuler un événement et rembourser **à tout moment** (remboursement en
     masse des inscrits payants = annulation d'événement). **Inclus en Phase 3** (décision
     Gaëlle, ne plus différer en V2).
   - ⚠️ Cette politique de remboursement **doit figurer dans les CGV** (pages légales encore
     en placeholders `[À COMPLÉTER]`). Nuance juridique : pour un événement à date fixe, le
     droit de rétractation de 14 j n'est légalement pas obligatoire (art. L221-28 Code de la
     conso) ; l'offrir ici est un choix commercial assumé.

## Conséquences

- **Modèle** : nouveaux enums `CourseType`, `CourseEventMode` ; nouveaux champs sur `Course`
  (type, eventMode, eventStartAt, eventEndAt, eventLocation, eventExternalUrl, capacity) — tous
  nullable pour ne pas casser les formations « contenu ». Migration requise.
  Inscription événement réutilise `CourseEnrollment` (= une place), contrôle de capacité.
  `CoursePayment` gère un statut « remboursé ».
- **Back-office admin** : formulaire de création/édition adapté selon le type (champs
  conditionnels), gestion de la capacité et des inscrits.
- **Flux membre** : inscription gratuite (directe, contrôle de place) ou payante (checkout
  Stripe existant → succès → inscription + email). Accès (lien/adresse) affiché dans le
  dashboard. Annulation → remboursement Stripe → place libérée.
- **Sécurité argent** : remboursements testés en **mode Stripe TEST** avant LIVE.
- **CDC V3** : avancement de périmètre V2 (billetterie / paiement formation) — à refléter.
- **À surveiller / décisions ouvertes différées** :
  - Pas de **liste d'attente** en V1 (événement « complet » → inscription bloquée).
  - **Annulation d'un événement par l'admin** (remboursement en masse des inscrits) : à
    confirmer comme inclus ou différé.
  - Webhook Stripe `charge.refunded` à gérer pour fiabiliser le statut « remboursé ».

## Livraison par phases (pour sécuriser la partie argent)

- **Phase 1** : data model (enums + champs `Course` + migration) + back-office admin + fixture
  d'exemple. Sans risque Stripe.
- **Phase 2** : flux d'inscription (gratuit + payant via Stripe existant), contrôle de capacité,
  email de confirmation → dashboard, affichage de l'accès dans le dashboard.
- **Phase 3** : annulation + remboursement Stripe (testé en mode TEST), webhook `charge.refunded`.
- **Frontend** (`bazaart-frontend`) : cartes catalogue (date/mode/places), page détail
  événement, widget dashboard « Mes formations & événements ».

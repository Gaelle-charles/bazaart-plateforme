# ADR-0015 — Confirmation d'email + onboarding obligatoire (artistes et structures)

- **Date** : 2026-06-18
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Aujourd'hui l'inscription (`/register`) crée le compte puis renvoie vers `/login`. Il n'y a
**aucune confirmation d'email** (le champ `User.isVerified` existe mais n'est pas utilisé dans
un parcours), et **aucun onboarding**. Gaëlle veut, après l'inscription :
1. une **confirmation d'email** (Option A) ;
2. un **onboarding obligatoire** (non skippable) qui collecte des informations exploitées par
   le **dashboard**, par les **alertes** (Ressourcerie) et **visibles par les admins** ;
3. un onboarding **pour les artistes ET les structures**.

Briques réutilisables existantes : `ArtistProfile` (displayName, bio, localisation, disciplines,
liens), `ResourceAlert` (1 par user : notifyOnNewResource, fréquence, disciplines filtrées,
types de ressources filtrés), et tout le circuit structure (`/structure/register` →
`OrganizationProfile` candidature → validation admin, cf. ADR-0008).

## Décisions

- **Confirmation d'email (Option A)** : envoi d'un email à l'inscription ; au clic du lien,
  `isVerified=true`. Connexion gating : un compte non vérifié ne peut pas accéder à l'app.
- **Onboarding OBLIGATOIRE** (non skippable) : tant qu'il n'est pas terminé, l'utilisateur est
  redirigé vers l'onboarding. Flag `User.onboardingCompleted` (ou équivalent) pour le suivi.
- **Étape 1 commune** : « Tu es artiste ou structure ? »
- **Branche Artiste** → remplit `ArtistProfile` (nom affiché, localisation, disciplines, bio,
  liens) + crée les préférences d'alertes (`ResourceAlert`) + question « Que recherches-tu ? ».
  → atterrit sur le dashboard.
- **Branche Structure** → **FUSION avec la candidature existante** (Option A architecture) :
  l'onboarding structure remplit `OrganizationProfile` (réutilise le service de candidature) et
  déclenche la **validation admin déjà en place** (ADR-0008). On ajoute 2 champs : **champ
  d'activité** et « Que recherchez-vous ? ». → atterrit sur « candidature en attente ».
  `/structure/register` est unifié avec l'onboarding (même formulaire / porte d'entrée).
- **Question « Que recherches-tu ? »** (choix multiples + « Autre » → champ libre), **visible
  par les admins** :
  - Artiste : Formations / Ressources (aides, appels…) / Autre.
  - Structure : Publier une ressource / Trouver des artistes / Partenariat / Autre.
- **Pré-remplissage des alertes** : les choix « ressources/appels » pré-cochent les types
  correspondants du `ResourceAlert` (proposé, à confirmer côté artiste).

## Approche de la confirmation d'email — arbitré

**Option 1 retenue : `symfonycasts/verify-email-bundle`** (accord explicite de Gaëlle pour le
`composer require`, cf. CLAUDE.md §12). Standard Symfony, liens signés expirables.
L'envoi reste assuré par Symfony Mailer (Brevo en prod, Mailpit en dev).
Emails de confirmation **personnalisés / brandés Bazaart** (template Twig HTML).

## Conséquences

- **Modèle** : nouveaux champs sur `User` (`onboardingCompleted`, `lookingFor` json,
  `lookingForOther`), sur `OrganizationProfile` (`activityField`). Migration.
- **Sécurité / flux** : modification de `/register`, du login (gating `isVerified`), nouveau
  parcours d'onboarding, route de vérification d'email.
- **Réutilisation** : circuit structure (ADR-0008) et entités profil/alertes existantes.
- **Admin** : vue listant « que recherche » + champ d'activité (pilotage de l'offre).
- **Fixtures** : les comptes de test (admin/artiste/structure) doivent être `isVerified=true`
  et onboarding marqué terminé, sinon ils seraient bloqués par le gating.
- **Découpage** : Lot 1 = confirmation d'email ; Lot 2 = onboarding artiste (+ dashboard) ;
  Lot 3 = onboarding structure (fusion candidature) + vue admin.
- **À surveiller** : RGPD (email de confirmation = traitement), cohérence avec les pages
  légales en cours.

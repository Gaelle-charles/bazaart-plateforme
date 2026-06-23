# ADR-0026 — Matching : connexion obligatoire, sans onboarding obligatoire

- **Date** : 2026-06-22
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le parcours d'accès au matching était jugé « trop périlleux » : un **visiteur non
connecté** voyait, dès la page d'accueil, l'intégralité du formulaire multi-étapes
de matching (disciplines, objectifs, nom, localisation, statut). Il devait le
remplir **avant** de créer un compte, puis confirmer son email, puis (selon le cas)
repasser par l'onboarding. Beaucoup d'abandons sur ce parcours long.

Par ailleurs, un utilisateur **connecté mais pas encore artiste** (sans
`ROLE_ARTIST`) était bloqué par un écran « Le matching est réservé aux artistes —
complète ton profil artiste » qui le renvoyait vers l'onboarding complet : une
étape supplémentaire ressentie comme lourde.

Ce changement révise le parcours défini par l'ADR-0021 (Lot C, formulaire visiteur
sur la home) et l'ADR-0023 (profil complet obligatoire pour le matching).

## Options envisagées

1. **Statu quo** — formulaire visible par les visiteurs, onboarding poussé aux
   connectés non-artistes.
   - Avantage : aucun développement.
   - Inconvénient : parcours long, fort taux d'abandon avant inscription.
2. **Connexion obligatoire + formulaire de matching inline pour tout connecté
   (retenue).**
   - Le visiteur non connecté ne voit plus le formulaire mais un CTA
     « crée ton compte / connecte-toi ».
   - Tout utilisateur **connecté** (artiste ou non, profil complet ou non) peut
     remplir le formulaire de matching directement sur la home ; sa soumission
     enregistre son profil et lui accorde `ROLE_ARTIST`.
   - Avantage : on inscrit la personne d'abord (moins d'abandon), puis on collecte
     les infos via un formulaire court, sans onboarding obligatoire.
   - Inconvénient : on perd la collecte « pré-inscription » des réponses du
     visiteur (le pré-remplissage de l'onboarding depuis la session devient
     secondaire).
3. **Tout ouvrir sans connexion** — matching accessible aux anonymes.
   - Écarté : impossible de rattacher les matchs/alertes à un compte, pas de
     persistance, contraire au besoin de connexion exprimé.

## Mise à jour (2026-06-23) — accroche par le formulaire

Ajustement de la mise en œuvre côté visiteur, sans changer le principe (« connexion
obligatoire pour accéder au matching ») :

- Le visiteur non connecté **revoit le formulaire de matching** (accroche / engagement)
  au lieu d'un simple CTA.
- Mais dès le clic sur **« Continuer »**, comme il n'est pas connecté, il est
  **redirigé vers la page d'inscription** (`/register?intent=artist`). Ses réponses
  de l'étape sont d'abord **sauvegardées en session** (best-effort) pour pré-remplir
  l'inscription/onboarding.
- Mise en œuvre : `HomeController` prépare le formulaire dès que le profil n'est pas
  complet (`$needsMatchingForm = !$profileComplete`, visiteurs inclus) ;
  `matching-form.js` redirige le visiteur au clic « Continuer »
  (`data-register-url`) ; filet de sécurité sans JS conservé (Flux A de
  `MatchingFormController` : POST → session → redirection inscription).

Le point 1 de la décision ci-dessous est donc nuancé : on ne montre plus un CTA nu au
visiteur, mais le formulaire lui-même — l'accès reste verrouillé par l'inscription.

## Décision

**Option 2 retenue.** Règles :

1. **Connexion obligatoire** : l'accès réel au matching est verrouillé par
   l'inscription. (Mise à jour 2026-06-23 : le visiteur voit le formulaire en accroche,
   mais le clic « Continuer » le redirige vers l'inscription — cf. section ci-dessus.)
2. **Pas d'onboarding obligatoire** : tout utilisateur connecté au profil non
   complet voit le formulaire de matching et peut le remplir directement. La
   soumission (Flux B) enregistre le profil via `OnboardingService` et accorde
   `ROLE_ARTIST`.
3. La notion de **profil complet** (ADR-0023 : nom + localisation + ≥1 discipline +
   lookingFor) **reste** le critère qui fait passer de « formulaire » à « cartes de
   swipe ». On ne supprime pas cette exigence : on supprime seulement le **détour
   par l'onboarding** et l'accès anonyme.

## Conséquences

- **Code** :
  - `HomeController` : `$needsMatchingForm = ($user instanceof User) && !$profileComplete;`
    (formulaire préparé pour tout connecté au profil non complet ; pas pour les
    visiteurs).
  - `MatchingFormController::submit()` : le Flux A (session + redirection /register)
    ne se déclenche plus que pour un visiteur réellement non connecté
    (`!$user instanceof User`) ; tout connecté passe au Flux B.
  - `templates/vitrine/index.html.twig` : machine à états de la section matching
    revue — État 3 « tout connecté au profil non complet → formulaire », État 4
    « visiteur → CTA connexion ». L'ancien état « réservé aux artistes » est retiré.
- **Aucune migration.**
- **Révise** : ADR-0021 (le formulaire n'est plus proposé aux visiteurs) et
  ADR-0023 (le profil complet reste requis pour les cartes, mais la complétion se
  fait via le formulaire inline, plus via l'onboarding obligatoire). Voir
  [[0021-systeme-matching-artiste-ressource]] et
  [[0023-profil-complet-obligatoire-pour-matching]].
- **À surveiller** : le Flux A résiduel (POST direct non connecté) reste un filet de
  sécurité ; le bloc « inscription » interne au formulaire (`mf-signup-cta`) devient
  inutilisé sur la home (les visiteurs ne voient plus le formulaire) — nettoyage
  possible plus tard.

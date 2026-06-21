# ADR-0023 — Profil complet obligatoire pour accéder au matching

- **Date** : 2026-06-21
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Un utilisateur qui venait de s'inscrire pouvait accéder à la fonctionnalité de
matching sans avoir réellement rempli son profil. La règle de complétude en
vigueur (cf. ADR-0021) était trop faible : elle n'exigeait que la présence d'un
`ArtistProfile`, d'au moins une discipline et d'au moins un objectif `lookingFor`.
Le nom affiché et la localisation n'étaient pas requis, si bien qu'un profil
« creux » (sans nom ni ville) pouvait obtenir des matchs.

Or la pertinence du matching et l'esprit « plateforme pensée pour les artistes »
supposent des profils réellement exploitables. Il fallait donc renforcer la
barrière d'accès au matching.

## Options envisagées

1. **Option A — garder la définition actuelle** (discipline + lookingFor).
   - Avantage : accès rapide, friction minimale.
   - Inconvénient : profils creux (sans nom/localisation) dans le système.
2. **Option B — renforcer le gate** : exiger en plus le **nom affiché** et la
   **localisation** avant d'accéder au matching (bio laissée optionnelle).
   - Avantage : profils exploitables, conforme à l'objectif de la plateforme.
   - Inconvénient : plus de friction ; nécessite que le parcours de complétion
     collecte effectivement ces champs.

## Décision

**Option B retenue.** Un profil est « complet pour le matching » si et seulement
si les **5 critères** suivants sont réunis :
1. un `ArtistProfile` existe,
2. `displayName` (nom affiché) non vide,
3. `location` (localisation) non vide  ← nouveau critère,
4. au moins une discipline,
5. au moins un objectif `lookingFor`.

La bio reste optionnelle.

Pour que remplir le mini-formulaire matching de la home suffise à compléter le
profil, ce formulaire est **étendu** (choix de Gaëlle, plutôt qu'une redirection
vers l'onboarding) afin de collecter aussi le **nom** et la **localisation**,
rendus requis dès l'étape 1.

## Conséquences

- **Source de vérité unique** : la règle est centralisée dans le service
  `MatchingProfileChecker::isComplete()`, utilisé par `HomeController` et
  `MatchingController`. Plus de duplication de la logique de complétude.
- **API durcie** : `/api/matching/my-matches` et `/api/matching/count` renvoient
  un résultat vide tant que le profil n'est pas complet (un profil incomplet ne
  peut jamais récupérer de matchs).
- **Formulaire matching home** : étape 1 « Toi » = nom + localisation + disciplines
  (nom et localisation requis, validés côté client et côté serveur). Le Flux B
  (artiste connecté) n'invente plus de nom placeholder et refuse une localisation
  vide.
- **Limite connue (non bloquante)** : pour un visiteur (Flux A), le nom et la
  localisation saisis dans le formulaire ne sont pas encore pré-remplis à
  l'onboarding (pré-remplissage depuis la BDD uniquement). Amélioration possible
  ultérieurement.
- **Lien** : complète et durcit l'ADR-0021 (système de matching artiste-ressource).

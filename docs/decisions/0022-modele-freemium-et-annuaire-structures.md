# ADR-0022 — Modèle freemium (paywall abonnement) + annuaire et dashboard structures

- **Date** : 2026-06-19
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le matching (ADR-0021) est l'entrée gratuite de la plateforme. Gaëlle veut un **modèle freemium** :
le gratuit donne accès au matching limité ; le reste (catalogue complet, alertes, annuaires,
messagerie) est réservé aux **abonnés** (Stripe, déjà en place). Elle veut aussi un **annuaire des
structures** et un **dashboard structure** dédié à la publication de ressources, en distinguant les
structures « agrégateur » (publient régulièrement) des structures « ponctuel ».

## Décisions — Matrice d'accès

| Fonctionnalité | Gratuit | Abonné |
|---|---|---|
| Matching (swipe) | ✅ mais **3 consultations / semaine** (reset hebdo), au-delà → /tarifs | ✅ illimité |
| Catalogue ressources complet (/resources) | ❌ → /tarifs | ✅ illimité |
| Alertes personnalisées | ❌ | ✅ |
| Annuaire artistes | ❌ | ✅ |
| Annuaire structures | ❌ | ✅ |
| Messagerie privée | ❌ | ✅ |

- **« 3 consultations »** = 3 ressources consultées **par semaine** (compteur hebdomadaire par
  utilisateur), puis affichage des tarifs.
- L'infra abonnement existe (entité `Subscription` + `isActive()` + Stripe + `/tarifs` +
  `/subscription/manage`) : le paywall **gate** et **redirige vers /tarifs** ; l'achat existe déjà.

## Décisions — Structures

- **Annuaire structures** : nouvelle page (à créer ; seul l'annuaire artistes existe aujourd'hui),
  réservée aux abonnés.
- **Dashboard structure** : dédié à la **publication de ressources** (la soumission de ressources
  par les structures existe déjà, cf. ADR-0008).
- **Distinction agrégateur / ponctuel** : un attribut sur `OrganizationProfile` indiquant si la
  structure publie **régulièrement** (agrégateur → candidate à devenir une **source de scraping**)
  ou **ponctuellement**. Synergie avec la découverte de sources (ADR-0017).

## Conséquences

- **Contrôle d'abonnement** : un service/Voter `isSubscribed` (réutilise `Subscription::isActive`).
- **Gating** : catalogue, alertes, annuaires (artistes + structures), messagerie → abonnés ; sinon
  redirection /tarifs avec écran d'incitation.
- **Compteur de consultations matching** : 3/semaine pour les gratuits (stockage par utilisateur +
  fenêtre glissante hebdo) ; au-delà → /tarifs.
- **Onboarding** : requis pour utiliser le matching (profil), plus de blocage sur tout le site
  (cf. ADR-0021).
- **Structures** : champ agrégateur/ponctuel + annuaire structures + dashboard publication.

## Découpage (programme matching + freemium + structures)

- **Lot A** : profil matching (legalStatus, discipline only) + onboarding reformulé Phase 1/2 +
  relâchement du gating (onboarding requis pour le matching, pas partout).
- **Lot B** : `MatchingService` (scoring) + « mes matchs » (nombre + liste).
- **Lot C** : UI swipe (home section 1) + favori + alerte avec consentement.
- **Lot D** : paywall freemium (contrôle abonnement + gating catalogue/alertes/annuaires/messagerie
  + compteur 3/semaine + écrans tarifs).
- **Lot E** : annuaire structures + champ agrégateur/ponctuel + dashboard structure (publication) +
  lien avec la découverte de sources.

## À surveiller

- Programme conséquent (5 lots) : à séquencer, pas tout d'un coup.
- Cohérence du paywall (ne pas bloquer l'admin ; comportement clair pour les non-abonnés).
- RGPD / clarté tarifaire (les tarifs doivent être à jour sur /tarifs ; CGV).

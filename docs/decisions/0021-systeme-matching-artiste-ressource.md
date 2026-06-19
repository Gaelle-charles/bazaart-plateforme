# ADR-0021 — Système de matching artiste ↔ ressource (remplace l'onboarding obligatoire)

- **Date** : 2026-06-19
- **Statut** : arbitré (quelques précisions de modélisation ouvertes)
- **Décidé par** : Gaëlle

## Contexte

Gaëlle veut un système de **matching** en première section de la page d'accueil : un artiste est
mis en correspondance avec des ressources (opportunités) selon son profil. Ce système **remplace
l'onboarding obligatoire** (ADR-0015) tout en réutilisant une partie de ses questions.

## Décisions (réponses de Gaëlle)

1. **UI** : l'utilisateur voit le **nombre de matchs** + les parcourt en **swipe (style Tinder)**.
2. **Accès** : réservé aux utilisateurs **connectés**. Si non connecté → bascule vers le **nouvel
   onboarding** qui recense les infos nécessaires au matching.
3. **Onboarding reformulé en 2 phases** :
   - **Phase 1** : identification (compte) + **validation d'email** (on conserve l'ADR-0015 Lot 1).
   - **Phase 2** : profil artistique : **type, secteur, discipline, localisation, ce qu'il
     recherche, statut juridique** (artiste-auteur / autoentrepreneur / autre...).
4. Le matching **peut créer une alerte**, mais **avec l'accord explicite** de l'utilisateur.
5. **Artistes uniquement**.

## Conséquences

- **Email verification (Lot 1)** : conservée (= Phase 1).
- **Onboarding (Lot 2)** : reformulé en Phase 2 (collecte du profil de matching). Le caractère
  bloquant est à reconsidérer (cf. points ouverts).
- **Nouveaux champs profil artiste** : `legalStatus` (statut juridique), et selon clarification
  `secteur` / `type` (cf. points ouverts) — en plus de disciplines/localisation/lookingFor déjà
  présents.
- **MatchingService** : score une ressource vs le profil (disciplines communes + ce que cherche
  l'artiste vs type de ressource + territoire + niveau, deadlines passées exclues).
- **UI swipe** (section 1 de la home) : cartes de ressources matchées + compteur ; swipe.
- **Alerte avec consentement** : bouton/case « me notifier des futures ressources qui matchent ».
- **Lot 3 structure (onboarding structure)** : hors de ce périmètre (matching = artistes only).

## Points résolus (2026-06-19)

1. **Pas de « type » ni « secteur »** : on garde uniquement **discipline** (M2M existante).
2. **`legalStatus`** : liste proposée (Artiste-auteur / Autoentrepreneur / Association / Société /
   En cours de structuration / Autre), ajustable plus tard.
3. **Swipe droite = ajouter en favori** ; gauche = passer.
4. Le point d'accès « 3 consultations gratuites sinon tarifs » relève du **modèle freemium**
   (cf. ADR-0022) : le matching est l'entrée gratuite ; le reste est payant.

## Découpage proposé

- **Lot A** : modèle (legalStatus + champs profil) + onboarding reformulé Phase 1/Phase 2.
- **Lot B** : `MatchingService` (scoring) + endpoint « mes matchs » (nombre + liste classée).
- **Lot C** : UI swipe (section 1 home) + action « intéressé »/« passer » + alerte avec consentement.

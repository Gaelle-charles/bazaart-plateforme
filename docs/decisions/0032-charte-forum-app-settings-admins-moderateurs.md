# ADR-0032 — Charte du forum stockée dans app_settings, admins = modérateurs

- **Date** : 2026-07-02
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte
Les admins doivent pouvoir gérer et modérer le forum : épingler des sujets, mettre à jour
la charte, gérer les utilisateurs. Un état des lieux a montré que l'épinglage
(`ForumVoter::FORUM_PIN`), le verrouillage, la suppression et la gestion des utilisateurs
(`/admin/users`) existaient déjà. Deux points restaient ouverts :

1. La **charte du forum** n'existait nulle part (ni affichage, ni édition). Le CDC V3
   ne la prévoit pas explicitement — petit ajout hors périmètre strict, validé.
2. Fallait-il un bouton admin pour **nommer des modérateurs** (`ROLE_MODERATOR`) ?

## Options envisagées
1. **Option A — charte dans `app_settings`** : réutilise l'entité `AppSetting` existante
   (clé `forum_charter`), édition via `/admin/settings`, affichage sur une page
   `/forum/charte`. Avantages : zéro migration, ~2-3 h, cohérent avec les autres réglages.
   Inconvénient : pas d'historique des versions.
2. **Option B — entité dédiée `ForumCharter`** avec historique des versions.
   Avantage : traçabilité. Inconvénient : overkill pour la V1, migration et CRUD en plus.

Pour les modérateurs : ajouter un toggle « nommer modérateur » sur `/admin/users`,
ou s'appuyer sur la hiérarchie de rôles existante.

## Décision
- **Option A retenue** : la charte vit dans `app_settings` (clé `forum_charter`),
  éditable par les admins, affichée en texte brut échappé (jamais de `|raw`).
- **Pas de toggle modérateur** : les admins sont directement modérateurs.
  C'est déjà effectif via `security.yaml` (`ROLE_ADMIN` hérite de `ROLE_MODERATOR`).
  Aucun changement de rôles nécessaire.

## Conséquences
- Aucune migration. Pas de nouvelle entité.
- La route `/forum/charte` doit être exclue du pattern attrape-tout
  `GET /forum/{categorySlug}` (même principe que l'exclusion de `nouveau`).
- Si un jour Bazaart nomme des modérateurs non-admins, il suffira d'ajouter un toggle
  `ROLE_MODERATOR` sur `/admin/users` — le `ForumVoter` le reconnaît déjà.
- L'historique des versions de la charte (Option B) pourra être reconsidéré en V2
  si le besoin de traçabilité apparaît.

# ADR-0009 — Publication d'articles réservée aux admins (lecture ouverte à tous)

- **Date** : 2026-06-13
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le module Articles / Blog permettait à **tout utilisateur connecté** (`ROLE_USER`) de créer,
éditer et supprimer des articles : la route `/articles/new` n'était protégée que par
`ROLE_USER`, et `ArticleService::deleteArticle()` autorisait « auteur ou admin ».

Pour le lancement V1, l'éditorial du blog doit rester **maîtrisé par l'équipe Bazaart** :
seuls les admins publient. Les utilisateurs restent **lecteurs** du blog. Ouvrir la
publication à tous en V1 poserait des problèmes de modération, de qualité éditoriale et de
cohérence de la ligne du média.

## Options envisagées

1. **Publication ouverte à tous les `ROLE_USER`** (état initial) — Avantage : contribution
   communautaire possible. Inconvénients : aucune modération a priori, risque de contenu
   hors-ligne éditoriale, charge de modération à un moment où l'équipe est mobilisée sur le
   lancement.

2. **Publication réservée à `ROLE_ADMIN`, lecture conservée pour tous** — Avantage : ligne
   éditoriale maîtrisée, zéro charge de modération non désirée, cohérent avec un « blog
   Bazaart » officiel en V1. Inconvénient : pas de contribution utilisateur (acceptable,
   reportable en V2 avec un workflow de validation si besoin).

## Décision

**Option 2.** La création, l'édition et la suppression d'articles (`/articles/new`,
`/articles/{id}/edit`, `/articles/{id}/delete`, `/articles/my`) sont **réservées à
`ROLE_ADMIN`**. La **lecture** (`/articles`, `/articles/{slug}`) reste ouverte à tous les
utilisateurs connectés. L'onglet « Articles & Blog » est conservé dans le menu (pour lire).

## Conséquences

- **Code** (commit `1dce6a9`) : `#[IsGranted('ROLE_ADMIN')]` sur les méthodes de publication
  d'`ArticleController` ; `ArticleService::deleteArticle()` durci via `AuthorizationCheckerInterface`
  (suppression de la clause « auteur » qui contournait la hiérarchie des rôles) ; boutons de
  publication masqués pour les non-admins. Validé E2E en prod (artiste → 403 sur new/edit/delete/my ;
  lecture du blog → 200).
- **Écart au CDC V3** : la publication d'articles, implicitement ouverte, devient admin-only.
  À refléter dans le cahier des charges.
- **À surveiller** : si une contribution communautaire au blog est souhaitée en V2, prévoir un
  workflow de soumission + validation (statut brouillon → relecture admin → publication), plutôt
  que de rouvrir la publication directe.

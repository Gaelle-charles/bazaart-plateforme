# ADR-0025 — Déduplication scraping : similarité de titre (fuzzy) via PostgreSQL pg_trgm

- **Date** : 2026-06-21
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

La clé de contenu actuelle exige une **égalité EXACTE** du titre normalisé
(`TitleNormalizerService::normalize`, comparé en PHP dans
`ScrapedResourceRepository::findByContentKey`). Des variantes mineures du même
intitulé échappent donc à la déduplication :

- mots en plus / en moins (« Bourse de création 2026 » vs « Bourse création 2026 »),
- reformulations, ordre des mots, préfixes de source (« [AAC] … »),
- coquilles.

Résultat : des doublons « presque identiques » passent entre les mailles. On veut
un **deuxième filet** par **similarité** (fuzzy matching), en complément (pas en
remplacement) de la dédup exacte et de la deadline canonique
([[0024-dedup-scraping-deadline-canonique]]).

Cette amélioration vise la **V2**.

## Options envisagées

1. **Levenshtein en PHP.**
   - Avantage : aucune dépendance BDD, simple à coder.
   - Inconvénient : comparaison O(n) en mémoire à chaque insertion (on doit charger
     les candidats et calculer la distance un par un), pas d'index → ne passe pas
     à l'échelle quand le catalogue grossit.
2. **PostgreSQL `pg_trgm` (recommandée).**
   - Extension native PostgreSQL : fonction `similarity(a, b)` + opérateur `%`,
     **indexable** via un index GIN/GiST sur trigrammes → recherche de quasi-doublons
     rapide même sur gros volume.
   - Inconvénient : nécessite `CREATE EXTENSION pg_trgm` (droits adaptés au
     déploiement) et, pour être efficace, un **titre normalisé persisté et indexé**.
3. **Embeddings / LLM (similarité sémantique).**
   - Avantage : capte les reformulations profondes (sens proche, mots différents).
   - Inconvénient : coût (tokens / stockage de vecteurs), complexité (pgvector),
     surdimensionné pour le besoin actuel. Repoussé (V3 éventuel).

## Décision

**Option 2 (`pg_trgm`) retenue pour la V2**, en **deuxième filet** appliqué APRÈS
la dédup exacte :

1. on conserve la dédup exacte (URL + clé de contenu titre normalisé + deadline
   canonique de l'ADR-0024) ;
2. en plus, à l'insertion, on recherche les candidats partageant la **même
   deadline canonique** dont la **similarité de titre** dépasse un **seuil
   paramétrable** (départ ≈ **0.6**, ajustable via `/admin/settings` ou une
   constante) ;
3. un candidat au-dessus du seuil est traité comme **doublon potentiel** : selon
   l'évolution de l'interface, soit ignoré à l'insertion (comme `contentDedup`
   aujourd'hui), soit **signalé pour fusion manuelle** par l'admin (piste « écran
   de fusion » A6, à décider séparément).

La combinaison **similarité de titre + même deadline** réduit fortement le risque
de faux positifs (deux opportunités distinctes ont rarement titre proche ET même
date limite).

## Conséquences

- **Migration** : activer l'extension (`CREATE EXTENSION IF NOT EXISTS pg_trgm`)
  et créer un **index GIN** sur une colonne de **titre normalisé persisté**.
  → implique de **stocker le titre normalisé** en colonne (aujourd'hui calculé en
  PHP à la volée). Cette colonne rejoint l'idée d'une **clé de contenu persistée**
  (recoupe la piste A4 et l'Option 3 de [[0024-dedup-scraping-deadline-canonique]]).
- **⚠️ Droits PostgreSQL** : `CREATE EXTENSION` peut exiger un rôle **superuser**.
  Sur notre Postgres self-hosted (Docker, droplet DigitalOcean), à **valider à
  l'infra** : soit la migration tourne avec un rôle habilité, soit l'extension est
  créée manuellement une fois par un superuser avant la migration applicative.
- **Paramétrage** : exposer le **seuil de similarité** (réglage fin en prod sans
  redéploiement, via `app_settings` / `/admin/settings`).
- **Tests** : non-régression de la dédup exacte + cas de quasi-doublons détectés /
  non détectés autour du seuil.
- **Risque** : faux positifs (fusion de deux opportunités proches mais distinctes).
  Mitigé par le couplage avec la deadline canonique et, idéalement, par une
  **validation humaine** (fusion admin) plutôt qu'un rejet silencieux.
- **Lien** : s'appuie sur [[0024-dedup-scraping-deadline-canonique]] et complète
  `docs/scraping.md` §5.

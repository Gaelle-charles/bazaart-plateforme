# ADR-0024 — Déduplication scraping : deadline canonique (date) dans la clé de contenu

- **Date** : 2026-06-21
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

La déduplication des opportunités scrapées repose aujourd'hui sur deux niveaux :
1. **URL exacte** normalisée (`ScrapedResourceRepository::findByUrl` + `normalizeUrl`),
2. **Clé de contenu** = *titre normalisé* (`TitleNormalizerService::normalize` :
   minuscules, sans accents, sans ponctuation) **+ deadline** (`findByContentKey`).

Problème : la `deadline` de `scraped_resources` est un **texte libre** (choix
délibéré, cf. `docs/scraping.md` §11). Deux sources qui expriment la **même**
date sous des formats différents (« 31/05/2026 » vs « 31 mai 2026 » vs
« 2026-05-31 ») produisent des clés de contenu **différentes** → le même appel
à candidature est inséré en double (doublon cross-sources non détecté).

Or le code sait déjà parser des dates : `archiveExpired()` reconnaît 3 formats
(ISO `2026-05-31`, `31/05/2026`, `31 mai 2026`). Cette logique n'est simplement
pas réutilisée par la clé de contenu.

Cette amélioration vise la **V2** (post-lancement V1 du 15 juin 2026), elle n'est
pas urgente mais attaque la cause principale des doublons restants.

## Options envisagées

1. **Statu quo — deadline texte dans la clé.**
   - Avantage : aucun développement.
   - Inconvénient : laisse passer les doublons cross-sources sur dates équivalentes.
2. **Canonicalisation à la volée (recommandée).**
   - Extraire le parseur de dates de `archiveExpired()` dans un service partagé
     (`DeadlineParser`) qui retourne une `\DateTimeImmutable|null`.
   - La clé de contenu utilise cette **date canonique** (format `Y-m-d`) au lieu
     du texte brut. Si la date n'est **pas** parsable → on **ne force pas** la
     dédup (on retombe sur le comportement actuel / pas de rapprochement), pour
     éviter les faux positifs.
   - Avantage : élimine les doublons sur dates équivalentes ; pas de migration ;
     mutualise un parseur aujourd'hui dupliqué.
   - Inconvénient : reste limité aux formats reconnus (mais on peut les enrichir).
3. **Colonne `deadline_date` persistée (DATE, nullable).**
   - Peuplée à l'insertion + backfill des lignes existantes ; clé de contenu et
     `archiveExpired()` s'appuient dessus.
   - Avantage : performant, requêtable en SQL, base pour l'indexation.
   - Inconvénient : migration + backfill ; à réserver si le volume l'exige.

## Décision

**Option 2 retenue pour la V2** : canonicalisation de la deadline à la volée via
un service `DeadlineParser` mutualisé (réutilisé par `findByContentKey` ET
`archiveExpired()`), avec **repli prudent** : deadline non parsable → pas de
rapprochement forcé (on préfère un doublon résiduel à une fusion erronée).

L'**Option 3** (colonne persistée) est gardée en réserve : elle deviendra
pertinente si on persiste aussi le titre normalisé pour l'indexation trigram
(cf. [[0025-dedup-scraping-similarite-titre-pgtrgm]]) — les deux colonnes
formeraient alors une clé de contenu persistée et indexée.

## Conséquences

- **Code** : nouveau service `DeadlineParser` ; `ScrapedResourceRepository::findByContentKey`
  et `ScrapedResourcePersister` utilisent la date canonique ; `archiveExpired()`
  bascule sur le service mutualisé (refactor sans changement de comportement).
- **Tests** : couvrir les 3 formats + les cas non parsables (repli).
- **Pas de migration** en Option 2 pure.
- **Risque** : élargir la dédup au point de fusionner deux opportunités distinctes
  partageant titre + date. Mitigé par : (a) le titre normalisé reste dans la clé,
  (b) le repli sur deadline non parsable, (c) la combinaison avec la similarité de
  titre décidée en [[0025-dedup-scraping-similarite-titre-pgtrgm]].
- **À surveiller** : enrichir progressivement les formats de date reconnus
  (« avant fin juin », « courant juillet ») comme déjà prévu en roadmap V2.
- **Lien** : complète le pipeline de scraping décrit dans `docs/scraping.md` §5.

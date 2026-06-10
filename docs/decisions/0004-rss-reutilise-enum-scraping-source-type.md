# ADR-0004 — Lecture RSS native : réutiliser l'enum `ScrapingSourceType` plutôt qu'un champ `source_type`

- **Date** : 2026-06-10
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le chantier « pipeline de collecte multi-méthodes » (juin 2026) introduit une lecture
native des flux RSS/Atom (sans LLM), le scraping LLM existant devenant le fallback.

La demande initiale prévoyait l'ajout d'un champ `source_type` (`'rss' | 'scrape'`) sur
l'entité `ScrapingSource`. Or l'entité possède **déjà** un champ `type` typé par l'enum
`App\Enum\ScrapingSourceType` (`RSS` / `HtmlLlm` / `HtmlCss`), introduit lors de la refonte
du scraping (mai 2026, cf. table `scraping_sources`). Ce champ encode déjà la distinction
« flux RSS » vs « scraping HTML ».

Il fallait trancher avant d'écrire la moindre migration : créer un nouveau discriminant ou
réutiliser l'existant.

## Options envisagées

1. **Réutiliser l'enum `type` existant** — `RSS` → lecture native (FeedReaderService),
   `HtmlLlm` / `HtmlCss` → pipeline scraping. Avantages : un seul concept, aucune
   redondance, pas de migration de données, pas de risque d'états incohérents.
   Inconvénients : on s'écarte du libellé `source_type` demandé.

2. **Ajouter un champ `source_type` parallèle** — Deux champs coexistent.
   Avantages : conforme à la lettre de la demande initiale.
   Inconvénients : deux notions qui peuvent diverger (`type=RSS` mais `source_type=scrape` ?),
   source de bugs et de confusion, migration et synchronisation à maintenir.

## Décision

**Option 1 retenue** : aucun champ `source_type` n'est créé. L'enum `ScrapingSourceType`
(champ `type`) reste le discriminant unique. Le routage du pipeline se fait sur
`type === ScrapingSourceType::RSS`.

Justification : éviter deux concepts parallèles qui décriraient la même chose, supprimer tout
risque d'incohérence, et ne pas alourdir le schéma sans valeur ajoutée.

## Conséquences

- **Entité** : seuls les champs réellement nouveaux sont ajoutés en WS1 (`feed_url`,
  `last_successful_fetch`, `consecutive_failures`, `auto_publish`). Pas de `source_type`.
- **Routage** : `app:read-feeds` ne traite que les sources `type=RSS` ; `app:scrape-opportunities`
  exclut désormais les sources `type=RSS` (évite le double-traitement).
- **À surveiller** : toute future source « flux » doit être créée avec `type=RSS` (et non un
  nouveau libellé) pour être prise en charge par le pipeline natif.
- **CDC V3 / CLAUDE.md** : aucune mise à jour nécessaire (décision tracée ici).

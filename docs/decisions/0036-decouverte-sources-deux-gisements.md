# ADR-0036 — Découverte de sources : deux gisements, support RSS, rotation

- **Date** : 2026-09-07
- **Statut** : proposé
- **Décidé par** : Gaëlle
- **Révise** : ADR-0034 (auto-validation RSS des sources découvertes), ADR-0003 (sources suggérées — périmètre V2)

## Contexte

Diagnostic établi le 7 septembre 2026 : `app:discover-sources` ne proposait plus
aucune nouvelle source depuis un moment. Trois causes cumulées :

1. **Sources agrégateurs mortes ou mal supportées.** Sur les 3 `ScrapingSource`
   marquées `estAgregateur = true`, 2 répondaient en HTTP 404
   (`on-the-move.org/calls`, `eacea.ec.europa.eu/grants_en` — les scrapers dédiés
   `OnTheMoveScraper`/`CultureMovesEuropeScraper` utilisent depuis longtemps
   d'autres URL, mais `scraping_sources.url` n'avait jamais été mise à jour). La
   troisième (`resartis.org/feed/`, HTTP 200) est un **flux RSS XML** :
   `LinkExtractorService::extractLinks()` cherchait des `<a href>` via DomCrawler
   sur le flux brut et n'en trouvait donc jamais.
2. **Même avec des pages vivantes, les pages-listes d'agrégateurs ne contiennent
   souvent que des liens internes** (`filterInternalLinks()` les élimine à raison)
   — les liens vers les organismes tiers sont dans les pages de détail ou les
   descriptions RSS, pas dans la page-liste elle-même.
3. **Pas de rotation** : `array_slice()` prenait toujours les 50 premiers
   candidats dans l'ordre du DOM — un run suivant soumettait exactement les
   mêmes candidats en tête de liste au LLM.
4. **Un gisement entier inexploité** : les `ScrapedResource` déjà collectées par
   `app:scrape-opportunities` portent souvent, dans `url` ou `applicationUrl`, le
   site de l'organisme émetteur de l'opportunité — un signal jamais utilisé par
   la découverte de sources.

## Options envisagées

1. **Corriger uniquement les 2 URL mortes, sans toucher au reste.**
   ➕ Minimal. ➖ Ne résout ni le RSS de Resartis, ni l'absence de rotation, ni le
   gisement "opportunités" — le volume de découverte resterait très faible
   (agrégateurs peu nombreux, souvent pauvres en liens externes exploitables).
2. **Ajouter un support RSS + un second gisement "opportunités déjà collectées"
   + une rotation, en plus de la correction des URL (retenue).**
   ➕ Traite toutes les causes identifiées du diagnostic. ➕ Le gisement
   "opportunités" ne coûte aucun fetch réseau supplémentaire (les données sont
   déjà en base). ➕ Réutilise le pipeline de filtrage existant
   (`LinkExtractorService`) plutôt que d'en écrire un second.
   ➖ Plus de code à maintenir ; complexité de `DiscoverSourcesCommand` en légère
   hausse (mitigée par l'extraction en méthodes privées dédiées par gisement).
3. **Remplacer entièrement les agrégateurs HTML par un scraping ciblé des pages
   de détail des agrégateurs (suivre chaque lien interne trouvé sur `/calls`).**
   ➖ Beaucoup plus de requêtes HTTP (un fetch par page de détail), latence et
   risque de blocage anti-bot nettement plus élevés pour un gain incertain.
   Rejetée pour le rapport coût/bénéfice, pourrait être reconsidérée en V2 si le
   volume de découverte reste insuffisant après cette ADR.

## Décision

**Option 2.** Quatre changements complémentaires, tous dans le périmètre de
`app:discover-sources` / `LinkExtractorService` :

- **A. Support RSS/Atom dans `LinkExtractorService`.** Détection heuristique du
  contenu (`looksLikeFeed()`), extraction des liens externes présents dans le
  texte des `<description>`/`<content:encoded>` (RSS) ou `<summary>`/`<content>`
  (Atom) via un parsing HTML tolérant (`html_entity_decode` + DomCrawler sur le
  fragment), le tout protégé par `libxml_use_internal_errors(true)` — un flux
  malformé ne remonte jamais d'exception.
- **B. Second gisement "opportunités déjà collectées".** Nouvelle méthode
  `ScrapedResourceRepository::findRecentForSourceDiscovery()` (12 derniers mois,
  tous statuts). `DiscoverSourcesCommand::discoverFromOpportunities()` construit
  des candidats `{titre, applicationUrl ?? url}`, les passe dans le même
  pipeline de filtrage (`LinkExtractorService::filterCandidates()`, extrait de
  `extractAndFilter()` pour être réutilisable), puis les soumet au LLM par lots
  de 50. `SuggestedSource::origine` devient `OPPORTUNITE` (nouvelle valeur, en
  plus de `AGREGATEUR`), `sourceOrigine` trace l'URL de la `ScrapedResource`
  d'origine (résolution par correspondance d'URL puis de domaine).
- **C. Rotation des candidats.** `shuffle()` de la liste filtrée juste avant le
  plafond de 50 — expose un sous-ensemble potentiellement différent au LLM à
  chaque run, sans coût ni changement de comportement quand le volume est déjà
  sous le plafond.
- **D. Correction des URL mortes.** Migration Doctrine `Version20260907230632`
  (`UPDATE` idempotent) + mise à jour de `SeedScrapingSourcesCommand` pour les
  nouvelles installations : `on-the-move.org/calls` →
  `on-the-move.org/news/deadlines`, `eacea.ec.europa.eu/grants_en` →
  `culture.ec.europa.eu/fr/funding` (mêmes URL déjà utilisées par les scrapers
  dédiés `OnTheMoveScraper`/`CultureMovesEuropeScraper`, donc connues fiables).

Un correctif complémentaire de **visibilité** (point E) : les échecs par
agrégateur (HTTP ≠ 200, contenu vide, 0 candidat après filtrage) passent en
`logger->warning` (visibles en supervision), un `logger->info` résume chaque
run, et la commande retourne `Command::FAILURE` uniquement si **tous** les
agrégateurs analysés ont échoué **et** que le gisement opportunités n'a produit
aucun candidat filtré — pour que le cron détecte un run totalement infructueux
sans confondre "rien trouvé" (normal) et "tout a échoué techniquement" (anormal).

Nouvelle option `--pool=aggregators|opportunities|all` (défaut `all`) pour
cibler un seul gisement en débogage.

## Conséquences

- **Ne révise pas le principe d'ADR-0034** : l'auto-validation RSS reste
  déclenchée de la même façon pour toute `SuggestedSource`, quel que soit son
  `origine` (AGREGATEUR ou OPPORTUNITE) — `SuggestedSourceAutoValidationService`
  ne distingue pas la provenance, seul `FeedDetectorService` fait foi.
- **Vérification empirique importante** : le flux `resartis.org/feed/` a été
  inspecté le 7 septembre 2026 (`curl -sk https://resartis.org/feed/`) — ses
  descriptions sont du texte brut tronqué, **sans aucun lien HTML embarqué**.
  Le support RSS (point A) ne rapporte donc **pas** de nouveaux candidats pour
  Resartis aujourd'hui. Ce n'est pas un échec de l'implémentation : le support
  RSS est une capacité générique qui bénéficiera à d'autres flux (WordPress
  notamment) qui embarquent bien du HTML dans leurs extraits/`content:encoded`.
  Pas de nouvelle source HTML séparée créée pour Resartis dans ce lot — à
  reconsidérer si le gisement "opportunités" ne suffit pas à compenser.
- **Volume attendu** : le gisement "opportunités" ne dépend pas de la richesse
  en liens des pages agrégateurs — il croît naturellement avec le volume de
  `ScrapedResource` collectées par `app:scrape-opportunities`, ce qui le rend
  plus robuste dans la durée que le gisement agrégateurs (sensible aux refontes
  de site, blocages anti-bot, etc.).
- **À surveiller** : le plafond `discovery_max_suggestions` reste **partagé**
  entre les deux gisements (agrégateurs traités en premier) — si le gisement
  agrégateurs sature systématiquement le plafond, le gisement opportunités
  n'aura jamais l'occasion de contribuer. Pas de changement de comportement
  demandé pour l'instant ; envisager un plafond par gisement si ça devient un
  problème observé en production.
- Cohérent avec la préférence durable de Gaëlle : découverte automatique sans
  intervention manuelle récurrente (cf. ADR-0034).

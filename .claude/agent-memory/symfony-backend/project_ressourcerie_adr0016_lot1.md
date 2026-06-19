---
name: project-ressourcerie-adr0016-lot1
description: ADR-0016 Lot 1+2 livré + correctif Lot 2 : import CSV enrichi avec city/country/experienceLevel/disciplines contraintes
metadata:
  type: project
---

ADR-0016 Lot 1 livré : enrichissement du modèle de données Ressourcerie avec localisation fine et niveau d'expérience.
ADR-0016 Lot 2 livré : commande `app:import-grant-csv` pour importer le CSV "Mise à jour fichier grant" de Gaëlle.
ADR-0016 Lot 2 correctif (2026-06-18) : `--enrich` complète maintenant city, country (si vide), experienceLevel et disciplines contraintes BDD.

**Why:** Les artistes ne pouvaient pas filtrer les opportunités par ville/pays ni par niveau d'expérience requis. Le LLM scraper extrayait les disciplines sous forme de texte libre non mappé vers les entités BDD.
Le CSV de grants permet à Gaëlle d'importer des opportunités curatées manuellement sans passer par le scraper automatique.

**How to apply:** Les opportunités CSV arrivent en `status=pending` dans scraped_resources, validables depuis `/admin/scraped-opportunities`.

## Fichiers Lot 1 créés/modifiés

- `src/Enum/ExperienceLevel.php` — NOUVEAU : enum backed string (DEBUTANT='beginner', INTERMEDIAIRE='intermediate', EXPERIMENTE='experienced'), méthode `label()` FR. NULL = tous niveaux.
- `src/Entity/Resource.php` — Ajout : `$city` (string 150 nullable), `$country` (string 100 nullable), `$experienceLevel` (?ExperienceLevel nullable) + getters/setters.
- `src/Entity/ScrapedResource.php` — Mêmes 3 champs (porteurs de l'extraction LLM avant publication).
- `src/DTO/ScrapedOpportunity.php` — Ajout : `$city`, `$country`, `$experienceLevel` (strings), `$disciplinesLabels` (array string). Champ `$disciplines` (CSV) conservé pour rétrocompatibilité.
- `src/Service/DisciplineMapperService.php` — NOUVEAU : convertit libellés texte LLM → entités Discipline BDD. Matching par ordre : exacte → synonyme (table SYNONYMS) → sous-chaîne. Ne crée JAMAIS de nouvelle discipline.
- `src/Service/LlmExtractorService.php` — Injection `DisciplineRepository`. Prompts Mistral ET Anthropic enrichis avec city, country, experienceLevel, disciplines (tableau contraint à la liste BDD). Parsing des nouveaux champs dans `mapItemsToOpportunities()`. Nouvelle méthode `buildDisciplinesListForPrompt()`.
- `src/Service/ScrapedResourcePersister.php` — Import `ExperienceLevel`. Nouvelle méthode privée `updateEnrichedFields()` appelée aux 3 cas de persistance (insert, réactivation, update).
- `src/Controller/AdminController.php` — Injection `DisciplineMapperService`. Dans `verifyScrapedOpportunity()` : propagation city, country, experienceLevel + mapping disciplines CSV → ManyToMany Resource.
- `migrations/Version20260618222051.php` — 6 ALTER TABLE ADD COLUMN nullable sur resources et scraped_resources.

## Fichiers Lot 2 créés

- `src/Service/GrantCsvImporter.php` — NOUVEAU : logique métier d'import CSV. Extrait URL depuis formats variés (URL complète, domaine dans parenthèses, format pipe, domaine nu). Mappe CATEGORY → ResourceType. Parse deadline MM/DD → YYYY-MM-DD (année courante ou suivante si passée). Convertit codes pays ISO 2 lettres.
- `src/Service/ImportResult.php` — NOUVEAU : DTO résultat de parseFile() (opportunities + ignoredLines).
- `src/Command/ImportGrantCsvCommand.php` — NOUVEAU : commande `app:import-grant-csv`. Options : --dry-run, --limit=N, --enrich. Utilise ScrapedResourcePersister (déduplication) + OpportunityEnrichmentService (--enrich).

## Disciplines existantes en BDD (V1)
- Musique (id 65)
- Cinéma & Audiovisuel (id 66)
- Arts visuels (id 67)
- Danse (id 68)
- Théâtre & Performance (id 69)
- Littérature (id 70)
- Arts numériques (id 71)
- Mode & Design (id 72)

## ResourceTypes existants en BDD (V1)
- Appel à projets (id 41)
- Résidence artistique (id 42)
- Bourse & Financement (id 43)
- Formation (id 44)
- Prix & Concours (id 45)

## Convention CSV import grants
- Colonnes attendues : OPEN CALLS, DEADLINE, CATEGORY, $, WHERE, NOTES, LINK
- DEADLINE format : MM/DD (ex: "02/28") → parsé en YYYY-MM-DD, année courante ou suivante si passée
- LINK formats supportés : URL complète, "Texte (domaine.com)", "Texte | ... (domaine.com)", domaine nu
- Fichier CSV hors repo → Gaëlle doit le copier dans app/var/ avant de lancer la commande Docker
  (bind-mount Docker ne monte que ./app/)

## Usage commande import CSV
```bash
# Copier le CSV dans le bind-mount Docker d'abord
cp "/Users/belamour/Downloads/Mise à jour fichier grant  - Feuille 2.csv" ./app/var/grant.csv

# Dry-run complet (voir rapport des ignorées)
docker compose exec app php bin/console app:import-grant-csv /var/www/html/var/grant.csv --dry-run

# Import + enrichissement LLM
docker compose exec app php bin/console app:import-grant-csv /var/www/html/var/grant.csv --enrich
```

## Convention prompt LLM enrichi
- `disciplines` : tableau (array) contraint à la liste BDD — ex : `["Musique", "Danse"]`
- `city` : string — ville où se déroule l'opportunité
- `country` : string — remplace l'ancien champ `pays` (rétrocompat maintenue dans le parsing)
- `experienceLevel` : "beginner" | "intermediate" | "experienced" | "" (vide = tous niveaux)

## Correctif Lot 2 (2026-06-18) — `--enrich` enrichissement complet

### Problème corrigé
`OpportunityEnrichmentService::enrich()` ne renvoyait QUE title/description/disciplines texte libre.
Résultat : city/country/experienceLevel vides, disciplines non contraintes.

### Fichiers modifiés (correctif)
- `src/DTO/OpportunityEnrichment.php` — Ajout de 4 champs : `city`, `country`, `experienceLevel`, `disciplinesLabels` (nullable). `isEmpty()` étendu à ces nouveaux champs.
- `src/Service/OpportunityEnrichmentService.php` — Injection `DisciplineRepository`. Prompt étendu à 6 champs (titre, description, city, country, experienceLevel, disciplines en tableau contraint). `validateAndBuildDto()` parse les nouveaux champs. Nouvelle méthode `buildDisciplinesListForPrompt()` (même pattern que LlmExtractorService).
- `src/Command/ImportGrantCsvCommand.php` — Bloc enrichissement étendu : applique city, country (si vide CSV), experienceLevel (converti en ExperienceLevel enum), disciplines (labels → string CSV). Suppression de l'injection DisciplineMapperService (non nécessaire : ScrapedResource::disciplines est une string).

### Règles d'enrichissement CSV (priorité données CSV)
- `title` : TOUJOURS le titre CSV → jamais écrasé
- `deadline` : parsé CSV → jamais écrasé
- `type` : mappé CATEGORY → jamais écrasé
- `country` : WHERE CSV → écrasé par LLM UNIQUEMENT si vide
- `city` : absent du CSV → LLM complète toujours
- `experienceLevel` : absent du CSV → LLM complète toujours
- `disciplines` : absent du CSV → labels contraints BDD

## Points à retenir
- `disciplinesLabels` dans le DTO = tableau de strings bruts LLM → à passer à `DisciplineMapperService::mapLabelsToEntities()` pour obtenir les entités (lors de la propagation vers Resource).
- `ScrapedResource::$disciplines` reste une string CSV pour l'affichage dans l'interface admin (pas de rupture de l'interface existante).
- La propagation ScrapedResource → Resource se fait dans `AdminController::verifyScrapedOpportunity()`.
- `OpportunityEnrichmentService` n'injecte PAS `DisciplineMapperService` : ScrapedResource ne stocke que du texte, la conversion en entités Discipline est réservée à la propagation admin.

---
name: project-ressourcerie-adr0016-lot1
description: ADR-0016 Lot 1 livré — enrichissement modèle Ressourcerie : ExperienceLevel enum, champs city/country/experienceLevel sur Resource et ScrapedResource, LLM prompt enrichi, DisciplineMapperService
metadata:
  type: project
---

ADR-0016 Lot 1 livré : enrichissement du modèle de données Ressourcerie avec localisation fine et niveau d'expérience.

**Why:** Les artistes ne pouvaient pas filtrer les opportunités par ville/pays ni par niveau d'expérience requis. Le LLM scraper extrayait les disciplines sous forme de texte libre non mappé vers les entités BDD.

**How to apply:** Le Lot 2 (import CSV disciplines) et le Lot 3 (affichage templates) peuvent maintenant s'appuyer sur ces champs.

## Fichiers créés/modifiés

- `src/Enum/ExperienceLevel.php` — NOUVEAU : enum backed string (DEBUTANT='beginner', INTERMEDIAIRE='intermediate', EXPERIMENTE='experienced'), méthode `label()` FR. NULL = tous niveaux.
- `src/Entity/Resource.php` — Ajout : `$city` (string 150 nullable), `$country` (string 100 nullable), `$experienceLevel` (?ExperienceLevel nullable) + getters/setters.
- `src/Entity/ScrapedResource.php` — Mêmes 3 champs (porteurs de l'extraction LLM avant publication).
- `src/DTO/ScrapedOpportunity.php` — Ajout : `$city`, `$country`, `$experienceLevel` (strings), `$disciplinesLabels` (array string). Champ `$disciplines` (CSV) conservé pour rétrocompatibilité.
- `src/Service/DisciplineMapperService.php` — NOUVEAU : convertit libellés texte LLM → entités Discipline BDD. Matching par ordre : exacte → synonyme (table SYNONYMS) → sous-chaîne. Ne crée JAMAIS de nouvelle discipline.
- `src/Service/LlmExtractorService.php` — Injection `DisciplineRepository`. Prompts Mistral ET Anthropic enrichis avec city, country, experienceLevel, disciplines (tableau contraint à la liste BDD). Parsing des nouveaux champs dans `mapItemsToOpportunities()`. Nouvelle méthode `buildDisciplinesListForPrompt()`.
- `src/Service/ScrapedResourcePersister.php` — Import `ExperienceLevel`. Nouvelle méthode privée `updateEnrichedFields()` appelée aux 3 cas de persistance (insert, réactivation, update).
- `src/Controller/AdminController.php` — Injection `DisciplineMapperService`. Dans `verifyScrapedOpportunity()` : propagation city, country, experienceLevel + mapping disciplines CSV → ManyToMany Resource.
- `migrations/Version20260618222051.php` — 6 ALTER TABLE ADD COLUMN nullable sur resources et scraped_resources.

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

## Convention prompt LLM enrichi
- `disciplines` : tableau (array) contraint à la liste BDD — ex : `["Musique", "Danse"]`
- `city` : string — ville où se déroule l'opportunité
- `country` : string — remplace l'ancien champ `pays` (rétrocompat maintenue dans le parsing)
- `experienceLevel` : "beginner" | "intermediate" | "experienced" | "" (vide = tous niveaux)

## Points à retenir pour Lot 2 et Lot 3
- `disciplinesLabels` dans le DTO = tableau de strings bruts LLM → à passer à `DisciplineMapperService::mapLabelsToEntities()` pour obtenir les entités.
- `ScrapedResource::$disciplines` reste une string CSV pour l'affichage dans l'interface admin (pas de rupture de l'interface existante).
- La propagation ScrapedResource → Resource se fait dans `AdminController::verifyScrapedOpportunity()`.

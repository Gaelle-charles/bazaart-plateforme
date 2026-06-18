# ADR-0016 — Ressourcerie : discipline, localisation (ville/pays) et niveau d'expérience sur les ressources + import de sources

- **Date** : 2026-06-18
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Gaëlle veut enrichir les ressources (opportunités) de la Ressourcerie pour qu'on affiche,
sur chaque annonce :
1. la/les **discipline(s)** concernée(s),
2. la **localisation** de la structure sous forme **ville + pays**,
3. le **niveau d'expérience** ciblé (débutant / intermédiaire / expérimenté).

Elle fournit aussi une base de **91 sources** (CSV « Mise à jour fichier grant ») à intégrer
au scraping.

État existant : `Resource` a déjà `disciplines` (M2M `Discipline`) et `location` (un seul
champ texte). `ScrapedResource` a `disciplines` (string libre) mais ni localisation ni niveau.
Le scraping est 100% PHP (cf. CLAUDE.md), extraction via `LlmExtractorService` (Mistral
principal, Claude secours ; clés en `app_settings`, confirmées présentes en local).

Analyse du CSV : 91 entrées, surtout des **opportunités individuelles** (bourses/prix/
résidences, arts visuels/photo, international). Catégories non normalisées, pays quasi vide
(86/91), 80 URL exploitables, 5 « domaine en texte », 7 sans lien. Quelques pages
**agrégatrices** (Institut français, Jerome Foundation, Wooloo).

## Décisions

- **A — Localisation structurée** : ajouter `city` et `country` sur `Resource` ET
  `ScrapedResource` (extraits par le LLM), affichés « Ville, Pays ». On conserve `location`
  pour compat, mais l'affichage privilégie city/country.
- **B — Import du CSV (approche mixte)** :
  - 80 entrées à URL propre → importées comme **opportunités**, passées dans le pipeline LLM
    pour extraire description, deadline, discipline (mappée), ville, pays, niveau.
  - Pages agrégatrices → enregistrées comme `ScrapingSource` récurrentes.
  - ~12 entrées sans lien exploitable → liste rendue à Gaëlle pour complétion manuelle (pas
    d'import auto).
  - Catégories CSV → mappées vers les `ResourceType` existants (normalisation casse/synonymes).
- **C — Niveau d'expérience** : nouvel enum `ExperienceLevel` (DEBUTANT, INTERMEDIAIRE,
  EXPERIMENTE), **nullable = « tous niveaux »**, sur `Resource` et `ScrapedResource`.

## Conséquences

- **Modèle** : enum `ExperienceLevel` ; champs `city`, `country`, `experienceLevel` sur
  `Resource` et `ScrapedResource` ; migration non destructive (tous nullable).
- **Extraction LLM** (`LlmExtractorService`) : enrichir le prompt pour produire discipline
  (contrainte à la liste des `Discipline` existantes), ville, pays, niveau. Mapping
  texte → entités `Discipline` et `ResourceType`.
- **Import** : commande dédiée (nettoyage des liens, classification source/opportunité,
  mapping catégorie→ResourceType) ; exécution + test sur échantillon.
- **Affichage** : ville/pays + niveau + disciplines sur l'annonce de ressource (templates).
- **Test** : enrichissement réel possible (clé Mistral en `app_settings`).
- **À surveiller** :
  - Coût/quota LLM si on enrichit 80 opportunités d'un coup (faire par lots, prévoir le
    fallback Claude).
  - Qualité du mapping discipline/pays (vérifier sur échantillon avant import massif).
  - Conformité CDC : enrichissement de la Ressourcerie V1 (pas un nouveau module).

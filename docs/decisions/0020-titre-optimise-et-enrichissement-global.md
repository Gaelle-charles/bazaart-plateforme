# ADR-0020 — Titre optimisé (français, concis) + enrichissement appliqué à toutes les ressources

- **Date** : 2026-06-19
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Gaëlle constate que l'enrichissement (description, etc.) est appliqué « aléatoirement » :
certaines ressources ont une description riche, d'autres non. Cause : les sources à **scraper
dédié** (CNAP, On The Move, etc.) ne passent pas par le LLM et gardent un titre brut (souvent
anglais, long) sans description structurée. Seules les sources génériques + la commande
`app:enrich-opportunities` reçoivent le traitement LLM.

Gaëlle veut :
- une **description systématique** (jamais vide) sur toutes les ressources ;
- des **titres en français, revus/optimisés et pas trop longs** ;
- l'enrichissement **appliqué à toutes les ressources existantes** (pas seulement les nouvelles).

## Décision

- **Titre optimisé par le LLM** : l'extraction/enrichissement produit aussi un `title`
  reformulé : **en français, concis (cap ~90 caractères), clair, factuel** (basé sur le contenu,
  sans invention). Ce titre remplace le titre brut. (Les opportunités restent en `pending` →
  l'admin peut ajuster.)
- **Description obligatoire** : déjà imposée par le prompt (ADR-0018) ; on garantit la
  cohérence en **lançant l'enrichissement sur TOUT le stock** (scraped + published).
- **Enrichissement global** : exécuter `app:enrich-opportunities` (scraped) + `--published`
  (publiées) sur l'ensemble des ressources existantes en prod.

## Conséquences

- **Code** : prompts LLM (`LlmExtractorService` + `OpportunityEnrichmentService`) produisent un
  `title` optimisé ; les commandes/persisters mettent à jour le `title`. Troncature ~90 chars.
  Pas de migration (champ `title` existant).
- **Opération** : passe d'enrichissement sur tout le stock prod (coût LLM proportionnel au
  nombre de ressources ; faire par lots).
- **À surveiller** : ne pas dénaturer le sens (titre factuel) ; conserver la possibilité pour
  l'admin de corriger ; cohérence pour les futurs scrapes (idéalement, le cron enchaîne
  scrape puis enrich pour que les sources à scraper dédié soient aussi enrichies).

# ADR-0017 — Découverte automatique de l'URL qui liste les opportunités (hybride heuristique + LLM)

- **Date** : 2026-06-18
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

L'import du CSV (ADR-0016 Lot 2) traitait chaque entrée comme une « opportunité » individuelle.
Mais beaucoup de liens pointent vers la **racine d'un site** (ex. `institutfrancais.com`) et non
vers la page qui **liste** les appels/bourses. Résultat constaté par Gaëlle en admin : des
opportunités créées qui ne pointent pas vers une ressource exploitable.

Gaëlle veut que « l'IA trouve les bonnes URLs à scraper » : pour un site donné, identifier la
page qui **liste** les opportunités, puis laisser le scraping récupérer **chaque** opportunité.

## Options envisagées

1. **A — LLM pur** : donner la page d'accueil au LLM pour qu'il renvoie l'URL-liste.
   - ✅ Robuste tous sites. ❌ 1 appel LLM par site (coût).
2. **B — Heuristique** : tester des chemins courants (`/open-call`, `/opportunities`, `/grants`,
   `/appels`, `/offres`, `/calls`...).
   - ✅ Gratuit, rapide. ❌ Rate les sites non standard.
3. **C — Hybride (retenu)** : tester d'abord les chemins courants ; si rien de probant,
   demander au LLM.
   - ✅ Bon compromis coût/robustesse. ❌ Un peu plus de code.

## Décision

**Option C.** Une étape de découverte qui, pour chaque site (domaine issu du CSV ou saisi) :
1. teste des chemins courants d'URL-liste (heuristique, sans LLM) ;
2. si aucun résultat probant, interroge le LLM (réutiliser `LlmExtractorService`) sur la page
   d'accueil pour trouver l'URL-liste ;
3. enregistre l'URL-liste trouvée comme **source `html_llm` (agrégateur)** dans `scraping_sources` ;
4. le pipeline de scraping existant récupère ensuite chaque opportunité (avec l'enrichissement
   ADR-0016 Lot 1 : discipline/ville/pays/niveau).

**Conséquences sur le CSV** : on **réoriente** le traitement du CSV. Au lieu d'importer les
racines comme opportunités, on les traite comme **sites à découvrir**. L'import « brut » des 79
restantes est **mis en pause**. Les opportunités déjà importées avec une URL-racine seront
**nettoyées**.

## Conséquences

- **Code** : nouvelle commande/service de découverte d'URL-liste (heuristique + fallback LLM),
  création de sources agrégateur. Réutilise `LlmExtractorService`.
- **Complémentarité** : dédup renforcée (titre+deadline, demande Gaëlle) et masquage des
  deadlines passées sur `/resources` (autres évolutions en cours) gardent le catalogue propre.
- **Coût LLM** : appel uniquement quand l'heuristique échoue.
- **À surveiller** : qualité de l'heuristique (paths FR + EN), faux positifs (page trouvée mais
  sans opportunités), robustesse réseau/anti-bot (try/catch, on continue), traçabilité des sites
  où la découverte échoue (rapport).

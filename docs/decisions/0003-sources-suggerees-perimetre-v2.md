# ADR-0003 — Sources suggérées automatiquement : avance V2 maintenue sur `demo`

- **Date** : 2026-05-26
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Lors du développement de la refonte du module de scraping (mai 2026), une feature de
découverte automatique de sources a été développée et commitée sur `demo` :

- `app:discover-sources` : une commande qui analyse les pages HTML des agrégateurs culturels
  (sources marquées `estAgregateur = true`) et soumet leur contenu au LLM pour détecter de
  nouveaux organismes susceptibles d'avoir leurs propres opportunités (résidences, bourses, etc.).
- `SuggestedSource` entity : les organismes détectés sont stockés avec le statut `AValider`.
- `/admin/suggested-sources` : l'admin peut valider (→ crée une `ScrapingSource`) ou rejeter
  chaque suggestion.

Cette feature n'est pas dans le cahier des charges V3 (périmètre V1 = Ressourcerie, Communauté,
Formation). Elle a été développée dans la continuité logique du module scraping et est
fonctionnelle, testée et commitée. La question est de trancher son statut officiel.

## Options envisagées

1. **Reclasser en V2, laisser sur `demo`** — La feature reste dans le code, accessible en prod
   si on merge `demo` → `main`. Elle est utile pour enrichir le catalogue de la Ressourcerie
   avant le lancement. Avantages : zéro effort supplémentaire, valeur immédiate.
   Inconvénients : périmètre V1 s'élargit légèrement, risque de dette si elle n'est pas
   documentée.

2. **Mettre sur une branche dédiée** — Isoler la feature hors de `demo` pour préserver la
   clarté du scope V1. Avantages : périmètre V1 strict.
   Inconvénients : coût de re-branchement, et la feature ne présente pas de risque technique.

3. **Supprimer** — Retirer les commits. Non pertinent : la feature est propre, isolée, sans
   effet de bord sur les modules V1.

## Décision

**Option 1 retenue** : la feature Sources suggérées est maintenue sur `demo` et sera mergée
avec les modules V1 lors du déploiement. Elle est classée **avance V2** dans la roadmap.

Justification :
- Isolation absolue : `SuggestedSource` ne touche jamais `ScrapedResource` ni aucun module V1.
- La commande `app:discover-sources` est optionnelle (lancée manuellement ou en cron).
- Elle enrichit la Ressourcerie avant le lancement sans risque de régression.
- Le coût de maintenance est nul à court terme.

## Conséquences

- **Migration** `Version20260526154321` à appliquer en prod avec les autres migrations V1.
- **Cron** : `app:discover-sources` n'est pas ajouté au cron automatique pour le lancement —
  à décider en V2. En attendant, peut être lancé manuellement depuis l'admin.
- **CDC V3** : pas de mise à jour nécessaire. La feature est tracée ici comme avance V2.
- **CLAUDE.md roadmap V2** : ajouter "Découverte automatique de sources (app:discover-sources)"
  lors de la prochaine mise à jour arbitrée.
- **Clé Mistral API** : requise pour les scrapers HTML_LLM (dont les scrapers agrégateurs).
  À saisir dans `/admin/settings` après déploiement.

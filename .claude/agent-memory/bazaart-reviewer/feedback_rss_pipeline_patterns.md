---
name: feedback_rss_pipeline_patterns
description: Patterns et anti-patterns identifiés dans le chantier pipeline RSS multi-méthodes (juin 2026)
metadata:
  type: feedback
---

Chantier pipeline RSS multi-méthodes (WS1-WS4, juin 2026) — patterns à retenir.

**Bonne pratique validée** : FeedReadResult distingue success=false (HTTP/XML raté) de success=true + items=[] (flux vide). Évite les faux-positifs d'auto-désactivation.

**Bonne pratique validée** : ScrapedResourcePersister factorise la logique de dédup à 5 cas partagée par les deux pipelines (LLM/CSS et RSS). Un seul point de vérité.

**Bonne pratique validée** : HtmlSanitizerService strip total + html_entity_decode + mb_substr (2000 chars). Aucun HTML ne survit — anti-XSS et anti-prompt-injection.

**Anti-pattern identifié — Double flush en cas de succès RSS** :
- `persistBatch()` fait son propre `flush()` (ligne ~165 de ScrapedResourcePersister)
- `ReadFeedsCommand` refait un `em->flush()` pour les champs de santé (lastSuccessfulFetch, consecutiveFailures, markRunSuccess)
- Ce double flush est SAIN car le second flush ne porte que sur l'entité ScrapingSource (distincte de ScrapedResource). Pas à signaler comme bug, mais à documenter.

**Anti-pattern identifié — ReadFeedsCommand sans try/catch global sur persistBatch()** :
- Si `persistBatch()` lève une exception (contrainte UNIQUE non anticipée, connexion BDD coupée), le run s'interrompt brutalement pour toutes les sources restantes.
- `ScrapeOpportunitiesCommand` gère le même risque via un `try/catch` par source.
- À corriger : envelopper `persistBatch()` + `em->flush()` dans un try/catch par source dans ReadFeedsCommand.

**Anti-pattern identifié — Log d'échec source au niveau INFO** :
- `logger->info('[read-feeds] Échec source RSS', ...)` (ligne 253 ReadFeedsCommand)
- Un échec de fetch est loggé INFO, alors que la désactivation automatique est loggée WARNING.
- Divergence légère : un échec individuel devrait être WARNING pour être catchable par les alertes.

**Anti-pattern identifié — Filtre RSS en PHP plutôt qu'en SQL dans ReadFeedsCommand** :
- `findAllActive()` charge TOUTES les sources actives, puis `array_filter` sur getType() === RSS.
- Pas de méthode `findActiveRss()` en repository. Acceptable en V1 (faible volumétrie), mais à optimiser si la table grossit.

**Décision B1 (GenericScraper::scrapeRss deprecated mais non supprimé)** : correctement appliquée. La méthode porte `@deprecated` mais est toujours fonctionnelle.

**Why:** Chantier juin 2026 — pipeline multi-méthodes RSS (laminas-feed) ajouté sur la branche demo.

**How to apply:** Pour toute commande orchestratrice de pipeline scraping, vérifier : (1) try/catch par source autour de persistBatch(), (2) niveaux de log cohérents (WARNING pour les erreurs catchables), (3) filtre SQL ou PHP explicite sur le type de source.

---
name: feedback_adr0018_adr0019_enrichissement_patterns
description: ADR-0018 (howToApply/fundingAmount/fundingType/description structurée) + ADR-0019 (applicationUrl/logoUrl) — patterns et anti-patterns identifiés — juin 2026
metadata:
  type: feedback
---

SSRF LogoFetcherService : solide — isSafeHost() partagé avec ListingUrlDiscoverer (public), timeout 8s, max_redirects 3, stream borné 50 Ko. Faille résiduelle : l'URL effective APRÈS redirection n'est PAS re-vérifiée par isSafeHost() (le Symfony HttpClient suit les redirections transparentement sans callback de validation intermédiaire).

Anti-hallucination applicationUrl : deux lignes de défense. 1re — le prompt LLM impose de choisir parmi les liens fournis (liste extractPageLinksForLlm). 2e — PHP : filter_var + http(s) + distinct de sourceUrl + host parseable. Limite documentée : mapItemsToOpportunities() n'a pas accès à la liste des pages réelles pour une vérification exacte.

XSS templates : logoUrl et applicationUrl passent par l'auto-échappement Twig (|e implicite dans {{ }}) — corrects. howToApply utilise |nl2br sans |raw explicite — correct (commentaire documenté dans le template). Description HTML Mistral : |raw justifié uniquement car les balises autorisées sont contraintes par le prompt et MAX_DESCRIPTION_LENGTH.

Troncatures : toutes cohérentes avec les colonnes BDD (fundingAmount 255, fundingType 255, applicationUrl 500, logoUrl 500, howToApply 8000). Double troncature défensive AdminController (mb_substr 500) sur les champs ADR-0019 déjà tronqués par Persister — acceptable.

Propagation complète : 5 champs propagés correctement ScrapedResource → Resource dans AdminController (verifyScrapedOpportunity), ScrapedResourcePersister (updateEnrichedFields), EnrichOpportunitiesCommand (processScrapedResources + processResources), ImportGrantCsvCommand.

PHPStan niveau 6 : 0 erreur.

Anti-patterns résiduels :
- LogoFetcherService : l'URL effective après redirection HTTP n'est pas re-vérifiée par isSafeHost() (SSRF par redirection, mitigé mais non clos).
- show.html.twig ligne 1011 : {{ resource.howToApply|nl2br }} sans |e explicite — Twig échappe par défaut mais le commentaire dit "|e|nl2br" alors que le code n'a que "|nl2br". En Twig avec auto-escape on, nl2br sur une valeur non marquée safe est appliqué APRÈS l'auto-escape — donc correct en pratique, mais incohérent avec la documentation inline.
- OpportunityEnrichmentService::callMistral() a deux blocs de documentation (docblock dupliqué lignes 437-441 et 443-447).
- MAX_TOKENS LlmExtractorService (3 500 tokens) pour Claude Haiku : suffisant pour 1-2 opportunités courtes, mais des pages-listes denses (10 opportunités × description 1000 chars) peuvent dépasser. Pas de guard si la réponse est tronquée (finish_reason: max_tokens non vérifié).
- finish_reason non vérifié : ni LlmExtractorService ni OpportunityEnrichmentService ne vérifient si la réponse LLM a été coupée (finish_reason = "max_tokens") — une description JSON tronquée provoquerait un json_decode silencieusement vide.

**Why:** Enrichissement Ressourcerie produit (description 1882 chars, howToApply, applicationUrl, logoUrl remplis en prod). Points résiduels documentés pour le lot suivant.

**How to apply:** Lors d'un prochain audit LogoFetcherService, signaler l'absence de re-vérification SSRF post-redirection. Surveiller finish_reason dans les réponses LLM si le coût des tokens augmente.

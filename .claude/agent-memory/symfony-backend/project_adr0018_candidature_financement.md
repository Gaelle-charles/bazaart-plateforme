---
name: project-adr0018-candidature-financement
description: ADR-0018 livré — extraction LLM de howToApply/fundingAmount/fundingType, prompts enrichis, affichage page détail
metadata:
  type: project
---

ADR-0018 livré (2026-06-18) : enrichissement extraction LLM + affichage page détail Resource.

**Champs ajoutés (Resource ET ScrapedResource, tous nullable) :**
- `howToApply` (TEXT) : modalités de candidature extraites par le LLM
- `fundingAmount` (VARCHAR 255) : montant lisible (ex : "5 000 €")
- `fundingType` (VARCHAR 255) : nature du financement (ex : "Bourse en argent")

**Migration :** `Version20260619034700` — 6 ALTER TABLE, non-destructive (DEFAULT NULL).

**Prompts LLM modifiés :**
- `LlmExtractorService` (Anthropic + Mistral) : 3 nouveaux champs + description "COMPLÈTE et STRUCTURÉE OBLIGATOIRE" (max 1 000 chars côté liste, tronquée à 1 500 en PHP). MAX_TOKENS monté à 3 500.
- `OpportunityEnrichmentService::callMistral()` : 9 clés JSON (au lieu de 6). Description avec 6 sections HTML structurées obligatoires. MAX_RESPONSE_TOKENS à 3 000.

**Pipeline de propagation :**
1. LlmExtractorService → ScrapedOpportunity (3 nouveaux champs readonly)
2. ScrapedResourcePersister::updateEnrichedFields() → ScrapedResource
3. AdminController::verifyScrapedOpportunity() → Resource (propagation directe)
4. EnrichOpportunitiesCommand (ScrapedResource + Resource modes) → mise à jour

**Affichage (templates/resource/show.html.twig) :**
- Bloc `.funding-candidature-block` EN TÊTE de la colonne principale (avant Candidater)
- fundingAmount + fundingType dans une `.funding-grid` conditionnelle
- howToApply avec `|nl2br` (PAS `|raw`) — sécurité XSS garantie
- Description : conserve `|raw` (HTML Mistral structuré)
- Aucun bloc affiché si tous les champs sont null (conditionnel global)

**Sécurité XSS :**
- `howToApply` : contenu LLM libre → `|nl2br` sur valeur auto-échappée Twig (JAMAIS `|raw`)
- `description` : HTML Mistral contrôlé → `|raw` valide (balises <p><ul><li><strong> seulement)
- `fundingAmount/Type` : courtes strings → échappement automatique Twig

**Why:** Améliorer la qualité des pages Resource pour guider les artistes immédiatement vers les infos essentielles (montant + comment candidater).
**How to apply:** Pour tout futur ajout de champ LLM : suivre le même pipeline (ScrapedOpportunity → updateEnrichedFields → verifyScrapedOpportunity → display conditionnel Twig).

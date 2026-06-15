---
name: feedback-no-em-dash
description: Ne JAMAIS utiliser le tiret cadratin « — » dans les titres et le contenu produits pour le site
metadata:
  type: feedback
---

**Règle : ne jamais utiliser le tiret cadratin « — » (em dash) dans les titres et le contenu.**

**Why:** préférence éditoriale explicite de Gaëlle (demandée le 2026-06-15). Le « — » n'est pas souhaité dans les textes du site (titres, paragraphes, descriptions).

**How to apply:**
- Dans tout contenu rédigé pour le site (vitrine, textes de présentation, titres, descriptions de ressources) : remplacer « — » par une virgule, un point, des parenthèses ou « : » selon le sens.
- Pour les contenus générés par IA : le **prompt d'enrichissement** (`OpportunityEnrichmentService`) et tout prompt LLM qui produit des titres/descriptions doit **interdire explicitement** le tiret cadratin. Les contenus déjà générés (ex. les 49 descriptions enrichies) peuvent en contenir → à nettoyer/re-générer si Gaëlle le souhaite.
- S'applique au **contenu livré** ; tâcher aussi de modérer son usage dans les échanges.

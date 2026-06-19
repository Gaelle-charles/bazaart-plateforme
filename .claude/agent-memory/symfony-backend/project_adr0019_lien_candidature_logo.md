---
name: project-adr0019-lien-candidature-logo
description: ADR-0019 livré — applicationUrl (lien candidature direct, LLM + anti-hallucination) et logoUrl (parsing HTML sans LLM) sur Resource+ScrapedResource, display Twig avec badge "B" fallback
metadata:
  type: project
---

ADR-0019 livré le 2026-06-19 : ajout du lien de candidature distinct (`applicationUrl`) et du logo de l'organisme (`logoUrl`) sur les entités `Resource` et `ScrapedResource`.

**Why:** L'artiste avait un seul bouton "Candidater / En savoir plus" pointant vers la page de présentation — pas idéal. ADR-0019 ajoute un lien direct vers le formulaire/page de dépôt de candidature (distinct de la page source), et un logo pour identifier l'émetteur au premier coup d'oeil.

**How to apply:** Ce pattern (anti-hallucination LLM + fetch HTML SSRF-safe + badge fallback) peut être réutilisé pour d'autres champs URL.

## Périmètre livré

### Entités
- `Resource` + `ScrapedResource` : 2 nouveaux champs `applicationUrl` (string 500 nullable) + `logoUrl` (string 500 nullable)
- Migration : `Version20260619040230.php` (non-destructive, 4 colonnes ALTER)

### Services
- **`LogoFetcherService`** (nouveau `/app/src/Service/LogoFetcherService.php`) :
  - Fetch page d'accueil (applicationUrl si présent, sinon sourceUrl)
  - Chaîne de repli : apple-touch-icon > icon/shortcut > og:image
  - SSRF via `isSafeHost()` de `ListingUrlDiscoverer`, timeout 8s, 50 Ko max, max_redirects 3
  - Stocke uniquement l'URL du logo (ne télécharge pas l'image)

- **`LlmExtractorService`** (modifié) :
  - `extractPageLinksForLlm(string $html, string $baseUrl): string` — méthode publique extraite avant cleanHtml()
  - Prompts Mistral ET Anthropic enrichis avec `applicationUrl` + liste des liens de la page
  - Guard anti-hallucination côté PHP dans `mapItemsToOpportunities()` : URL doit être HTTP(s), différente de sourceUrl, host parseable
  - Bug corrigé : `$pageLinksContext = ''` (écrasait le paramètre) — supprimé dans `callAnthropicApi()`

- **`OpportunityEnrichmentService`** (modifié) :
  - `enrich()` : extrait les liens avant cleanHtml, passe à callMistral, puis appelle LogoFetcherService
  - `validateAndBuildDto()` : extraction + guard anti-hallucination applicationUrl
  - Reconstruction du DTO readonly avec logoUrl après fetch

- **`ScrapedResourcePersister`** (modifié) :
  - `updateEnrichedFields()` : applique applicationUrl + logoUrl depuis DTO (troncature 500 chars, pas d'écrasement si vide)

### Propagation AdminController
- `verifyScrapedOpportunity()` : propage applicationUrl + logoUrl de ScrapedResource → Resource (troncature défensive 500 chars)

### Commandes
- `EnrichOpportunitiesCommand` : 2 boucles (ScrapedResource + Resource) mises à jour pour appliquer applicationUrl + logoUrl
- `ImportGrantCsvCommand` : même ajout dans la boucle --enrich

### Templates
- `resource/show.html.twig` :
  - Logo en corner haut-droit du hero (position:absolute, mobile = position:static)
  - Fallback badge "B" fond lime si logoUrl null
  - CTA split : si applicationUrl → bouton "Candidater" (accent) + bouton "En savoir plus" (outline) ; sinon → bouton unique "Candidater / En savoir plus" (rétro-compat)
  - Classes CSS ajoutées : `.resource-hero__logo`, `.resource-hero__logo--badge`, `.btn-secondary`, `.cta-group`, `.card-logo`, `.card-logo--badge`

- `resource/index.html.twig` :
  - Vue liste : miniature logo 36×36 dans `.list-card__right` au-dessus de la deadline
  - Vue grille : logo + badges sur même ligne en haut de la card
  - Même fallback badge "B"

## Points techniques notables
- `|raw` interdit sur applicationUrl/logoUrl (auto-escape Twig)
- `onerror` JS minimal pour fallback image en cas d'erreur réseau (client-side uniquement)
- PHPStan niveau 6 : 0 erreurs après correction du bug callAnthropicApi

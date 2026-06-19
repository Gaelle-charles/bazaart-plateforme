---
name: project-scraper-api-fallback
description: Architecture du repli API de scraping (ScraperApiClient) pour débloquer les sites bloquant l'IP du droplet
metadata:
  type: project
---

Repli API de scraping livré en juin 2026 — déblocage sites Cloudflare/IP-ban.

**Architecture :**
- `ScraperApiClient` : service autonome `App\Service\ScraperApiClient`
  - `isAvailable()` : lit `scraper_api_enabled` + `scraper_api_key` en BDD
  - `fetchViaApi(url, isSafeHostFn)` : construit l'URL depuis `scraper_api_url_template`, appel GET timeout 40s
  - La clé API n'est JAMAIS dans les logs ni dans `.env` — seulement en BDD (`app_settings`)
- `fetchHtmlRobust()` : ajouté dans `HttpBrowserFetchTrait`
  - Tente d'abord le fetch direct (closure fournie par l'appelant)
  - Si échec (null OU HTML vide) ET API disponible → `fetchViaApi()`
  - Un seul niveau de repli — pas de boucle

**Consommateurs branchés :**
- `ListingUrlDiscoverer::doFetch()` — page d'accueil pour LLM fallback (PAS scoreUrl() — trop coûteux)
- `OpportunityEnrichmentService::fetchPage()`
- `LogoFetcherService::fetchPage()`
- `GenericScraper::scrapeHtmlLlm()`

**3 réglages app_settings (SeedSettingsCommand) :**
- `scraper_api_enabled` : "true"/"false", défaut "false"
- `scraper_api_key` : secret, null par défaut
- `scraper_api_url_template` : template `{key}`+`{url}`, défaut ScraperAPI avec render=true

**Provider-agnostique :** changer le template pour ScrapingAnt, BrightData etc. sans code.

**SSRF :** guard `isSafeHost()` sur l'URL CIBLE avant envoi à l'API tierce (callback fourni par l'appelant, pas de couplage au service SSRF).

**Why:** L'IP fixe du droplet DigitalOcean est souvent bannie par les sites culturels. Le repli API route depuis une IP propre et peut rendre le JS.

**How to apply:** Activer depuis `/admin/settings` en mettant `scraper_api_enabled=true` et en saisissant la clé ScraperAPI. Le quota n'est consommé qu'en cas d'échec direct.

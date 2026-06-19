---
name: feedback_scraper_api_client_patterns
description: ScraperApiClient + HttpBrowserFetchTrait — repli API de scraping — patterns et anti-patterns identifiés — juin 2026
metadata:
  type: feedback
---

PHPStan 0 erreur. Points de vigilance à retenir pour les prochaines relectures :

**Sécurité — SSRF template URL admin :**
Le `scraper_api_url_template` vient de `app_settings` (contrôle admin). Un admin malveillant ou un SSRF en BDD pourrait y injecter `http://169.254.169.254/?url={url}` pour exfiltrer les métadonnées cloud. La garde SSRF dans `ScraperApiClient::fetchViaApi()` ne s'applique qu'à `$targetUrl` (l'URL cible scrapée), pas à l'URL de l'API elle-même. Acceptable dans le modèle de menace actuel (attaquant admin = game over) mais à documenter.

**Sécurité — Callback SSRF passthrough dans GenericScraper et OpportunityEnrichmentService :**
Si `$listingUrlDiscoverer === null`, le callback SSRF retourne `true` sans vérification (`fn(string $u): bool => ... : true`). Acceptable car `$scraperApiClient` est aussi `null` dans ce cas (deux paramètres optionnels liés), mais la corrélation des deux conditions n'est pas explicitement vérifiée dans le trait.

**Sécurité — GenericScraper::scrapeRss() sans garde SSRF :**
La méthode `scrapeRss()` fait un `$this->httpClient->request()` direct sur `$source->getUrl()` sans passer par `isSafeHost()`. Les URLs de `ScrapingSource` viennent de la BDD (admin), ce qui atténue le risque, mais c'est une incohérence avec le reste du pipeline.

**Sécurité — URL effective post-redirection non vérifiée dans ScraperApiClient :**
`ScraperApiClient::fetchViaApi()` utilise `$this->httpClient->request()` + `getContent()` sans vérifier l'URL effective après éventuelles redirections (si l'API de scraping renvoie une redirection). Risque faible car l'API est un service tiers de confiance (ScraperAPI), mais à noter.

**Double lecture des settings dans fetchViaApi() :**
`isAvailable()` lit `scraper_api_enabled` et `scraper_api_key`. `fetchViaApi()` relit les 3 settings depuis la BDD. Double accès BDD par appel si `isAvailable()` est appelé juste avant `fetchViaApi()`. Pas de cache partagé. Mineur (coût faible, N appels BDD max = N sites en échec).

**Robustesse — Pas de vérification du Content-Type dans ScraperApiClient :**
L'API peut retourner du HTML 200 mais aussi une réponse JSON d'erreur (clé invalide, quota). On lit `getContent()` sans vérifier si c'est bien du HTML. Mineur car le HTML vide est déjà géré.

**Why:** Chantier ScraperApiClient — repli API de scraping sur IP bannie — juin 2026
**How to apply:** Vérifier ces points sur tout nouveau consommateur de `fetchHtmlRobust()` ou `ScraperApiClient`.

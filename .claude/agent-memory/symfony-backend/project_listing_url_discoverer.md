---
name: project-listing-url-discoverer
description: ADR-0017 Option C hybride livré — ListingUrlDiscoverer service + app:discover-listing-urls command
metadata:
  type: project
---

ADR-0017 Option C hybride implémentée : pour chaque site du CSV de Gaëlle, trouver la sous-page qui liste les opportunités et l'enregistrer comme ScrapingSource agrégateur.

**Fichiers créés :**
- `/app/src/Service/ListingUrlDiscoverer.php` — service métier complet (avec correctifs sécurité Lot 4)
- `/app/src/Service/DiscoveryResult.php` — DTO résultat readonly
- `/app/src/Command/DiscoverListingUrlsCommand.php` — commande mince

**Algorithme (2 étapes) :**
1. Heuristique : teste ~30 chemins FR+EN (/appels-a-projets, /grants, /open-calls...), score par mots-clés "listing" (deadline, candidature, apply...), seuil minimum = 2 mots-clés différents
2. Fallback LLM : télécharge homepage, cleanHtml() (8000 chars), Mistral → Anthropic, prompt {"listing_url": "..."}
   → L'URL LLM est maintenant vérifiée par scoreUrl() (vraie requête HTTP) avant persistance (AV-1)

**Sécurité (Lot 4 — correctifs SSRF) :**
- `isSafeHost(string $url): bool` — méthode publique bloquant localhost/127.x/::1/0.0.0.0, 169.254/16, RFC1918 (10/8, 172.16-31/12, 192.168/16), schéma non-http(s)
- Garde appliquée en début de `scoreUrl()` ET `doFetch()` ET sur l'URL LLM dans `extractListingUrlFromJson()`
- Vérification de l'URL EFFECTIVE après redirections (via `$response->getInfo('url')`) dans `scoreUrl()` et `doFetch()`
- `max_redirects` réduit de 5 à 3 (constante `MAX_REDIRECTS`)
- Lecture partielle via `$this->httpClient->stream($response)` + cancel() à BODY_INSPECT_BYTES (évite OOM)
- Logs "heuristique sans résultat" et "aucune URL trouvée" passés de INFO à WARNING

**Usage :**
```bash
php bin/console app:discover-listing-urls --url=https://institutfrancais.com --dry-run
php bin/console app:discover-listing-urls var/grant.csv --limit=10 --dry-run
php bin/console app:discover-listing-urls var/grant.csv --limit=2  # créé en BDD
# Test SSRF (doit être rejeté proprement) :
php bin/console app:discover-listing-urls --url=http://169.254.169.254 --dry-run
php bin/console app:discover-listing-urls --url=http://10.0.0.1 --dry-run
```

**ScrapingSource créée :** type=html_llm, estAgregateur=true, scraperSlug=null, actif=true, statutDernierRun=never_run

**Déduplication :** findByUrl() sur l'URL-liste avant persist(). Lazy flush en fin de boucle (une transaction par run).

**Why:** Le CSV de Gaëlle contient des domaines racines sans préciser la page-liste. Cette commande automatise la découverte et alimente le pipeline de scraping existant. Les correctifs SSRF sont critiques car le droplet DO expose l'API de métadonnées sur 169.254.169.254.

**How to apply:** Vérifier que la clé mistral_api_key est présente dans app_settings avant d'utiliser le fallback LLM. Le CSV est monté à /var/www/html/var/grant.csv dans le container.

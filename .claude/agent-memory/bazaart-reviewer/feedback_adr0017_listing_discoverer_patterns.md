---
name: feedback_adr0017_listing_discoverer_patterns
description: ADR-0017 ListingUrlDiscoverer — SSRF sans garde IP, URL LLM non vérifiée par HTTP, getContent() non-streamé, ambiguïté null dans persistIfNew, log INFO sur échec
metadata:
  type: feedback
---

## Patterns identifiés — ADR-0017 (juin 2026)

**PHPStan niveau 6 : 0 erreur.**

### CRITIQUE — SSRF sans garde IP (scoreUrl + doFetch)
`extractBaseUrl()` accepte n'importe quel schéma http/https, y compris des IPs privées/link-local (169.254.169.254, 127.0.0.1, 0.0.0.0, ::1, localhost). Aucun filtre IP dans `scoreUrl()` ni `doFetch()`. Risque limité (CLI/admin seulement) mais présent si un CSV malveillant ou une redirection d'un site public pointent vers le réseau interne Docker.
**Correction type :** ajouter une garde avant toute requête HTTP sortante :
```php
private function isSafeUrl(string $url): bool {
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) return false;
    if (str_starts_with($host, '169.254.') || str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) return false;
    return true;
}
```
**Why:** FeedDetectorService a ce pattern (whitelist schémas), à généraliser ici aussi.

### Avertissement — URL LLM non vérifiée par requête HTTP avant persistance
`runLlmFallback()` → `extractListingUrlFromJson()` valide uniquement avec `filter_var(FILTER_VALIDATE_URL)` puis appelle directement `persistIfNew()`. Le LLM peut halluciner une URL syntaxiquement valide mais inexistante. L'URL heuristique passe par `scoreUrl()` (requête HTTP réelle), pas l'URL LLM.
**Correction type :** appeler `scoreUrl($listingUrlFromLlm)` après `runLlmFallback()`, ne persister que si score >= 0 (HTTP 200 HTML confirmé).

### Avertissement — `getContent()` charge le body complet avant `mb_substr()`
Ligne 395 dans `scoreUrl()` : `$body = $response->getContent()` télécharge la totalité de la page avant de découper à `BODY_INSPECT_BYTES`. Sur une page de 5 Mo (site avec images inline base64), cela consomme beaucoup de mémoire × 33 requêtes par site.
**Correction type :** utiliser `$response->toStream()` avec lecture des premiers 30 000 octets uniquement, ou passer `'buffer' => false` + stream manuel.

### Avertissement — `verify_peer: false` + `verify_host: false` global
Désactiver la vérification SSL sur toutes les requêtes est un choix délibéré (petites structures culturelles), mais la combinaison avec l'absence de garde IP amplifie le risque. Un attaquant qui contrôle un DNS peut pointer vers une IP interne avec un certificat auto-signé — les deux protections SSL ET IP sont alors contournées.

### Mineur — Ambiguïté `null` dans `persistIfNew()`
La méthode retourne `null` pour deux cas distincts : "entité persistée, ID inconnu avant flush" ET "mode dry-run". Le docblock l'avoue ("null si créée ou dry-run"). Cela force la commande à connaître le contexte `$dryRun` pour interpréter `null`. Un type dédié (enum ou classe résultat) serait plus robuste.

### Mineur — Log `INFO` sur "aucune URL-liste trouvée" (ligne 272)
Cohérent avec la politique du projet (échec de découverte = état normal, pas une erreur). Mais pourrait être `WARNING` pour faciliter le monitoring en production. Voir [[feedback_rss_pipeline_patterns]] pour la même discussion.

### Mineur — `created()` dans `DiscoveryResult` faux en dry-run
`created()` retourne `true` si `listingUrl !== null && sourceId !== -1`. En dry-run, `sourceId` est `null` et `listingUrl` est non-null → `created()` retourne `true` alors qu'aucune source n'a été créée. La commande compense en vérifiant `$dryRun` explicitement, mais le getter est trompeur.

### Note positive
- Architecture propre : séparation services/commande respectée, aucune logique métier dans la commande.
- Déduplication intra-lot (par domaine) ET inter-lot (findByUrl BDD) : double garde correcte.
- BOM UTF-8 géré (appris de ADR-0016 Lot 2).
- `strict_types=1` présent sur tous les fichiers.
- Lazy flush (un seul flush en fin de boucle) : pattern correct.
- Chemins heuristiques FR en priorité : cohérent avec le ciblage Bazaart.
- `extractBaseUrl()` publique : réutilisable par la commande pour la normalisation.

**How to apply:** Lors de la prochaine PR touchant ListingUrlDiscoverer, vérifier en priorité la garde IP SSRF (ajout d'`isSafeUrl()`), puis la validation HTTP de l'URL LLM.

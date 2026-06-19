---
name: feedback_adr0016_lot1_patterns
description: ADR-0016 Lot 1 — discipline/localisation/niveau sur Ressourcerie (modèle + extraction LLM) — patterns et anti-patterns identifiés — juin 2026
metadata:
  type: feedback
---

ADR-0016 Lot 1 : enrichissement modèle Ressourcerie + extraction LLM (juin 2026)

**PHPStan niveau 6 : 0 erreur.**

## Points critiques

### CRITIQUE — Absence de troncature city/country avant insert en BDD

`LlmExtractorService::mapItemsToOpportunities()` ne tronque pas `$city` (colonne VARCHAR 150) ni `$country` (colonne VARCHAR 100) avant de les écrire dans le DTO. `ScrapedResourcePersister::updateEnrichedFields()` les copie tels quels dans l'entité. Un LLM qui hallucine une valeur longue provoque une exception Doctrine non attrapée à l'intérieur du `flush()` unique — toute la transaction batch échoue silencieusement (ou fatal selon le niveau d'erreur).

Correction :
```php
$city    = mb_substr(trim((string) ($item['city']    ?? '')), 0, 150);
$country = mb_substr(trim((string) ($item['country'] ?? $item['pays'] ?? '')), 0, 100);
```

### CRITIQUE — Double requête BDD `disciplines` par cycle extractFromHtml + verify

`LlmExtractorService::buildDisciplinesListForPrompt()` appelle `DisciplineRepository::findAllOrdered()` à chaque invocation (pas de cache interne). `DisciplineMapperService::buildNormalizedCache()` en fait autant au premier appel. Dans un cycle complet (scraping + vérification admin), la table `disciplines` est interrogée deux fois par deux services distincts pour des données identiques. Faible impact V1 (~8 disciplines) mais pattern à consolider.

Correction à moyen terme : injecter `DisciplineMapperService` dans `LlmExtractorService` et utiliser son cache normalisé pour construire la liste prompt, ou ajouter un cache statique dans `buildDisciplinesListForPrompt()`.

## Avertissements

### Faux positif sous-chaîne — "art" seul vers Arts numériques (étape 4)

`DisciplineMapperService` step 4 est bidirectionnel : `str_contains($normalized, $dbKey) || str_contains($dbKey, $normalized)`. Si le LLM retourne "art" seul (non contraint), `str_contains("arts numeriques", "art")` est `true` → mappe vers Arts numériques (alphabétiquement première). Le prompt contraint le LLM à la liste BDD, ce qui réduit ce risque, mais il reste en cas de comportement LLM inattendu.

Correction : ajouter une longueur minimale à l'étape 4 (refuser les libellés normalisés de moins de 4 caractères dans le matching par sous-chaîne) :
```php
if ($discipline === null && mb_strlen($normalized) >= 4) {
    foreach ($this->normalizedCache as $dbKey => $dbDiscipline) { ... }
}
```

### Disciplines mappées depuis le CSV `ScrapedResource::$disciplines` (pas de `disciplinesLabels` stocké)

Lors de `verifyScrapedOpportunity`, le controller explode le CSV `ScrapedResource::$disciplines` pour le passer à `DisciplineMapperService::mapLabelsToEntities()`. Le DTO `ScrapedOpportunity::$disciplinesLabels` (tableau contraints BDD) n'est jamais persisté directement — seul le CSV l'est. Si le CSV contient les noms BDD exacts (grâce au prompt contraint), le matching exacte fonctionne. Mais si le CSV est enrichi d'un ancien scraper (avant ADR-0016), les libellés libres passent par les synonymes/sous-chaîne. Pas un bug en soi, mais le pipeline est indirect.

## Suggestions

### Logique métier dans le controller : mapping disciplines délégué, mais pas intégralement

`verifyScrapedOpportunity` contient un explode/mapLabels inline (lignes 547-554). Ce n'est pas grave (3 lignes), mais si le CSV peut aussi provenir de `$disciplinesLabels`, il vaudrait mieux exposer une méthode `mapFromScrapedResource(ScrapedResource $sr): Discipline[]` dans `DisciplineMapperService` pour encapsuler ce split.

### Pas de test unitaire sur `DisciplineMapperService`

Aucun test n'existe pour ce nouveau service. Les cas critiques à couvrir : libellé exact, synonyme, sous-chaîne, libellé invalide (ignoré), doublon (même discipline deux fois), liste vide. Priorité modérée vu la couverture V1 à 30 %.

### `buildDisciplinesListForPrompt` appelée dans `callMistralApi` ET `callAnthropicApi` — pas de memoïzation

Si `extractFromHtml` est appelée plusieurs fois dans la même requête (ex: batch de scrapers), `findAllOrdered()` est exécuté à chaque appel. Ajouter une propriété `private ?string $disciplinesListCache = null;` dans `LlmExtractorService`.

**Why:** Risque de données corrompues (city/country trop longues) + faux positif disciplineMapper sur libellés courts.
**How to apply:** Toujours vérifier la troncature des champs LLM libres contre les longueurs de colonnes BDD. Méfiance sur les matchings par sous-chaîne bidirectionnelle sur des clés courtes.

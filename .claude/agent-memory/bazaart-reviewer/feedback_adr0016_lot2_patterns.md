---
name: feedback_adr0016_lot2_patterns
description: ADR-0016 Lot 2 — import CSV grants + enrichissement LLM étendu (city/country/experienceLevel/disciplines) — patterns et anti-patterns identifiés — juin 2026
metadata:
  type: feedback
---

ADR-0016 Lot 2 : ImportGrantCsvCommand + GrantCsvImporter + OpportunityEnrichmentService enrichi — juin 2026

**PHPStan niveau 6 : 0 erreur.**

## Avertissements

### Faux positif str_contains dans mapCategoryToType — "IMMIGRANT" → "Bourse & Financement"

`GrantCsvImporter::mapCategoryToType()` fait une correspondance partielle `str_contains($normalized, $pattern)` après la correspondance exacte. Le pattern "GRANT" est une sous-chaîne de "IMMIGRANT" : `str_contains("IMMIGRANT", "GRANT")` vaut `true`, donc la catégorie inconnue "IMMIGRANT" est mappée vers "Bourse & Financement" au lieu de tomber dans le type par défaut.

Même risque (plus faible) pour "TRAVEL" contenu dans "TRAVEL WRITING" (mais là c'est sémantiquement correct).

Anti-pattern identifié dans [[feedback_adr0016_lot1_patterns]] (même famille que le faux positif "art" seul).

Correction recommandée : ajouter un séparateur de mot lors de la correspondance partielle, ou passer les patterns à matcher comme mots entiers :
```php
// Correspondance mot entier au lieu de simple sous-chaîne
foreach (self::CATEGORY_MAP as $pattern => $type) {
    // Cherche le pattern entouré de début/fin ou de non-alphanum
    if (preg_match('/(?<![A-Z])' . preg_quote($pattern, '/') . '(?![A-Z])/i', $normalized)) {
        return $type;
    }
}
```

### Disciplines LLM non filtrées contre la liste BDD — "Architecture" peut être stocké

`validateAndBuildDto()` accepte tout label string non-vide retourné par Mistral dans le tableau `disciplines`, sans vérifier que la valeur existe dans la liste BDD envoyée dans le prompt. Si Mistral hallucine "Architecture" (non listé), le label est stocké tel quel dans `ScrapedResource::$disciplines` (string CSV) et dans `$disciplinesLabels`.

La contrainte n'est donc appliquée qu'au niveau du prompt (déclarative), pas côté PHP (impérative).

Impact : lors de la vérification admin, `DisciplineMapperService::mapLabelsToEntities()` tentera de mapper "Architecture" → aucune correspondance → discipline ignorée silencieusement. Ce n'est pas un bug bloquant (la valeur hors-liste est juste perdue lors de la validation), mais le champ `ScrapedResource::$disciplines` peut afficher en admin des valeurs fantômes non mappables.

Correction recommandée : filtrer les labels en PHP après l'appel Mistral :
```php
// Dans validateAndBuildDto(), après avoir construit $disciplinesLabels :
if ($this->disciplinesListCache !== null) {
    $allowedList = array_map('trim', explode(', ', $this->disciplinesListCache));
    $disciplinesLabels = array_values(
        array_filter($disciplinesLabels, fn(string $l) => in_array($l, $allowedList, true))
    );
}
```
Attention : cela nécessite que `$disciplinesListCache` soit construit avant `validateAndBuildDto()`, ce qui est le cas (appel dans `callMistral()`).

### BOM UTF-8 non géré sur l'en-tête CSV — premier champ silencieusement cassé

`SplFileObject` avec `READ_CSV` ne supprime pas le BOM (Byte Order Mark : `\xEF\xBB\xBF`) des fichiers CSV exportés par Excel ou Google Sheets. Après `strtoupper(trim())`, la première colonne devient `"\xEF\xBB\xBFOPEN CALLS"` au lieu de `"OPEN CALLS"`. Résultat : `$row['OPEN CALLS']` est toujours `''` pour toutes les lignes → toutes les lignes ignorées avec "Titre vide".

Correction :
```php
$rawHeader = $file->current();
if (is_array($rawHeader) && isset($rawHeader[0])) {
    // Supprimer le BOM UTF-8 si présent (fichiers Excel/Google Sheets)
    $rawHeader[0] = ltrim($rawHeader[0], "\xEF\xBB\xBF");
}
```

### Enrichissement itère $importResult->opportunities (toutes) et non $persistResult->inserted (nouvelles seulement)

Le bloc `--enrich` itère sur **toutes** les opportunités parsées (`$importResult->opportunities`), pas seulement sur celles nouvellement insérées. Le commentaire du code indique "on enrichit toutes les opportunités importables" mais le titre de section dit "Enrichissement LLM (N nouvelles opportunités)". En pratique :
- Si 80 opportunités parsées dont 70 déjà en BDD (updated/skipped), `--enrich` appelle quand même `findByUrl()` + potentiellement Mistral sur les 70 existantes.
- `findByUrl()` retourne null uniquement si l'URL est absente de la BDD → les 70 "déjà en BDD" passent quand même au bloc enrichissement.

Bilan : sur un ré-import, `--enrich` ré-enrichit potentiellement toutes les opportunités déjà existantes. C'est un coût inutile (~0,001 € × N) et la règle "on ne jamais écrase country si déjà renseigné" limite les dégâts, mais le comportement est en contradiction avec le message console.

Correction : passer les URLs insérées en retour de `ScrapedResourcePersister::persistBatch()` ou filtrer selon `$persistResult->inserted` :
```php
// Stocker les URLs créées dans PersistResult (modification légère du service)
// Ou : vérifier la date createdAt === aujourd'hui comme heuristique
```

### SSRF (Server Side Request Forgery) — signalé, risque limité mais non nul

`fetchPage()` fait un GET HTTP sur des URLs issues directement du CSV (contrôle utilisateur). Cette commande étant admin/CLI uniquement (pas exposée en HTTP public), le risque SSRF est fortement limité : un attaquant devrait déjà avoir accès CLI au serveur. Toutefois, sur un serveur avec métadonnées cloud (AWS IMDSv1, DigitalOcean metadata), une URL comme `http://169.254.169.254/latest/meta-data/` dans le CSV serait fetchée sans restriction.

Mention documentée, pas critique pour V1 en CLI pur.

## Suggestions

### Séparateur CSV fixé à virgule — pas de détection automatique du séparateur

`setCsvControl(',', '"', '\\')` est codé en dur. Les exports Google Sheets peuvent utiliser `;` selon la locale. En pratique le CSV testé (84 lignes) utilise bien `,` mais si Gaëlle exporte depuis une locale FR, le séparateur peut changer silencieusement.

Suggestion : documenter clairement la contrainte ("le CSV doit utiliser `,` comme séparateur") ou tenter une détection heuristique sur la première ligne.

### totalLines() sous-estime si --limit est actif

`ImportResult::totalLines()` retourne `count($opportunities) + count($ignoredLines)`. Si `--limit=5` est passé, le résultat sera "5 lignes totales" alors que le CSV en contient 92. Le terme "lignes totales dans le CSV" dans le résumé est donc trompeur avec `--limit`.

Correction légère : passer le nombre réel de lignes lues comme paramètre de `ImportResult`, ou renommer la métrique en "Lignes traitées".

### Pas de test unitaire pour GrantCsvImporter::extractUrl() et parseDeadlineFromCsv()

Ces deux méthodes couvrent des cas complexes (5 formats d'URL, logique d'année suivante, cas 29 fév bissextile). Ce sont exactement les méthodes qui méritent des tests unitaires avec table de cas. Priorité modérée vu la couverture cible V1 à 30 %.

**Why:** BOM silencieux = import complet raté sans message d'erreur utile. Labels LLM non filtrés = valeurs fantômes en admin. str_contains partiel = mauvais mapping de catégorie.
**How to apply:** Toujours strip_bom sur le premier champ d'en-tête CSV. Toujours valider côté PHP les sorties LLM contre la liste de référence (ne pas se fier uniquement au prompt). str_contains sur des patterns courts = vérifier les sous-chaînes non intentionnelles.

---
name: feedback_enrichment_ia_patterns
description: Anti-patterns et points de vigilance repérés sur le module d'enrichissement IA (OpportunityEnrichmentService, EnrichOpportunitiesCommand)
metadata:
  type: feedback
---

## Patterns récurrents à surveiller sur le pipeline d'enrichissement IA

### 1. Asymétrie de traitement disciplines entre les deux modes
Le mode `--published` (`processResources`) ne met pas à jour les disciplines de l'entité `Resource`,
car `Resource::disciplines` est une `Collection` ManyToMany (entité `Discipline`), pas un `string`.
C'est intentionnel. Attention à ne pas introduire `$item->setDisciplines(string)` sur `Resource`.

**Why:** `ScrapedResource::disciplines` est un `string` libre ; `Resource::disciplines` est une relation ORM.
**How to apply:** Toujours vérifier quel type est `disciplines` sur l'entité cible avant d'y écrire.

### 2. Condition de mise à jour du titre couplée à la description
Dans `processScrapedResources`, le titre n'est mis à jour que si `$enrichment->description !== null`.
Cela signifie que si Mistral retourne uniquement `titre` + `disciplines` (sans description), le titre
est ignoré. Ce comportement est voulu (éviter de remplacer un titre correct sans description associée)
mais peut sembler contre-intuitif.

**Why:** Décision conservative — le titre reformulé sans contexte de description pourrait réduire la qualité.
**How to apply:** Signaler si une future PR touche à cette condition sans expliciter le cas "titre seul".

### 3. Docblock isEmpty() partiel
Le commentaire de `isEmpty()` dit "title = null ET description = null → vide" mais l'implémentation
vérifie aussi les disciplines. Le commentaire ne mentionne pas le 3ème champ. Cohérence à vérifier
si le DTO évolue encore.

### 4. Erreur PHPStan infrastructure (`.env` absent du container)
PHPStan niveau 6 échoue dans le container Docker avec "Unable to read the /var/www/html/.env"
car le fichier `.env` n'est pas monté dans le container (variables injectées via Docker Compose).
Ce n'est pas un bug du code — c'est une erreur d'infra préexistante indépendante des PRs.

### 5. Placeholder "Description non disponible."
Ce placeholder apparaît dans 4 fichiers distincts (AdminController, ScrapedResourceRepository,
ResourceRepository, EnrichOpportunitiesCommand). Si la chaîne change un jour, il faudra
chercher et remplacer dans tous ces endroits.

### 6. Constante MAX_TEXT_LENGTH documentée avec une valeur incorrecte
La docblock de `MAX_TEXT_LENGTH` dans `OpportunityEnrichmentService` dit "au lieu de la valeur par
défaut de LlmExtractorService (12 000 chars)" — vérifier si la valeur par défaut de LlmExtractorService
est bien 12 000 et non autre chose. Risque de désynchronisation documentaire.

### 7. Passage texte brut → HTML : templates non couverts (PR HTML enrichissement)
Lors du passage de description texte brut → HTML généré par Mistral, plusieurs templates n'ont PAS
été mis à jour et afficheront des balises HTML brutes dans les previews :
- `templates/resource/index.html.twig` (ligne ~772) : `|slice(0, 160)` sans `|striptags`
- `templates/resource/favorites.html.twig` (ligne ~461) : `|slice(0, 160)` sans `|striptags`
- `templates/vitrine/index.html.twig` (lignes 2053-2056) : `|slice(0, 120)` sans `|striptags`
- `templates/admin/resources_pending.html.twig` (ligne ~328) : `|slice(0, 300)` sans `|striptags`
Ces 4 fichiers sont les OUBLIÉS récurrents lors d'un changement de format de `resource.description`.

### 8. |raw sur description dans show.html.twig : vecteur XSS si la source de description évolue
`{{ resource.description|raw }}` est justifié tant que SEUL l'enrichissement IA peut écrire en BDD.
Si un jour une soumission manuelle (artiste, structure, admin) peut aussi alimenter `Resource::description`,
ce `|raw` devient un vecteur XSS direct. Surveiller toute nouvelle voie d'écriture dans ce champ.

### 9. mb_substr sur HTML : coupure de balise possible
`mb_substr($rawDesc, 0, 1500)` dans le service peut couper une balise HTML à mi-chemin (ex: `<stro`).
C'est mineur pour l'affichage (le navigateur corrige), mais peut produire du HTML invalide persisté en BDD.
Solution propre : passer par `html_entity_decode` + `strip_tags` pour mesurer la longueur du texte visible,
puis décider du tronquage, ou utiliser une lib DomDocument.

### 10. Double calcul striptags dans resource_alert.txt.twig
`resource.description|striptags|length` est calculé UNE FOIS pour le test, puis `resource.description|striptags|slice`
recalcule le striptags une deuxième fois inutilement. Pas de bug, mais coût CPU doublé en boucle sur N ressources.
Solution : `{% set plain = resource.description|striptags %}` puis réutiliser `plain`.

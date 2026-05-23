---
name: feedback_fixtures_autopublished
description: Dans les fixtures, autoPublished doit être true pour les ressources admin/structure, pas false pour toutes
metadata:
  type: feedback
---

Dans `AppFixtures`, le champ `autoPublished` est mis à `false` pour toutes les ressources quelle que soit leur source (`submitter`). C'est une incohérence métier : selon la logique documentée dans `ResourceStatus` et `SubmitterRole`, `autoPublished = true` pour les sources `admin` et `structure`, et `false` uniquement pour `artist`.

**Why:** La donnée fausse trompe les tests du StructureVoter et les compteurs du tableau de bord admin (filtres par auto_published).

**How to apply:** À chaque relecture de fixtures ou de services de soumission de ressource, vérifier que `autoPublished` est cohérent avec `submitterRole`. Pattern correct :
```php
$isAuto = in_array($data['submitter'], ['admin', 'structure'], true);
$resource->setAutoPublished($isAuto);
```

Lié à [[feedback_apply_union_type_antipattern]] (même périmètre : logique de publication des ressources).

---
name: feedback_apply_union_type_antipattern
description: Anti-pattern : méthode de service retournant Entity|string pour signaler une erreur — à remplacer par exception ou objet résultat
metadata:
  type: feedback
---

`StructureService::applyAsStructure()` retourne `OrganizationProfile|string` : le type `string` sert à transporter un message d'erreur de validation, que le controller teste avec `is_string($result)`.

Ce pattern est fonctionnel mais problématique :
- PHPStan au niveau 6+ peut signaler un retour de type mixte.
- L'appelant doit connaître la convention (string = erreur) sans contrat formel.
- Rend les tests unitaires plus fragiles (on teste une chaîne, pas un type d'erreur).

Alternatives recommandées :
1. Lancer une `\InvalidArgumentException` ou une exception métier `StructureValidationException` → le controller la capture dans un try/catch.
2. Retourner un objet `StructureApplicationResult { bool $success; ?string $errorMessage; ?OrganizationProfile $profile }`.

**Why:** Détecté lors de la relecture du module Compte Structure (mai 2026). Le pattern existe aussi dans `AuthService::register()` qui retourne `?User`.

**How to apply:** Signaler ce pattern dans les futures relectures de services qui retournent un type union incluant `string` ou `null` pour signaler une erreur.

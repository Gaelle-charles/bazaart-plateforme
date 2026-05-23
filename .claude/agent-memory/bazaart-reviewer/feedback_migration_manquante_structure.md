---
name: feedback_migration_manquante_structure
description: Anti-pattern récurrent — colonnes ajoutées en entité sans migration correspondante, détecté sur le module Structure (mai 2026)
metadata:
  type: feedback
---

Les colonnes `is_structure_partner`, `structure_activated_at`, `structure_activation_validated_by_id` et `structure_application_at` ont été ajoutées à l'entité `OrganizationProfile` sans qu'une migration Doctrine ait été générée pour les créer en base.

Résultat : l'entité PHP et le schéma PostgreSQL sont désynchronisés. L'application plantera en production dès qu'une requête touchera ces colonnes.

**Why:** Les migrations sont générées manuellement (`doctrine:migrations:diff` + `:migrate`). Oublier cette étape est facile, surtout en développement rapide.

**How to apply:** À chaque relecture d'un module, vérifier systématiquement que toutes les colonnes définies dans les entités modifiées ont une migration correspondante. Chercher dans `migrations/` avec `grep` sur les noms de colonnes snake_case.

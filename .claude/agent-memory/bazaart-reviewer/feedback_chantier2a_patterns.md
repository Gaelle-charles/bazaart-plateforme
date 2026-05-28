---
name: feedback-chantier2a-patterns
description: Chantier 2A (deadlineDate + archivage DQL) — bugs et anti-patterns identifiés (mai 2026)
metadata:
  type: feedback
---

## Bug critique : em->clear() dans BackfillDeadlineDateCommand invalide les entités en cours d'itération

Après `$this->em->clear()` dans la boucle de batch, toutes les entités `$resource` du tableau `$candidates` sont détachées de l'IdentityMap. Les lots suivants appellent `setDeadlineDate()` sur des objets détachés → le flush final ne persiste pas ces changements (Doctrine ignore les entités détachées).

**Correction** : utiliser une requête DQL UPDATE directe par lot (comme `archiveExpired()`), ou refaire un `find()` de chaque entité après `clear()`.

**Why:** em->clear() vide intégralement l'IdentityMap — toutes les références obtenues avant l'appel sont invalides pour Doctrine après.

**How to apply:** Signaler systématiquement tout `em->clear()` à l'intérieur d'une boucle qui continue à modifier des entités chargées avant le clear.

## Divergence regex entre archiveExpiredLegacy() et DeadlineParserService

- Legacy (ScrapedResourceRepository) : `/^\d{1,2}\/\d{2}\/\d{4}$/` — mois obligatoirement sur 2 chiffres
- Service (DeadlineParserService)     : `/^\d{1,2}\/\d{1,2}\/\d{4}$/` — mois sur 1 ou 2 chiffres

Un format "1/5/2026" serait parsé par le service (deadlineDate remplie) mais pas par la legacy.
Si `archive_use_legacy = 1` est activé en fallback, ces items ne seraient pas archivés alors que leur deadlineDate est passée.

**Why:** La porte de sortie legacy ne correspond plus exactement au nouveau comportement.
**How to apply:** En cas de feature flag legacy, toujours vérifier que les deux branches ont une logique identique.

## Migration 000226 : description trompeuse

Version20260528000226 dit "retrait du commentaire ajouté par erreur dans Version20260528000131" mais 000131 ne contient aucun COMMENT SQL. En réalité, DBAL génère automatiquement un COMMENT '(DC2Type:datetime_immutable)' lors du diff pour les colonnes datetime_immutable. La migration 000226 supprime ce commentaire automatique pour rester en sync avec doctrine:schema:validate. Comportement normal DBAL 3.x — pas un bug, juste une description confuse.

**How to apply:** Ne pas signaler la double migration comme un problème si doctrine:schema:validate passe.

## DQL UPDATE bypass les listeners Doctrine (comportement normal, à documenter)

`archiveExpired()` utilise une requête DQL UPDATE directe via `executeStatement()`. Cette requête contourne le UnitOfWork et ne déclenche pas `preUpdate` (ni le listener `ScrapedResourceListener`). C'est voulu et correct ici car le listener ne modifie que `deadlineDate` et `title/description`, pas `status`. Pas un bug mais un comportement à garder en tête.

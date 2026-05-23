---
name: feedback_fixtures_validatedby_structure
description: validatedAt/validatedBy doivent rester null pour les ressources auto-publiées (structure/admin)
metadata:
  type: feedback
---

Dans `AppFixtures` et dans tout service de publication de ressource, `validatedAt` et `validatedBy` doivent rester `null` pour les ressources auto-publiées (soumises par une structure ou un admin). Ces champs ne sont pertinents que pour les ressources soumises par un artiste qui ont passé la validation manuelle d'un admin.

**Why:** Le commentaire de l'entité `Resource` l'indique explicitement : "Null si la ressource n'a pas été validée manuellement (cas des ressources auto-publiées par les structures)." Des valeurs non-nulles faussent les compteurs du tableau de bord admin.

**How to apply:** Vérifier ce pattern dans toute fixture ou service qui crée des ressources. Pour les ressources artist, setter validatedAt + validatedBy. Pour admin/structure, ne setter que publishedAt.

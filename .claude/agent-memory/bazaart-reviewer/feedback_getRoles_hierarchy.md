---
name: feedback_getRoles_hierarchy
description: Les voters utilisent getRoles() sur l'entité User au lieu de Security::isGranted() — ne prend pas en compte la role_hierarchy Symfony pour les admins
metadata:
  type: feedback
---

Les voters du projet appellent `in_array('ROLE_X', $user->getRoles())` directement sur l'entité User. `getRoles()` retourne uniquement les rôles bruts stockés en BDD (+ ROLE_USER injecté), sans expansion via la `role_hierarchy` de security.yaml.

Conséquence pratique : si un ROLE_ADMIN a `["ROLE_ADMIN"]` en BDD, `getRoles()` retournera `["ROLE_ADMIN", "ROLE_USER"]` — mais PAS `ROLE_MODERATOR` ni `ROLE_STRUCTURE`. Pour le ForumVoter par exemple, `isAdminOrModerator()` fonctionne car il vérifie explicitement les deux. Pour StructureVoter `canViewDashboard()`, un ROLE_ADMIN se verrait refuser l'accès au dashboard structure car `ROLE_STRUCTURE` n'est pas dans ses `getRoles()`.

**Why:** Signalé lors de la relecture des Voters V1 (mai 2026). Anti-pattern récurrent à détecter dans tous les futurs voters.

**How to apply:** Vérifier dans chaque nouveau voter si `getRoles()` brut est utilisé pour des vérifications de rôle là où la hiérarchie Symfony est attendue. Recommander l'injection du service `Security` et l'utilisation de `$this->security->isGranted('ROLE_X')` à la place.

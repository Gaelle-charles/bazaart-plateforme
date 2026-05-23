---
name: project_v1_voters
description: État des 4 Voters V1 créés semaine 1 (mai 2026) — bugs connus et points à surveiller en V2
metadata:
  type: project
---

Les 4 voters créés en semaine 1 (ResourceVoter, ForumVoter, StructureVoter, LiveVoter) ont été relus le 2026-05-22. ForumVoter relu en détail le 2026-05-23 : aucun getRoles() brut — utilise correctement isGranted() via Security injecté. Les instanceof ForumThread/ForumReply remplacent bien les anciens method_exists(). Voter propre.

Bogue critique identifié dans StructureVoter : `canViewDashboard()` vérifie `in_array('ROLE_STRUCTURE', $user->getRoles())` — un ROLE_ADMIN n'a pas ROLE_STRUCTURE dans ses getRoles() bruts, donc un admin se voit refuser l'accès au dashboard structure. La correction consiste à injecter le service Security et utiliser `$this->security->isGranted('ROLE_STRUCTURE')`.

Même problème latent dans ResourceVoter pour `canEdit()` / `canDelete()` : un ROLE_ADMIN sans ROLE_STRUCTURE dans sa BDD mais avec la hiérarchie Symfony n'est pas impacté ici car on vérifie ROLE_ADMIN directement — mais la pattern est cohérente seulement par chance.

**Why:** La role_hierarchy Symfony n'est pas propagée dans `$user->getRoles()`. Ce fait est documenté dans le commentaire de `isAdminOrModerator()` mais pas appliqué dans StructureVoter.

**How to apply:** En V2, quand on écrit un voter qui dépend de la hiérarchie (ex: ROLE_ADMIN devant hériter de ROLE_STRUCTURE), injecter le service Security et utiliser isGranted() au lieu de getRoles().

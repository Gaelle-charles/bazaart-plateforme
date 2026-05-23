---
name: feedback_method_exists_placeholder
description: Voters anticipatoires utilisant method_exists() en attendant les entités ForumThread/ForumReply/Live — hack temporaire documenté, à upgrader vers interface typée
metadata:
  type: feedback
---

Les voters ForumVoter et LiveVoter utilisent `method_exists($subject, 'getAuthor')` / `method_exists($subject, 'getHost')` car les entités Forum et Live n'existent pas encore en BDD (prévues semaine 2/3 du planning V1).

Ce pattern est intentionnel et documenté dans les commentaires du code. Il est safe en production car il retourne `false` si la méthode n'existe pas (accès refusé par défaut). Cependant :

- PHPStan niveau 6 tolère ce pattern avec un `@var object $subject` mais perd la vérification de type.
- À upgrader vers une `HasAuthorInterface` / `HasHostInterface` dès que les entités seront créées.

**Why:** Pattern repéré lors de la relecture des Voters V1 (mai 2026). Accepté en V1 pour tenir le planning, mais à ne pas laisser en place en V2.

**How to apply:** À chaque fois que ce pattern apparaît, vérifier que : (1) il est documenté dans un commentaire, (2) il renvoie false par défaut (sens sécurisé), (3) l'upgrade path vers une interface est explicité.

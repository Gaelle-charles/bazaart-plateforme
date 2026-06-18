---
name: project-v1-context
description: Contexte technique et deadline du projet Bazaart V1
metadata:
  type: project
---

Deadline V1 : 15 juin 2026 (date fixe, lancement incubation Mansa).

Stack imposée par le CDC V3 :
- Symfony 7.x, PHP 8.2+
- Hashage password : **bcrypt** (changé depuis argon2id en mars 2026)
- PHPStan niveau 6 obligatoire sur tous nouveaux modules
- Pas de JWT en V1 (Symfony Security classique)
- PostgreSQL 16, Redis 7

Rôles en jeu : ROLE_USER, ROLE_ARTIST, ROLE_STRUCTURE, ROLE_MODERATOR, ROLE_ADMIN.

Container Docker **éteint en dehors des sessions de dev actives** — lors des relectures, vérifier si le daemon Docker est disponible avant de lancer PHPStan. Si Docker est éteint, faire une revue statique manuelle + greps ciblés.

**Anti-pattern retrait de fonctionnalité (ADR-0013, juin 2026)** : après un retrait non destructif, le cache Twig (`var/cache/dev/`) peut conserver des fichiers compilés avec des références aux anciennes routes. Ces caches stale ne cassent rien en production (Symfony régénère à la demande ou après `cache:clear`) mais peuvent induire en erreur lors d'une relecture. Toujours distinguer les hits dans `var/cache/` des hits dans `src/` et `templates/`.

**Why:** Informations stables nécessaires pour calibrer les recommandations de relecture.
**How to apply:** Toujours vérifier bcrypt (pas argon2id), PHPStan niveau 6, pas de JWT en V1.

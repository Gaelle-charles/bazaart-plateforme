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

Container Docker monté sur `/Users/belamour/Downloads/bazaart-plateforme-demo/app` (différent du workspace courant `/Users/belamour/bazaart-plateforme/app`). PHPStan dans le container ne peut pas analyser les fichiers non commités du workspace courant.

**Why:** Informations stables nécessaires pour calibrer les recommandations de relecture.
**How to apply:** Toujours vérifier bcrypt (pas argon2id), PHPStan niveau 6, pas de JWT en V1.

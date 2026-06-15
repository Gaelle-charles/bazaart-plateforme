---
name: feedback-docker-local
description: Docker n'est pas lancé en local — ne pas lancer les agents reviewer/backend pour des checks qui nécessitent les containers
metadata:
  type: feedback
---

Docker n'est PAS lancé en local sur la machine de Gaëlle. Les commandes `docker compose exec ...` échouent avec "service not running".

**Why:** La plateforme tourne uniquement sur le serveur prod (app.bazaart.fr). Le développement local ne passe pas par Docker.

**How to apply:**
- Ne JAMAIS lancer un agent reviewer ou backend qui a besoin de `docker compose exec` pour valider (lint:twig, phpstan, cache:clear) — il va se bloquer.
- Pour les validations, faire une relecture manuelle du code (lire les fichiers) OU demander à Gaëlle de lancer la commande sur le serveur.
- La commande correcte sur le serveur prod : `docker compose -f docker-compose.prod.yml exec platform_app php bin/console <commande>`
- Le service s'appelle `platform_app` dans `docker-compose.prod.yml` (et non `bazaart_platform_app` ni `app`).

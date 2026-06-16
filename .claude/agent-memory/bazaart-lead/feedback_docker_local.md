---
name: feedback-docker-local
description: Docker local — selon les jours peut être éteint ou allumé ; vérifier l'état avant de supposer
metadata:
  type: feedback
---

L'état de Docker en local sur la machine de Gaëlle VARIE. Ne pas supposer — vérifier avec `docker ps` au début.

**Le 2026-06-15, Gaëlle a explicitement lancé Docker en local ("rallume docker").** La stack de dev (`docker-compose.yml`) tournait : containers `bazaart_app`, `bazaart_nginx`, `bazaart_postgres`, `bazaart_redis`, `bazaart_mail`, `bazaart_adminer`. Les commandes `docker compose exec -T app php bin/console ...` ont fonctionné (migrations, phpstan, schema:validate).

**Why:** la plateforme tourne surtout sur le serveur prod, mais Gaëlle peut allumer la stack locale pour valider avant déploiement. L'ancienne note "Docker toujours éteint en local" était devenue fausse.

**How to apply:**
- Vérifier `docker ps` d'abord. Si les containers tournent, on peut valider en local.
- Stack de DEV locale : service = `app` dans `docker-compose.yml`. Ex : `docker compose exec -T app php bin/console <cmd>`.
- Stack PROD (serveur) : service = `platform_app` dans `docker-compose.prod.yml`.
- ⚠️ PHPStan local : augmenter la mémoire (`php -d memory_limit=512M vendor/bin/phpstan ... --memory-limit=512M`), sinon crash "memory limit 128M".
- ⚠️ `DATABASE_URL` n'est PAS dans `.env`/`.env.dev` : il doit être dans `app/.env.local` (gitignoré) pour que les commandes Doctrine ET PHPStan (extension Symfony qui boote le kernel) fonctionnent. Valeur dev : `postgresql://bazaart_user:bazaart_pass@postgres:5432/bazaart?serverVersion=16&charset=utf8`. Voir [[pieges-techniques-projet]].

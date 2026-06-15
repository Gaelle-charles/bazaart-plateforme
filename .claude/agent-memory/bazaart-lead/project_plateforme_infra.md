---
name: project-plateforme-infra
description: Carte technique app.bazaart.fr (plateforme Symfony) et règles de cohabitation critique avec la vitrine sur le même droplet
metadata:
  type: project
---

# Plateforme app.bazaart.fr — carte technique & règles de cohabitation

J'interviens sur la **PLATEFORME app.bazaart.fr** (application Symfony : ressourcerie, forum, formation, scraping). Distincte de la vitrine bazaart.fr.

**Why:** deux sites cohabitent sur le même droplet DigitalOcean ; un faux pas sur l'un casse l'autre (incident du 2026-06-11).
**How to apply:** vérifier l'identité de la stack avant toute commande Docker/nginx/git ; ne jamais agir sur la vitrine.

## Carte technique — PLATEFORME (la mienne)
- **Dépôt :** `Gaelle-charles/bazaart-plateforme.git`, branche `main` (= la plateforme déployée).
- **Dossier serveur :** `/root/bazaart-plateforme` — **Compose :** `/root/bazaart-plateforme/docker-compose.prod.yml`.
- **Container app :** `bazaart_platform_app` (PHP 8.3.31, code à `/var/www/html`).
- **BDD :** `bazaart_platform_postgres`.
- **Copie locale :** `/Users/belamour/bazaart-plateforme` (branche `main`).
- **Service PHP** = `platform_app` (alias DNS unique), `container_name: bazaart_platform_app`. **Ne JAMAIS le renommer `app`** → collision d'alias avec la vitrine = clignotement des deux sites.

## Vitrine bazaart.fr — NE JAMAIS Y TOUCHER
- Dépôt `bazaart.git`, dossier `/root/bazaart2`, containers `bazaart_app` / `bazaart_postgres`.
- nginx **partagé** : `bazaart_nginx` → toute modif nginx peut casser la vitrine.
- `bazaart.fr` est servi par `bazaart_app:9000` (fastcgi_pass `app:9000` dans prod.conf).

## Ancienne vitrine Next.js — SUPPRIMÉE le 2026-06-15
- Dossier `/root/bazaart`, containers `vitrine_front-end` / `vitrine_back-end` / `vitrine_db` (MySQL) / `vitrine_proxy` / `vitrine_certbot`.
- Supprimée car : architecture découplée (Next.js + Symfony API + MySQL) tournant en mode dev sur prod, consommant ~646 Mo de RAM, totalement isolée (réseau `bazaart_default` sans connexion nginx).
- Volume MySQL `bazaart_db_data` toujours présent sur disque (non supprimé). Pour libérer ~350 Mo de disque : `docker volume rm bazaart_db_data`.

## Swap configuré le 2026-06-15
- 1 Go de swap ajouté (`/swapfile`), permanent via `/etc/fstab`.
- Avant : 0 swap, 272 Mo RAM disponible (critique). Après : 1 Go swap + 1,2 Go RAM disponible.

## Règles non négociables
1. **Jamais** merger `main` ↔ `demo` sur `bazaart.git`. Seul flux autorisé : amener du code dans `bazaart-plateforme.git`, jamais dans `main` de `bazaart.git`.
2. Ne pas toucher aux containers `bazaart_app`/`bazaart_postgres`/`vitrine_*` ni à `/root/bazaart2`.
3. Service PHP plateforme = `platform_app` / `bazaart_platform_app` (cf. ci-dessus).
4. **Aucune commande destructive sans accord de Gaëlle** : pas de `down -v`, pas de suppression de volume, pas de `reset --hard`/`clean -fdx`, pas de modif BDD non validée. Diagnostic lecture seule d'abord → plan validé → ensuite push/merge/recréation container/migration.
5. **Sauvegarde avant opération risquée** : `pg_dump` de `bazaart_platform_postgres` + `git bundle`/tag.

## Pièges connus (incident 2026-06-11)
- Conf nginx en **bind-mount de fichier** : après modif → `docker restart bazaart_nginx` (pas seulement `nginx -s reload`).
- Dans `app.bazaart.fr.conf` : `SCRIPT_FILENAME`/`DOCUMENT_ROOT` doivent pointer vers **`/var/www/html/public`** (chemin réel du code dans `bazaart_platform_app`), **PAS** `$realpath_root//var/www/platform/public` (sinon 404 « File not found » sur toutes les URL).
- Diagnostic 404 : `docker exec bazaart_platform_app php bin/console router:match /` dit si le routeur matche ; `docker logs bazaart_platform_app | grep "No route found"` dit si le 404 vient de Symfony ou de PHP-FPM.

## PHPStan
`vendor/bin/phpstan analyse src --level=6 --memory-limit=512M` — objectif 0 erreur hors `phpstan-baseline.neon`.

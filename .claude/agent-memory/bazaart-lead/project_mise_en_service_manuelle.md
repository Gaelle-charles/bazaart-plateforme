---
name: project-mise-en-service-manuelle
description: Sur ce projet, code déployé ≠ fonctionnalité en service — des étapes manuelles post-déploiement sont nécessaires (seeds, clés en BDD, cron)
metadata:
  type: project
---

# « Code déployé » ne veut PAS dire « fonctionnalité en service »

Piège récurrent constaté le **2026-06-12** sur le module scraping : tout le code était écrit, **toutes les migrations appliquées** en prod depuis début juin, mais la fonctionnalité **ne tournait pas** — tables vides, aucun cron, clés LLM absentes.

**Why:** plusieurs réglages vivent **hors du code/migrations** et doivent être déclenchés manuellement après déploiement. Sans ça, le module est inerte mais sans erreur visible.
**How to apply:** avant de déclarer un module « prêt » pour le 15 juin, vérifier en prod (lecture seule) : seeds lancés ? clés API en BDD ? cron en place ? — pas seulement « le code est sur main ».

## Checklist de mise en service (cas du scraping, transposable)
1. **Seeds applicatifs** : `app:seed-settings` (crée les lignes `app_settings`) puis `app:seed-scraping-sources` (10 sources). Idempotents, **sans `--force`** (force écrase les valeurs saisies).
2. **Secrets en BDD, pas en `.env`** : les clés LLM (`mistral_api_key`, etc.) se saisissent dans **`/admin/settings`**. Le champ n'apparaît dans l'UI que si la ligne existe déjà (d'où le seed avant).
3. **Cron sur le host** (pas dans le code) : crontab root appelant `/usr/bin/docker exec bazaart_platform_app php bin/console …` (chemin docker **absolu** obligatoire en cron), logs → `/var/log/bazaart-scraping.log`. Cf. `docs/scraping-cron.md`.
4. **Valider par étapes** : `--dry-run` d'abord (RSS gratuit, LLM facturé mais sans persistance), puis vrai run sur 1-2 sources.

## État scraping au 2026-06-12 (peut évoluer)
En service en prod : 78 opportunités `pending`, provider `mistral`, CNAP désactivé (bruit score 0), cron installé.
**Reste :** bug SSL **Resartis** (`unable to get local issuer certificate` — container ne reconnaît pas le cert ; reco = mettre à jour `ca-certificates` dans l'image, à faire à froid car rebuild sur droplet partagé). Voir [[project-plateforme-infra]].

## Récupération d'un accès admin perdu
Commande `app:promote-admin <email>` donne ROLE_ADMIN. Après promotion, **se reconnecter** (le token de session garde l'ancien rôle sinon → 403). Un `/admin` en **404** = aucun admin / non connecté (masquage volontaire) ; en **403** = connecté sans le rôle.

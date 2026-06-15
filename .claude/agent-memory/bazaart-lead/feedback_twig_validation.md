---
name: feedback-twig-validation
description: lint:twig ne détecte PAS les erreurs d'exécution — toujours vérifier le rendu réel après déploiement d'un template dynamique
metadata:
  type: feedback
---

Pour tout changement de **template Twig dynamique** (boucles sur données BDD, filtres `|date`, accès à des champs potentiellement null), **vérifier le rendu RÉEL après déploiement** : code HTTP + taille de la réponse (`curl -s -o /dev/null -w "%{http_code}"` + `wc -c`). Une page d'erreur prod fait ~1 Ko ; une vraie page plusieurs dizaines de Ko.

**Why:** le 2026-06-14, un déploiement a mis la home en **500 pendant ~2 min**. Cause : `|date('d M.', 'fr')` — `'fr'` était interprété comme **fuseau horaire** (2e argument du filtre `date`), d'où `Unknown timezone (fr)`. `lint:twig` était passé au vert car il valide la **syntaxe**, pas l'**exécution**. Détecté seulement par la vérif post-déploiement (taille 1 Ko au lieu de 118 Ko).

**How to apply:**
- Après déploiement d'un template dynamique : `curl` la page, vérifier `HTTP 200` ET une taille plausible. Si ~1 Ko → page d'erreur, investiguer `docker logs bazaart_platform_app | grep -iE "exception|critical"`.
- Le filtre Twig `|date(format, timezone)` : le 2e argument est un **fuseau**, jamais une langue. Pour du français sans nom de mois → format numérique `d/m/Y`. Pour des noms de mois en FR → `format_date` (Intl) + locale, pas `|date`.
- `lint:twig` et PHPStan = syntaxe/types seulement. Ils ne remplacent pas un test de rendu. Voir [[project-plateforme-infra]] pour le diagnostic 500 (logs Symfony vs PHP-FPM).

---
name: feedback_route_prefix_concatenation
description: Routes avec préfixe de classe — le nom final = préfixe + nom de méthode, pas le nom de méthode seul
metadata:
  type: feedback
---

Dans ResourceController, la classe porte `#[Route('/resources', name: 'app_resource_')]` et chaque méthode a son propre `#[Route('', name: 'index')]` etc.

Le nom de route complet est `app_resource_` + `index` = `app_resource_index`, et `app_resource_` + `alerts` = `app_resource_alerts`, et `app_resource_` + `show` = `app_resource_show`.

Les templates email utilisent `url('app_resource_alerts')`, `url('app_resource_show', ...)`, `url('app_resource_index')` — ces noms sont CORRECTS car ils correspondent bien aux noms concaténés par Symfony.

**Why:** Vérifié lors de la relecture du job d'alertes email (mai 2026). Pattern à garder en tête pour ne pas signaler une fausse alerte sur les noms de routes avec préfixe de classe.

**How to apply:** Avant de signaler un nom de route introuvable dans un template, vérifier si le controller utilise un préfixe de nom (`name: 'prefix_'`) sur la classe.

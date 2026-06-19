---
name: feedback_resource_index_filters_patterns
description: Catalogue ressources — filtres year/country/sort + formulaire alertes — patterns et anti-patterns identifiés — juin 2026
metadata:
  type: feedback
---

## Bons patterns confirmés

- Whitelist sortBy (`['recent', 'deadline']`, `strict: true`) : correct, pas d'injection ORDER BY.
- Filtre `year` : validé par `ctype_digit() + strlen() === 4` avant cast en int : solide.
- Filtre `country` : lu brut, nettoyé par `trim()`, comparaison DQL via paramètre bindé `LOWER(:country)` : pas d'injection SQL.
- CSRF sur POST alertes : `isCsrfTokenValid('resource_alerts', ...)` avant tout traitement.
- Pas d'IDOR sur alertes : `findByUser($user)` — pas d'ID en paramètre URL, l'alerte appartient toujours à `$user` (OneToOne).
- `disciplineIds/typeIds` : chaque valeur castée `(int)` puis vérifiée via `find()` → entité null ignorée : pas d'injection.
- `buildPublishedQueryBuilder` centralisé et partagé entre `findPublished` / `countPublished` : cohérence pagination garantie.
- DBAL natif pour `findAvailableDeadlineYears` / `findAvailableCountries` : SQL sans paramètre utilisateur → pas d'injection (données lues, pas filtrées par input).
- Paginator Doctrine avec `fetchJoinCollection: true` sur le fetch-join ManyToMany disciplines : correct, évite la pagination tronquée.
- PHPStan niveau 6 : 0 erreur.
- Doctrine schema validate : OK (en sync).

## Anti-patterns / points à corriger

### Route /resources/mes-alertes au lieu de /mes-alertes (Avertissement)
Le commentaire PHP dit "chemin absolu commençant par '/' écrase le préfixe". En réalité, Symfony applique quand même le préfixe de classe à tous les paths relatifs **et absolus dans un #[Route] de méthode**. La route effective est `/resources/mes-alertes`, pas `/mes-alertes`. Le bouton dans index.html.twig pointe vers `app_resource_alert_edit` (correct), mais l'URL dans l'email template pointe vers `app_resource_alerts` (la route principale) → cohérence OK. Le commentaire est trompeur mais le comportement fonctionnel est correct.

### `currentYear` affiché sans `|e` dans la meta-ligne de résultats (Suggestion)
Ligne 813 : `{{ currentYear }}` sans `|e`. `$year` est un `int|null` validé par `ctype_digit()` côté PHP, donc pas de risque réel XSS. Mais par cohérence avec `currentCountry|e` sur la ligne suivante, ajouter `|e` serait propre.

### `discipline.icon` et `type.icon` dans alerts.html.twig sans `|e` (Suggestion)
Lignes 571/612 : `{{ discipline.icon ? discipline.icon ~ ' ' : '' }}` — si l'icône est un emoji ou un caractère Unicode stocké en base, Twig l'auto-échappe. Mais si quelqu'un stockait du HTML via l'admin, ce serait rendu encodé (pas injecté). Risque pratique nul, mais incohérent avec le reste.

### `onerror` inline dans index.html.twig (Suggestion)
Ligne 856 : `onerror="this.onerror=null; this.src='...';"` est un gestionnaire JS inline. Compatible CSP `script-src 'self'` uniquement si `unsafe-inline` est autorisé (ou `unsafe-hashes` avec hash précalculé). Aucune CSP n'est encore configurée sur le projet, donc pas bloquant. À noter pour la mise en prod.

### `updatedAt` non initialisé si `new ResourceAlert()` sans `flush()` immédiat (Non-bloquant)
`$this->updatedAt` est un `\DateTimeInterface` non nullable mais initialisé seulement dans `initTimestamps()` (PrePersist). Si quelqu'un appelle `getUpdatedAt()` avant le premier `flush()`, c'est une erreur PHP (propriété non initialisée). En pratique le contrôleur appelle toujours `flush()` avant toute lecture de `updatedAt`. Risque théorique uniquement.

**Why:** Relecture catalogue resources + alertes juin 2026.
**How to apply:** Lors de futures relectures de templates, vérifier systématiquement l'échappement des variables `int` affichées dans les balises HTML attribut (value=, option, meta).

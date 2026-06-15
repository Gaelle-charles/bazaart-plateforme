---
name: feedback-patterns
description: Conventions validées et patterns approuvés dans ce projet
metadata:
  type: feedback
---

**Traits PHP pour factoriser la logique partagée entre DTOs**
Convention approuvée : utiliser un trait (ex: `PasswordStrengthTrait`) pour partager une logique de validation entre plusieurs DTOs readonly. Le trait lit `$this->property` — le DTO hôte doit définir la propriété correspondante.
**Why :** Évite la duplication de code entre RegisterDTO et ResetPasswordDTO (et tout futur DTO avec un champ mot de passe).
**How to apply :** Créer le trait dans `src/DTO/`, le trait lit `$this->password` — bien documenter la dépendance implicite en commentaire.

**RateLimiterFactory naming convention**
`password_reset_limiter` dans framework.yaml → `$passwordResetLimiter` en PHP (snake_case → camelCase). Même pattern que `register_limiter` → `$registerLimiter` dans AuthController.
**Why :** Symfony autowire les RateLimiterFactory par leur nom en camelCase.
**How to apply :** Toujours déclarer la limite dans les deux sections : section principale ET `when@test` (avec limit: 1000 pour ne pas bloquer les tests).

**#[ORM\Index] ne s'utilise pas sur les propriétés**
L'attribut `#[ORM\Index]` Doctrine n'est pas interprété sur les propriétés — uniquement via `#[ORM\Table(indexes: [...])]` sur la classe. Pour des index "hors schema diff", les créer directement dans la migration via `CREATE INDEX`.
**Why :** Évite les faux espoirs — l'attribut est silencieusement ignoré sur une propriété.
**How to apply :** Si un index est hors Doctrine (ex: sur une colonne de token), le créer dans la migration et le documenter sur la propriété.

**PHPStan niveau 6 et boucles foreach sur types hétérogènes**
Ne jamais faire une seule boucle foreach sur un tableau pouvant contenir deux types d'entités distincts (ex: ScrapedResource|Resource). PHPStan signale `instanceof ... alwaysTrue` dans le second `elseif`.
**Why :** PHPStan analyse le type exact de `$items` selon la branche qui l'alimente — si `$items = Resource[]` dans une branche, `$item instanceof Resource` sera always true.
**How to apply :** Diviser en deux méthodes privées typées séparément : `processResources(array $items)` et `processScrapedResources(array $items)`. Plus lisible et safe.

**PHPStan dans le container Docker : .env requis**
PHPStan (via l'extension phpstan-doctrine) boote le kernel Symfony en mode test. Sans un fichier `.env` à la racine du projet, phpstan échoue avec "Unable to read .env". Le fichier `/var/www/html/.env` (= `app/.env` via le volume) doit exister.
**Why :** `tests/bootstrap_phpstan.php` appelle `(new Dotenv())->bootEnv(__DIR__ . '/../.env')` — requis même si les vraies config sont dans `.env.dev` / `.env.local`.
**How to apply :** Maintenir un `app/.env` minimal versionné avec les valeurs non-secrètes (APP_ENV=dev, APP_SECRET non-prod, DATABASE_URL docker). Les secrets réels restent dans `.env.local` non versionné.

**Anti-énumération dans les formulaires d'email**
Ne jamais révéler si un email existe ou non dans les réponses de formulaire. Le service retourne void et ne lève jamais d'exception. Le contrôleur affiche le MÊME message quel que soit le résultat.
**Why :** Empêche l'énumération des adresses email de la plateforme.
**How to apply :** Applicable à : mot de passe oublié, désabonnement, export RGPD.

---
name: feedback-conventions
description: Conventions de code validées pour ce projet Bazaart
metadata:
  type: feedback
---

Conventions confirmées (issues du CLAUDE.md) :

- **Commentaires en français, abondants** — chaque bloc logique doit être commenté
- **PHPStan niveau 6** — obligatoire sur nouveaux modules (mais pas installé dans vendor actuellement, à vérifier)
- **PSR-12** — formatage obligatoire
- **Attributs PHP 8** — jamais d'annotations `/** @ORM\... */`
- **Logique métier dans les Services** — jamais dans les controllers
- **Voters Symfony** — jamais de `if ($user->getRoles()...)` dans les controllers

**Why:** Projet pédagogique pour Gaëlle qui apprend — les commentaires sont sa documentation principale.

**How to apply:** Toujours commenter le "pourquoi" en plus du "comment". Signaler les conventions Symfony utilisées dans les réponses.

**Note importante :** PHPStan n'est pas dans composer.json (require-dev). Ne pas supposer qu'il est disponible. Si besoin, signaler comme open question.

**Slugger :** `symfony/string` (qui fournit SluggerInterface) n'est PAS dans les dépendances directes de `app/composer.json`. La classe existe dans vendor (car dépendance transitive) mais pas autowirable. Utiliser une méthode `slugify()` maison avec `iconv()` (ext-iconv est listée dans composer.json).

**Templates HTML natifs :** Le projet n'utilise pas Symfony Form Component pour les formulaires simples. Formulaires HTML natifs + token CSRF manuel (`csrf_token()` en Twig + `isCsrfTokenValid()` en controller).

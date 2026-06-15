---
name: feedback_street_redesign_patterns
description: Anti-patterns identifiés lors du redesign Street de resource/index, forum/index, article/index, article/show — juin 2026
metadata:
  type: feedback
---

Anti-patterns identifiés lors de la relecture des 4 templates redesignés thème Street (juin 2026) :

**1. namespace() Twig utilisé deux fois dans un même template — BLOQUANT**
`forum/index.html.twig` utilise `{% set hotCount = namespace(value=0) %}` en bas de page (bloc sidebar "Hot du jour", ligne 852) alors que le premier `namespace()` du template (ligne 710) fonctionne. Le lint Twig remonte "Unknown namespace function" sur la *deuxième* occurrence dans le template compilé. En réalité, `namespace()` est disponible dans Twig (Symfony), mais le lint échoue à cause de l'ordre de résolution lors du parsing. La version saine est de **réutiliser un seul objet namespace** créé au début du bloc `{% block content %}`.
**Why:** La duplication d'un objet `namespace()` dans un même template peut provoquer une erreur Twig selon la version et le contexte.
**How to apply:** Signaler en Bloquant. Réutiliser l'objet namespace existant ou créer une variable `{% set hotCount = 0 %}` simple (sans namespace, puisqu'on n'incrémente pas dans une boucle imbriquée différente).

**2. Divergence nom de paramètre URL search/q entre contrôleur et template**
`resource/index.html.twig` utilise `name="search"` dans le formulaire GET, mais `ResourceController::index()` lit `$request->query->get('q')`. La recherche textuelle est donc silencieusement cassée : le champ ne remonte jamais rien.
**Why:** Divergence template/contrôleur classique, sans erreur fatale.
**How to apply:** Signaler en Critique. Aligner soit le template (name="q") soit le contrôleur (get('search')).

**3. resource.deadline est un DateTimeInterface, pas un string**
`resource/index.html.twig` accède à `resource.deadline` comme un texte lisible ("15 juin 2026") mais l'entité `Resource` n'a qu'une seule propriété `$deadline: ?\DateTimeInterface`. Il n'existe pas de propriété `$deadlineDate` distincte. Le template utilise `resource.deadlineDate|date("U")` pour les calculs et `resource.deadline` pour l'affichage — les deux appels échoueront.
**Why:** Le template suppose une architecture dual-propriété (deadline string + deadlineDate objet) qui n'existe pas en entité.
**How to apply:** Signaler en Critique. Soit utiliser `resource.deadline|date("U")` pour le calcul ET `resource.deadline|date("d M Y")` pour l'affichage, soit ajouter un getter `getDeadlineFormatted(): ?string` à l'entité.

**4. article.readingTime et article.category inexistants dans l'entité Article**
`article/index.html.twig` et `article/show.html.twig` accèdent à `article.readingTime` et `article.category`, mais l'entité `Article` n'expose que : id, title, slug, excerpt, content, coverImagePath, author, status, publishedAt, createdAt, updatedAt. Ces deux accès lèveront une erreur Twig en production (Twig strict mode) ou afficheront null silencieusement en dev.
**Why:** Champs prévus dans le design mais pas encore ajoutés à l'entité — décalage design/entité.
**How to apply:** Signaler en Critique (x2). Soit ajouter `readingTime: ?int` et `category: ?string` à l'entité Article + migration, soit supprimer les affichages dans les templates.

**5. upcomingLives dans forum/index.html.twig — variable non transmise, gestion correcte**
Le template vérifie `upcomingLives is defined` avant d'y accéder. Le contrôleur ne la passe pas. C'est intentionnel et documenté. Aucun bug.
**Why:** Pattern correct pour les variables optionnelles futures.

**Ce qui est bien fait :**
- Tous les tokens CSS sont conformes : var(--bg), var(--ink), var(--accent), var(--accent-2), var(--display), var(--body), var(--mono)
- border-radius: 0 partout, aucune ombre, style Street cohérent
- Aucun tiret cadratin (—) dans le code ou le texte
- Aucun |raw sur des entrées utilisateur non contrôlées (seul article.content|raw avec justification ROLE_ADMIN)
- CSRF présent et documenté dans show.html.twig (formulaire suppression article)
- Formulaires GET (filtres resources, forum tabs) sans CSRF — correct car lecture seule
- Héritage layout correct dans les 4 fichiers : extends base_app.html.twig, blocs title/page_title/stylesheets/content/javascripts tous fermés
- is_granted() utilisé correctement (pas getRoles())
- Routes vérifiées : toutes les routes utilisées existent bien dans les contrôleurs
- Pagination native Twig (sans KnpPaginatorBundle) — cohérente avec le contrôleur
- namespace() Twig utilisé correctement pour les compteurs incrémentaux en boucle imbriquée
- Logique hasThreads / hasHot correcte dans forum/index pour éviter les boucles vides
- Meta OG + Twitter Card complets dans article/show.html.twig
- absolute_url() utilisé pour og:image (URL absolue requise pour partage social)

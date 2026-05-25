---
name: feedback_twig_dashboard_patterns
description: Patterns et anti-patterns identifiés dans dashboard/index.html.twig (relecture mai 2026)
metadata:
  type: feedback
---

Anti-patterns et points de vigilance repérés dans dashboard/index.html.twig (relecture 24 mai 2026) :

**1. Double H1 — bug d'accessibilité structurel**
base_app.html.twig rend `{% block page_title %}` dans un `<h1 class="topbar__title">`. Le dashboard définit `{% block page_title %}Tableau de bord{% endblock %}` ET a un deuxième `<h1 class="db-banner__greeting">` dans son bloc content.
→ Deux balises h1 par page. Le h1 de la topbar doit être rétrogradé en h2 (ou le block page_title du dashboard vidé).
**Why:** Pattern récurrent à surveiller dans tous les templates qui ajoutent leur propre h1 visuel.
**How to apply:** Dès qu'un template a un <h1> dans son bloc content ET définit block page_title, signaler Double H1.

**2. Preloads de polices dupliqués**
base_app.html.twig preload déjà archivo-black-400.woff2 et space-grotesk-400.woff2. Le dashboard les redéclare dans son bloc stylesheets. Double requête de preload par page.
**Why:** Inutile, peut générer un warning navigateur.
**How to apply:** Ne pas preloader dans les pages enfants des polices déjà preloadées dans le layout parent.

**3. resourceType.name accédé sans garde sur objet non-null**
Dans l'entité Resource, `resourceType` a JoinColumn(nullable: false) → ne peut pas être null en base. Le template teste `resource.resourceType is not null` avant d'accéder à `.name`. Ce test est défensif mais incohérent avec le mapping — il masque une éventuelle incohérence de données plutôt que de la corriger.
**Why:** Le test superflu est inoffensif mais peut tromper sur la nature du schéma. Si des ressources scrappées arrivent avec resourceType null (incohérence de données), elles seront silencieusement affichées comme "Ressource" au lieu de lever une erreur.
**How to apply:** Signaler comme Suggestion si JoinColumn est nullable: false mais le template teste is not null.

**4. Emoji 📍 dans le template**
La convention CLAUDE.md interdit les emojis sauf si explicitement demandé. L'emoji 📍 est utilisé pour la localisation dans la bannière.
**How to apply:** Signaler en Suggestion. Remplacer par un SVG inline ou caractère texte.

**5. Ordre des filtres post.content|slice(0,90)|e — CORRECT**
Ne pas signaler en fausse alerte : slice AVANT |e est le bon ordre avec autoescape activé. slice coupe le texte brut, |e échappe le résultat. L'ordre inverse couperait une entité HTML en plein milieu.

**6. avatarStyles[loop.index0] avec index hors tableau — silencieux en Twig**
Si `recent_posts` retourne plus de 3 éléments (impossible avec findFeed(3) mais théoriquement), Twig retourne null pour l'index manquant → la classe CSS est "db-avatar" sans modificateur. Comportement graceful, pas d'erreur.

**7. URL de profil affichée avec accents non encodés**
`bazaart.fr/{{ displayName|lower|replace({' ': '-'})|e }}` : les accents (é, à, ç) dans displayName ne sont pas encodés pour une URL. Cosmétique seulement (le span n'est pas un <a href>). À noter pour la V2 si cette URL devient cliquable.

---

### sidebar artiste — partial _partials/sidebar_artiste.html.twig (relecture 25 mai 2026)

**H. Icône SVG dupliquée — "Mes candidatures" et "Articles & Blog" ont le même path d=**
Les deux liens utilisent l'icône "livre ouvert" (mêmes path d= identiques). UX ambigüe, pas de bug fonctionnel.
**How to apply:** Signaler en Suggestion — différencier avec une icône "newspaper" ou similaire pour Articles.

**I. Lien "Profil public" actif sur app_artist_profile_public (profil d'un tiers)**
La condition `starts with 'app_artist_profile_' and != edit and != directory` active aussi sur `app_artist_profile_public` (consultation du profil d'un autre artiste). L'user voit le lien "Profil public" comme actif alors qu'il consulte quelqu'un d'autre.
**How to apply:** Signaler en Avertissement. Corriger avec `in ['app_artist_profile_show']` ou ajouter `!= 'app_artist_profile_public'`.

**J. Duplication CSS sd-wordmark-link dans structure/dashboard.html.twig**
structure/dashboard.html.twig étend base_dashboard.html.twig mais redéfinit `.sd-wordmark-link` dans son propre bloc stylesheets. Inutile car base_dashboard le définit déjà.
**How to apply:** Signaler en Avertissement. Supprimer la redéfinition dans structure/dashboard.

**K. Pages resource/alerts, resource/favorites, resource/submit — sans sidebar artiste**
Ces pages dashboard artiste (soumission, alertes, favoris) étendent encore base_app.html.twig sans sidebar. Hors scope de la PR actuelle mais à traiter.
**How to apply:** Signaler en Avertissement — travail à faire dans une prochaine PR.

**L. cours/* (course/index, course/show, course/lesson) — sans sidebar artiste**
Même situation que K pour les templates formation.

**M. Ce qui est correct dans le partial sidebar_artiste**
- Routes toutes vérifiées et correctes (app_notification_index sans "s" — corrigé)
- `|e` explicite sur initials et email (redondant avec autoescape mais défensif)
- `aria-label`, `aria-hidden` correctement appliqués
- Pas de variable utilisateur dans href= brut (uniquement path())
- `app.user` vérifié avant tout accès dans .sd-user

---

### dashboard structure (relecture 24 mai 2026)

**A. inline style="{{ typeColor }}" avec littéraux Twig — sûr en V1**
Toutes les branches if/elseif assignent des littéraux CSS (jamais `typeName`) dans `typeColor`. Autoescape échappe l'interpolation. Pas de faille exploitable mais pratique fragile à surveiller. Signal : dès qu'une branche concatène une variable utilisateur dans typeColor, signaler SÉCURITÉ.

**B. `active_count` surcompte silencieux si enum étendu**
Si un case est ajouté à ResourceStatus sans mise à jour du template, les nouvelles valeurs ne sont pas soustraites du total. Comportement graceful mais trompeur.

**C. Doublon du bloc statut desktop/mobile — anti-pattern récurrent**
Signaler systématiquement comme AVERTISSEMENT quand le même if/elseif de statut est copié. Suggérer une macro Twig.

**D. Double H1 correctement évité** — utilise `<p>` pour le nom de la structure, `<h2>` pour "Mes opportunités". Template de référence pour les futurs dashboards.

**E. Preloads polices absents** — commentaire explicite, bug artiste évité.

**F. Token --ok sur KPI "Refusées"** — incohérence sémantique : couleur succès sur une métrique d'échec.

**Ce qui est correct dans ce template :**
- Autoescape Twig actif → {{ initials }}, {{ notifCount }}, etc. auto-échappés sans |e explicite
- |e systématique sur tous les contenus utilisateur explicitement rendus (displayName, location, title, post.content)
- completenessPercent calculé en PHP (entier 0-100), safe dans style= inline
- is_granted() utilisé correctement (jamais getRoles())
- user.artistProfile is not null vérifié avant tout accès sur la relation nullable
- role="progressbar" avec aria-valuenow/min/max présent
- SVG décoratifs avec aria-hidden="true"
- Tous les liens db-quicklink ont title= explicite
- Aucun |raw sur données utilisateur
- TODO V2 clairement marqués en commentaires Twig
- Toutes les routes référencées existent (vérifiées par grep des attributs Route)
- post.author nullable: false → accès .email sans guard safe
- Conformité périmètre V1 respectée

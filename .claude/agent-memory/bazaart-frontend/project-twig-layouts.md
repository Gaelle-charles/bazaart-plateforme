---
name: project-twig-layouts
description: Structure des layouts Twig existants — base.html.twig et base_app.html.twig, tokens CSS intégrés en mai 2026
metadata:
  type: project
---

Structure des deux layouts Twig racines au 23 mai 2026.

**Why:** Comprendre la hiérarchie Twig avant toute modification de template.

**How to apply:** Toujours vérifier si un bloc Twig existe avant d'en créer un nouveau. Ne jamais mettre de logique métier dans les templates.

---

## `app/templates/base.html.twig`

Layout racine. Contient :
- `<head>` avec meta, Google Fonts (Playfair Display + Inter — ancien design, à migrer)
- Lien vers `css/design-tokens.css` (ajouté mai 2026, avant tous les autres styles)
- Variables CSS `:root` pour l'ancien design (--color-green, --color-red, etc.)
- Reset CSS + composants réutilisables (btn, card, form-input, badge, flash, divider)
- Blocs Twig : `title`, `stylesheets`, `body`, `javascripts`

**Important :** le `<style>` inline de base.html.twig contient encore l'ancien token system (--color-green, --color-red, etc.). Ces variables COEXISTENT avec les nouveaux tokens de design-tokens.css. La migration complète se fera page par page.

---

## `app/templates/base_app.html.twig`

Layout pour les pages de l'application connectée. Extends `base.html.twig`.

Structure HTML :
```
.app-layout (flex)
  ├── aside.sidebar (240px, fixed, vert forêt)
  │   ├── .sidebar__logo
  │   ├── nav.sidebar__nav (liens avec .sidebar__link--active)
  │   └── .sidebar__user (avatar + nom + rôle + déconnexion)
  └── .app-main (margin-left: 240px)
      ├── .topbar (56px sticky, titre de page)
      └── .app-content (padding 28px, flashes + contenu)
```

Blocs Twig disponibles pour les pages enfants :
- `page_title` → titre dans la topbar
- `content` → contenu principal
- `stylesheets` (hérite de base)
- `javascripts` (hérite de base, contient le polling notifications)

Fonctionnalités spéciales :
- Badge notifications en temps réel (polling JS toutes les 60s via `/api/notifications/unread-count`)
- Liens conditionnels selon les rôles : ROLE_STRUCTURE et ROLE_ADMIN ont des sections supplémentaires
- Initiales avatar générées depuis l'email Twig-side

---

## Templates resource/ — conventions appliquées (mai 2026)

Les 6 templates de la section Ressources ont été réécrits au thème Street en mai 2026.
Conventions retenues et pièges à connaître :

- **Grille de cartes** : `border: 1px solid var(--ink)` sur le conteneur, les cartes ont `border-right` et `border-bottom` — les bordures sont partagées (pas de gap).
- **Pagination KnpPaginatorBundle** : on encapsule `knp_pagination_render()` dans `.pagination-wrap` et on cible `.pagination .page-link` pour styler. Vérifier si `resources.pageCount` existe avant d'afficher.
- **Enum ResourceStatus** : accès via `resource.status.value` (string backed) pour les classes CSS. Les helpers `isPending()`, `isPublished()`, `isRejected()` sont définis sur l'entité.
- **Formulaire favoris** : conserver TOUS les attributs `data-resource-id`, `data-token`, `data-favorited`, `data-count` — indispensables pour Stimulus future. Fallback POST fonctionne sans JS.
- **Radio buttons fréquence alertes** : listés en dur (immediate/daily/weekly) car l'enum PHP n'est pas directement accessible en Twig sans extension Twig personnalisée.
- **Coexistence tokens** : `base.html.twig` contient encore `--color-green`, `--color-red`, etc. Ces anciens tokens sont encore utilisés par `base_app.html.twig` (sidebar). Ne pas supprimer. Les pages ressources n'utilisent QUE les nouveaux tokens (`var(--accent)`, `var(--ink)`, etc.).

## Piège récurrent : apostrophes et arobase dans les attributs Twig form_row()

Twig utilise les guillemets simples `'` pour délimiter les chaînes littérales dans les hash `{ key: 'value' }`.
Toute apostrophe française (`l'annuaire`, `qu'il`) ou arobase (`contact@domain.fr`) à l'intérieur d'une chaîne guillemet simple produit une erreur de syntaxe.

**Règle :** dès qu'une chaîne `help:`, `label:` ou `placeholder:` contient une apostrophe ou un `@`, utiliser les guillemets doubles :
```twig
{# Incorrect — l'apostrophe casse le guillemet simple #}
help: 'Cette description sera visible dans l'annuaire...'

{# Correct #}
help: "Cette description sera visible dans l'annuaire..."
```
Toujours lancer `php bin/console lint:twig` après chaque écriture de template.

---

## `app/templates/vitrine/index.html.twig` — Page d'accueil publique (réécrite mai 2026)

Réécriture complète en mai 2026 selon le thème Editorial Cream.

**Architecture :** extends `base.html.twig`, blocs `title` + `stylesheets` (avec `{{ parent() }}`) + `body` + `javascripts`.

**Activation du thème :** `data-theme="editorial"` sur le wrapper `<div class="vitrine-root">` (pas sur `<html>` car base.html.twig contrôle cet élément).

**Ce qui a changé par rapport à l'ancienne version :**
- Suppression Google Fonts (Playfair Display, Bebas Neue, Inter)
- Suppression de la référence à `vitrine.css` et `vitrine.js`
- Suppression du bloc `<!DOCTYPE html>` complet (la page étendait mal le layout)
- Tout le CSS est inline dans `{% block stylesheets %}` — classes préfixées `.v-` pour éviter les collisions
- Menu mobile : checkbox trick CSS pur (pas Stimulus), fermé par JS vanilla minimal
- Contenu statique, zéro variable du contrôleur

**Sections :**
1. Navbar sticky `.v-nav` (logo SVG + liens + CTA login/register)
2. Hero `.v-hero` (visuel décoratif CSS pur, titre highlight `--lemon`)
3. Stats `.v-stats` (fond `--fern`, 4 chiffres en `--lemon`)
4. Offre `.v-offer` (3 cartes : Ressourcerie, Communauté, Formation)
5. CTA `.v-cta-section` (fond `--fern`, bouton `--lemon`)
6. Footer `.v-footer` (fond `--footer-bg: #0A1410`)

**Pièges à connaître :**
- `data-theme="editorial"` (et non "cream") — vérifier dans design-tokens.css avant de l'utiliser
- Les tokens `--display` et `--body` du thème Editorial valent respectivement Fraunces et DM Sans (≠ `--font-heading`/`--font-body` qui sont les anciens tokens du thème Street)
- Les routes `/confidentialite`, `/cgu`, `/mentions-legales` n'existent pas encore — liens en href dur dans le footer. BACK : à créer via StaticController.

---

## Templates dashboard/, messaging/, notifications/ — réécrits thème Street (23 mai 2026)

Les 4 templates suivants ont été convertis au thème Street en mai 2026 :
`dashboard/index.html.twig`, `messaging/index.html.twig`, `messaging/show.html.twig`, `notifications/index.html.twig`.

**Conventions appliquées :**
- Hero bande sombre (`background: var(--ink)`) avec greeting en Archivo Black (clamp 42–96px) et badges de rôles en chips Street.
- Bande stats fond `var(--accent)` (lime), grille 3 colonnes séparées par `border-right: 1px solid var(--ink)`.
- Grille de widgets : `border: 1px solid var(--ink)` sur le conteneur, séparation par `border-right` et `border-top` (pas de gap, pas de shadow — règle Street).
- Avatars : **carrés** (`border-radius: 0`) en thème Street — jamais de cercles (qui sont réservés au thème Editorial).
- Bulles de chat : `border-radius: 0` (mine : fond `--accent` + border `--ink` ; l'autre : fond `#fff` + border `--ink`).
- Pastilles non-lues : carrés orange (`var(--accent-2)`) en Street (pas de pilule ronde).
- Formulaire envoi message : CSRF nommé `messaging_send_{conversationId}` — conservé à l'identique.
- `unread_notifications_count()` : fonction Twig Extension déjà disponible — utilisée dans le dashboard pour la bande stats et le widget notif (évite de l'injecter depuis chaque contrôleur).

**Variables contrôleur DashboardController :**
`user`, `recent_resources`, `recent_posts`, `submissions` — PAS de `recentResources` ni `unreadNotificationsCount`.

**Pièges :**
- `block: 'end'` dans `scrollIntoView` : l'option `block` de l'API Web est un string valide (pas un conflit avec `{% block %}`).
- La `margin: -28px -28px 0` sur `.db-hero` et `.db-stats` est nécessaire pour contrecarrer le `padding: 28px` de `.app-content` et coller les bandes aux bords.

---

## Templates artist_profile/ et post/ — réécrits thème Street (23 mai 2026)

Les 4 templates suivants ont été convertis au thème Street :
`artist_profile/show.html.twig`, `artist_profile/edit.html.twig`, `artist_profile/directory.html.twig`, `post/feed.html.twig`.

**Préfixes CSS :**
- `.ap-` — artist-profile (show + edit)
- `.dir-` — directory
- `.feed-` — fil d'actualité

**Variables des contrôleurs (vérifiées dans le code) :**
- `show.html.twig` : `profile` (ArtistProfile) — pas de `posts`, pas de `isOwnProfile`. Test ownership : `app.user == profile.user`.
- `edit.html.twig` : `profile` (ArtistProfile|null) — formulaire HTML natif (pas Symfony Form Component), lecture via `$request->request->all()`.
- `directory.html.twig` : `profiles` (ArtistProfile[]) — pas de filtre discipline côté back en V1.
- `feed.html.twig` : `posts`, `page`, `totalPages` — pas de `postForm` ni sidebar dynamique.

**Routes artiste vérifiées :**
- `app_artist_profile_show` → `/profile/artist` (profil personnel)
- `app_artist_profile_edit` → `/profile/artist/edit`
- `app_artist_profile_directory` → `/profile/artist/directory`
- `app_artist_profile_public` → `/profile/artist/{id}` (paramètre `id`, pas `username`)

**Routes feed vérifiées :**
- `app_post_feed` → `/community`
- `app_post_new` → `/community/post/new` (POST)
- `app_post_comment` → `/community/post/{id}/comment` (POST)
- `app_post_comment_delete` → `/community/comment/{id}/delete` (POST) — note : paramètre `id` = ID du commentaire, pas du post
- `app_post_delete` → `/community/post/{id}/delete` (POST)
- `app_post_like` → `/community/post/{id}/like` (POST, JSON response)

**Conventions spécifiques :**
- Grille directory : technique bordures partagées (container avec border-top + border-left, cartes avec border-right + border-bottom) — pas de gap, zéro doublement de bordures.
- Avatars artiste : **carrés** (border-radius: 0) fond `--accent` (lime), 2 premières lettres en `--mono`.
- Avatars posts/commentaires : carrés fond `--ink`, texte `--accent` (lime sur noir).
- Likes AJAX : conserver data-post-id, data-token, data-liked sur `.feed-btn-like` + aria-pressed pour l'accessibilité.
- `nl2br` obligatoire sur `profile.bio` et `post.content` / `comment.content` — jamais `|raw`.
- Messagerie `/community/post/{id}/like` : URL en dur dans le JS (cohérent avec le template original).

**Piège :** la route messagerie `app_messaging_new` n'existe pas encore en V1. Le bouton "Envoyer un message" dans show.html.twig est commenté (href="#" + aria-disabled).

---

## État de la tokenisation (mai 2026)

Valeurs converties en tokens dans base_app.html.twig :
- `#1c3a2f` → `var(--color-green)` (sidebar bg)
- `#C8503A` → `var(--color-red)` (badge notifs, lien actif)
- `white` → `var(--color-white)` (textes sur fond sombre)
- `#f0c040` → `var(--color-yellow, #f0c040)` (lien admin — token ajouté)
- `rgba(255,255,255,0.06)` → conservé (couleur alpha difficile à tokeniser)
- `#e2eae7` → conservé (valeur spécifique sidebar, pas dans tokens actuels)

Reste à tokeniser lors de la refonte complète : la valeur `#e2eae7` (couleur des liens sidebar non actifs).

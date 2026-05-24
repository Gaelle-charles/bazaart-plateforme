---
name: project-auth-templates
description: Architecture et conventions des templates auth/login et auth/register — thème Street, split-screen 50/50, variables Symfony Security à respecter
metadata:
  type: project
---

## Templates auth réécrits en thème Street (mai 2026)

Fichiers : `app/templates/auth/login.html.twig` et `app/templates/auth/register.html.twig`

### Layout commun
- Split-screen 50/50 (`display:flex`, `height:100vh`)
- Colonne gauche : `background:var(--ink)` (#0D0D0D), `color:var(--bg)`, décorative (`aria-hidden="true"`)
- Colonne droite : `background:var(--bg)` (#F2EFE6), formulaire centré max-width 420px
- Mobile (<768px) : colonnes empilées, colonne gauche compactée, chips/bullets cachées

### Champs Symfony Security — à ne jamais renommer
- `name="_username"` → identifiant email (login uniquement)
- `name="_password"` → mot de passe (login uniquement)
- `name="_csrf_token"` avec `value="{{ csrf_token('authenticate') }}"` → login
- `form action="{{ path('app_login') }}"` → Symfony Security intercepte ce POST

### Champs register
- `name="profile_type"` (caché, géré par JS : 'artiste' | 'organisation')
- `name="display_name"`, `name="email"`, `name="password"`
- `name="_csrf_token"` avec `value="{{ csrf_token('registration') }}"` — **inclus mais pas encore validé côté back**

### Contrôleur AuthController (vérifié)
- Injecte `$error` (string|null) et `$last_username` pour login
- Injecte `$error` (string|null) direct pour register — **pas de flash messages d'erreur, variable directe**
- Validation password actuelle : 8 caractères (CDC V3 prévoit 10 + 1maj + 1chiffre — delta à corriger)

### Points ouverts pour symfony-backend
1. CSRF token non validé dans `AuthController::register()` — sécurité à corriger avant le 15 juin
2. Validation password : 8 chars vs 10 chars (CDC). `RegisterDTO::isPasswordStrong()` à mettre à jour
3. Routes `/cgu` et `/confidentialite` inexistantes — utilisées en URL statique dans register
4. Route `app_forgot_password` inexistante — lien commenté dans login

### CSS — conventions spécifiques
- Préfixe `.auth-` sur toutes les classes du template
- Bouton primaire : `background:var(--accent)` lime, `border:2px solid var(--ink)`, hover inverse
- Bouton Google : `background:white`, `border:2px solid var(--ink)`, hover box-shadow offset
- Inputs : `border:2px solid var(--ink)`, focus `outline:2px solid var(--accent)`
- Aucun `border-radius` sur inputs/boutons (`var(--radius-none) = 0`)
- Police titres : `var(--display)` = Archivo Black
- Police corps : `var(--body)` = Space Grotesk
- Police labels/chips : `var(--mono)` = JetBrains Mono

**Why:** Harmonisation visuelle des pages publiques d'auth avec le thème Street de l'app connectée.
**How to apply:** Toujours vérifier ces points avant de modifier les templates auth. Ne jamais changer les `name=` des champs Symfony Security.

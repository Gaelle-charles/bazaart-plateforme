# ADR-0037 — Espace projets : outil de gestion de projet interne dans l'admin

- **Date** : 2026-09-24
- **Statut** : proposé
- **Décidé par** : Gaëlle

## Contexte

L'équipe Bazaart (3 personnes : Mllebelamour@gmail.com, zahibowendie@gmail.com,
hello@gaellecode.fr, qui remplace g.charlesbel@gmail.com depuis le 24/09/2026) mène de nombreux projets en parallèle (événements, formations
Studio, candidatures du Lab, communication), chacun avec beaucoup de tâches. Il manquait
un outil de suivi commun : qui fait quoi, pour quand, où en est chaque projet, avec un
espace de notes partagé où l'on sait qui a écrit quoi, et un accès aux documents rangés
dans le Google Drive de l'équipe (reinesdestempsmodernes@gmail.com).

Demande exprimée : un onglet dans le dashboard admin, des filtres, plusieurs vues
(calendrier, par personne, par priorité…), un Kanban, un espace de notes signées, des
étapes d'onboarding, et la possibilité de joindre des fichiers ou dossiers du Drive aux
projets et aux tâches.

NB : c'est un outil **interne** (back-office), sans impact sur le périmètre public V1/V2
du cahier des charges.

## Options envisagées

1. **Outil externe (Notion, Trello, Asana…)** : rien à développer, mais un outil de plus,
   des comptes à gérer, des abonnements, et aucun lien avec les données de la plateforme.
2. **Module intégré à l'admin Symfony** (retenu) : un seul endroit, design Street,
   données chez nous, extensible (ex. lier plus tard une tâche à une formation ou un live).
3. Pour le Drive :
   - **Google Picker côté navigateur** : chaque membre doit être connectée au compte
     Google de l'équipe dans son navigateur. Peu pratique à trois.
   - **Compte de service** : aucun consentement, mais ne voit que les dossiers partagés
     avec lui et **ne peut pas téléverser** dans un Drive personnel (pas de quota).
   - **OAuth hors-ligne du compte de l'équipe, appels côté serveur** (retenu) : une seule
     connexion pour toute l'équipe, parcours complet du Drive, téléversement possible, le
     jeton ne quitte jamais le serveur et la CSP (`connect-src 'self'`) reste inchangée.

## Décision

Module « Espace projets » sous `/admin/projets` (routes `app_admin_pm_*`) :

- **Accès** : nouveau rôle `ROLE_PROJECT`, **non hérité** par `ROLE_ADMIN` (les notes
  internes restent limitées aux personnes désignées). Règle `access_control`
  `^/admin/projets` placée AVANT `^/admin`, plus `ProjectVoter::ACCESS` sur les
  contrôleurs. Une membre non admin ne voit que cette section de la sidebar.
  Attribution : migration (3 emails de départ, idempotente) + commande `app:projets:acces`
  (utilisée pour remplacer g.charlesbel@gmail.com par hello@gaellecode.fr).
- **Modèle** : `Project`, `ProjectTask` (assignation multiple, étiquettes, checklist,
  commentaires, pièces jointes), `ProjectNote` (mur signé), `ProjectActivity` (journal),
  `ProjectMemberProfile` (préférences et onboarding), `ProjectDriveConnection`.
- **Vues des tâches** (filtres communs dans l'URL) : Kanban, Liste triable + export
  tableur, Calendrier mensuel, Par personne, Par priorité ; Chronologie des projets.
  Glisser-déposer maison (pointer events) : souris et appui long au doigt.
- **Notes** : signées (autrice + date + « modifiée »), épinglables par toutes, modifiables
  uniquement par l'autrice (`ProjectVoter::NOTE_EDIT`).
- **Onboarding** : visite guidée (6 écrans) à la première visite + checklist « Bien
  démarrer » cochée automatiquement d'après les actions réelles.
- **Force de proposition** : modèles de projets (événement, formation, candidature,
  campagne) planifiés à rebours depuis l'échéance, emails (assignation, commentaire,
  récap quotidien `app:projets:rappels`), raccourci clavier N, fuseau de l'équipe
  (`PROJECT_TIMEZONE`, défaut America/Guadeloupe).
- **Drive** : OAuth 2.0 hors-ligne, scope `drive`, réutilise l'application OAuth
  Google existante (`GOOGLE_CLIENT_ID/SECRET`). Le compte connecté est **vérifié**
  (`PROJECT_DRIVE_ACCOUNT`) ; refresh token **chiffré** (libsodium, clé dérivée de
  `APP_SECRET`) dans une table dédiée (pas dans `app_settings`, dont la page admin
  permet d'éditer toutes les clés). Seules des références (ID, nom, lien) sont stockées.

## Conséquences

- 12 nouvelles tables (`projects`, `project_*`), migration `Version20260924124344`.
- Configuration Google Cloud à faire une fois (URI de redirection, API Drive, écran de
  consentement « en production ») : procédure dans `docs/espace-projets.md`.
- Si `APP_SECRET` change, le jeton Drive devient illisible : il suffit de reconnecter.
- Scope `drive` = scope « restreint » chez Google : écran « application non validée »
  lors de la connexion (normal pour un usage interne, < 100 utilisatrices).
- Téléversement limité à 10 Mo (PHP `upload_max_filesize` relevé dans le Dockerfile,
  aligné sur Nginx). Les fichiers plus lourds se déposent dans Drive puis se joignent.
- Cron à ajouter pour le récap quotidien (cf. `docs/scraping-cron.md`).
- `CLAUDE.md` n'est pas modifié : à mettre à jour si Gaëlle valide cet ADR (rôle
  `ROLE_PROJECT` à ajouter à la section 7).

## Ajustements après mise en service (24/09/2026)

Retours de l'équipe après les premiers essais :

- **Prénom et nom** modifiables dans « Équipe & réglages » : ce sont ceux du compte
  (`users.first_name` / `last_name`), donc aussi utilisés ailleurs sur la plateforme et
  dans les emails. Nouvelle étape de checklist « Indiquer mon prénom » (7 étapes).
- **Retour vers l'Espace projets** depuis le site : lien dans le menu du compte, le menu
  mobile et les barres latérales « Mon espace », affiché seulement pour `ROLE_PROJECT`.
- **Import CSV** des tâches suivies jusque-là dans Google Sheets (`ProjectTaskImporter`) :
  CSV plutôt que .xlsx pour ne pas ajouter de dépendance (Google Sheets et Excel exportent
  tous deux en CSV). Aperçu obligatoire avant enregistrement (fichier gardé en session,
  vérifié par empreinte SHA-256), en-têtes reconnus sans accents ni casse, doublons
  ignorés (même titre dans le même projet), aucune notification, une seule ligne
  d'activité (`tasks_imported`), liens http(s) seulement, 1 Mo et 500 tâches maximum.
- **Interface allégée** (« trop de couleurs, trop d'infos ») : statuts, colonnes et
  avatars neutres (le réglage « Ma couleur » disparaît), rouge réservé au retard et à
  l'urgence, couleur de projet limitée à sa pastille, priorités « Normale / Basse »
  masquées sur les cartes. Vue d'ensemble : 5 tâches par groupe, projets en lignes,
  étapes faites repliées, plus de charge de l'équipe (vue « Par personne » et « Équipe »).
  Onglets internes masqués sur ordinateur (doublon de la barre latérale).
  Débordement horizontal corrigé sur mobile (libellés masqués positionnés hors du
  Kanban, grilles `minmax(0, 1fr)`).

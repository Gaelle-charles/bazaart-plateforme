# Espace projets : gestion de projet interne de l'équipe

Outil de suivi des projets et des tâches de l'équipe Bazaart, intégré au dashboard admin
de **app.bazaart.fr** sous **`/admin/projets`**. Décision d'architecture : ADR-0037.

Accès réservé aux membres de l'équipe projets (rôle `ROLE_PROJECT`) :
Mllebelamour@gmail.com, zahibowendie@gmail.com, hello@gaellecode.fr.

---

## 1. Mise en service (à faire une fois, après le déploiement)

### 1.1 Migration et accès des 3 membres

Le déploiement (`deploy.sh`) applique la migration `Version20260924124344`, qui :

- crée les tables du module ;
- donne l'accès (`ROLE_PROJECT`) aux comptes **déjà existants** des 3 adresses de départ
  (Mllebelamour@gmail.com, zahibowendie@gmail.com et g.charlesbel@gmail.com) ;
- crée quelques étiquettes de départ (Communication, Budget, Partenaires…).

Pour vérifier qui a accès, ou créer les comptes manquants (sur le serveur) :

```bash
# Liste des membres
docker compose --env-file .env.local -f docker-compose.prod.yml exec -T platform_app \
  php bin/console app:projets:acces

# Crée les comptes absents et donne l'accès
docker compose --env-file .env.local -f docker-compose.prod.yml exec -T platform_app \
  php bin/console app:projets:acces --creer Mllebelamour@gmail.com zahibowendie@gmail.com hello@gaellecode.fr

# Retirer l'accès à quelqu'un
... php bin/console app:projets:acces --retirer adresse@exemple.com
```

> **Changement du 24/09/2026** : l'accès de g.charlesbel@gmail.com est remplacé par
> **hello@gaellecode.fr**. La migration, déjà appliquée, n'est pas modifiée : l'échange se
> fait avec la commande (`--creer hello@gaellecode.fr`, puis `--retirer g.charlesbel@gmail.com`).

Un compte créé par la commande reçoit un email « choisir mon mot de passe » (lien valable
1 heure, relançable ensuite via « Mot de passe oublié »). Il n'a pas de prénom : l'outil
affiche alors le début de l'email (« Hello ») jusqu'à ce que la personne indique son
prénom dans **Équipe & réglages > Mes préférences** (étape de la checklist « Bien démarrer »). Pour les adresses Gmail,
**« Se connecter avec Google » fonctionne aussi directement** ; pour hello@gaellecode.fr,
seulement si cette adresse est rattachée à un compte Google. Après connexion, une membre qui n'est pas admin arrive directement sur
l'Espace projets.

> `ROLE_ADMIN` ne donne PAS accès à l'Espace projets : c'est voulu (les notes internes
> de l'équipe ne sont visibles que par les personnes ajoutées).

### 1.2 Google Drive (reinesdestempsmodernes@gmail.com)

L'outil réutilise l'application OAuth Google déjà configurée pour « Se connecter avec
Google » (variables `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`). Dans la
**console Google Cloud**, sur le projet qui contient cet identifiant OAuth :

1. **API et services > Bibliothèque** : activer **Google Drive API**.
2. **API et services > Identifiants** > l'ID client OAuth existant > **URI de redirection
   autorisés** : ajouter exactement
   `https://app.bazaart.fr/admin/projets/drive/callback`
3. **Écran de consentement OAuth** :
   - ajouter le champ d'application `https://www.googleapis.com/auth/drive` ;
   - **statut de publication : « En production »**. En mode « Test », Google fait
     expirer l'autorisation au bout de 7 jours (il faudrait reconnecter chaque semaine).
     Si vous restez en mode Test, ajoutez au moins reinesdestempsmodernes@gmail.com
     dans « Utilisateurs test ».
4. Dans l'outil : **Espace projets > Drive > Connecter le Drive**, choisir le compte
   reinesdestempsmodernes@gmail.com. Google affiche « application non validée » (normal
   pour un outil interne) : *Paramètres avancés* puis *Accéder à BazaArt*, et autoriser.

Une seule connexion suffit pour toute l'équipe. Tout autre compte Google est refusé
(l'autorisation est alors immédiatement révoquée). Pour changer de Drive un jour :
variable `PROJECT_DRIVE_ACCOUNT` dans `.env.local`.

### 1.3 Récap quotidien par email (optionnel mais conseillé)

Ajouter au cron du serveur (cf. `docs/scraping-cron.md`) :

```cron
0 11 * * 1-5 /usr/bin/docker exec bazaart_platform_app php bin/console app:projets:rappels --env=prod >> /var/log/bazaart-projets.log 2>&1
```

11h UTC = 7h en Guadeloupe. Chaque membre reçoit ses tâches en retard, du jour et des
3 prochains jours (rien si sa liste est vide). Test sans envoi : `--dry-run`.

### 1.4 Variables facultatives (`.env.local`)

| Variable | Défaut | Rôle |
|---|---|---|
| `PROJECT_DRIVE_ACCOUNT` | `reinesdestempsmodernes@gmail.com` | Seul compte Google accepté pour le Drive |
| `PROJECT_TIMEZONE` | `America/Guadeloupe` | Fuseau de l'équipe (« aujourd'hui », heures affichées) |

Le Dockerfile PHP autorise désormais des envois de 10 Mo (`uploads.ini`), aligné sur
Nginx : le conteneur est reconstruit automatiquement par `deploy.sh`.

---

## 2. Utilisation (pour l'équipe)

| Onglet | Ce qu'on y trouve |
|---|---|
| **Vue d'ensemble** | Mes tâches par urgence (en retard, aujourd'hui, semaine…), projets en cours, notes épinglées, charge de l'équipe, activité récente, checklist « Bien démarrer » |
| **Tâches** | 5 vues : **Kanban**, **Liste** (triable, export tableur), **Calendrier**, **Par personne**, **Par priorité** |
| **Projets** | Cartes avec avancement, filtre par statut, **Chronologie** sur 6 mois |
| **Notes d'équipe** | Le mur : notes signées, colorées, épinglables, liées ou non à un projet |
| **Drive** | Parcourir / rechercher dans le Drive de l'équipe |
| **Équipe & réglages** | Membres et charge, mon prénom et mon nom, ma couleur, mes emails, étiquettes, connexion Drive |

- **Filtres** communs à toutes les vues (projet, personne, priorité, statut, étiquette,
  échéance, recherche) + raccourcis *Mes tâches*, *En retard*, *Urgentes*,
  *Cette semaine*, *Non assignées*. Ils sont dans l'URL : une vue filtrée peut se
  mettre en favori.
- **Glisser-déposer** : Kanban (statut + ordre), Par personne (réassigner), Par
  priorité, Calendrier (replanifier ; le bac « Sans échéance » permet de dater une
  tâche). Sur téléphone : **appui long** sur la carte, puis glisser.
- **Raccourci N** : nouvelle tâche depuis n'importe quelle page du module.
- **Revenir à l'Espace projets** depuis le reste du site : menu du compte (avatar en
  haut à droite) ou menu mobile > **Espace projets**, et lien dans la barre latérale de
  « Mon espace ». Visible seulement pour l'équipe.
- **Importer des tâches** depuis un tableur (*Tâches > Importer*) : dans Google Sheets,
  *Fichier > Télécharger > CSV* (onglet des tâches), puis envoyer le fichier. Un aperçu
  montre ce qui sera créé (projets retrouvés par leur nom ou créés, personnes retrouvées
  par prénom ou email, dates, sous-tâches, liens) avant de confirmer. Réimporter le même
  fichier ne crée pas de doublon ; aucun email n'est envoyé. Un modèle à remplir est
  téléchargeable sur la page.
- **Modèles de projet** : Événement, Formation (Studio), Candidature / appel à projets,
  Campagne de communication. Les tâches sont planifiées à rebours depuis l'échéance du
  projet et confiées à la personne responsable, qui les répartit ensuite.
- **Notes et commentaires signés** : autrice, date et mention « modifiée » toujours
  visibles. Seule l'autrice peut modifier ou supprimer ; tout le monde peut épingler.
- **Drive** : sur une tâche ou un projet, « Joindre depuis le Drive » (fichiers ou
  dossiers, plusieurs à la fois), « Téléverser un fichier » (rangé dans le dossier Drive
  du projet, 10 Mo max) ou « Ajouter un lien ». Retirer une pièce jointe ne supprime rien
  dans le Drive. Un projet peut avoir son **dossier Drive** (créé automatiquement à la
  création du projet si la case est cochée).
- **Onboarding** : visite guidée à la première visite (relançable depuis la vue
  d'ensemble ou Équipe & réglages) + checklist de 7 étapes qui se cochent seules
  (dont « Indiquer mon prénom »).
- **Emails** : quand on m'assigne une tâche, quand on commente une tâche qui me concerne,
  récap quotidien. Désactivables dans *Équipe & réglages*.

---

## 3. Côté technique (repères pour le code)

```
src/Controller/Project/        AdminProject*Controller (fins, dont l'import) + ProjectControllerTrait
src/Service/Project/           logique métier (ProjectService, ProjectTaskService,
                               ProjectNoteService, ProjectAttachmentService,
                               GoogleDriveService, TokenCipher, ProjectClock,
                               ProjectCalendarBuilder, ProjectOnboardingService,
                               ProjectNotifier, ProjectTemplateCatalog,
                               ProjectTaskImporter…)
src/DTO/Project/               ProjectTaskFilter, ProjectData, ProjectTaskData, ProjectNoteData,
                               ProjectImportReport, ProjectImportRow
src/Entity/Project*.php        entités (tables projects / project_*)
src/Security/Voter/ProjectVoter.php
src/Twig/ProjectTwigExtension.php   filtres pm_* (dates FR, avatars, autolink)
templates/admin/projects/      pages + partiels (_layout, _board, _task_card…)
public/css/projects.css, public/js/projects.js   (préfixe .pm-, JS sans dépendance)
src/Command/ProjectAccessCommand.php, ProjectDigestCommand.php
tests/E2E/ProjectSpaceTest.php, tests/E2E/ProjectImportAndProfileTest.php,
tests/Unit/Service/Project/
```

Sécurité : `access_control` + `ProjectVoter`, CSRF sur tous les formulaires et en-tête
`X-CSRF-Token` pour les appels `fetch`, redirections `_back` limitées à `/admin/projets`,
assignation limitée aux membres, liens de pièces jointes en http(s) uniquement, texte
échappé avant transformation des URL en liens, export CSV protégé contre l'injection de
formules, flux OAuth protégé par `state`, compte Google vérifié, jeton chiffré.

Tests :

```bash
php bin/phpunit tests/E2E/ProjectSpaceTest.php tests/E2E/ProjectImportAndProfileTest.php tests/Unit/Service/Project
```

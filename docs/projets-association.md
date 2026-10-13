# Page « Projets en cours » de l'association

Page privée qui présente les projets en cours de l'association à ses partenaires
(structures, financeurs), **sans compte ni mot de passe**, à l'adresse secrète :

```
https://app.bazaart.fr/projets/<jeton>
```

Décision d'architecture : ADR-0039. Back-office : **`/admin/fiches-projets`**
(menu admin, section Contenu, « Projets de l'association »).

---

## 1. Mise en service (à faire une fois, après le déploiement)

### 1.1 Déployer

Après validation, fusionner la branche dans `main`, puis lancer `./deploy.sh` depuis le Mac,
comme d'habitude. Le script applique les deux migrations du module :

- `Version20261013015217` : crée les tables `association_projects` et `association_project_blocks` ;
- `Version20261013020000` : crée les 9 fiches de départ, **toutes non publiées**.

Tant que le jeton n'est pas configuré (étape suivante), la page répond « page introuvable »
(404) : rien n'est visible.

### 1.2 Créer le jeton secret

Le jeton est le « mot de passe » contenu dans l'adresse. Il doit être long et aléatoire.

1. Se connecter au serveur : `ssh root@206.189.3.112`
2. Générer un jeton de 64 caractères :
   ```bash
   docker exec bazaart_platform_app php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```
   Copier la ligne affichée (64 caractères, chiffres et lettres de a à f).

### 1.3 Enregistrer le jeton sur le serveur

Le jeton va dans **`/root/bazaart-plateforme/app/.env.local`** (le fichier du dossier `app`,
celui que lit Symfony), **pas** dans `/root/bazaart-plateforme/.env.local` à la racine.
Le fichier de la racine sert à Docker Compose : seules les variables listées sous
`environment:` dans `docker-compose.prod.yml` (APP_SECRET, la base de données, MAILER_DSN)
passent de ce fichier à l'application. Une variable nouvelle comme celle-ci n'y est pas :
elle doit donc aller dans `app/.env.local`.

1. Vérifier que c'est bien le fichier où vivent déjà les clés de l'application
   (la commande affiche un nombre, jamais la clé elle-même) :
   ```bash
   grep -c STRIPE_SECRET_KEY /root/bazaart-plateforme/app/.env.local
   ```
   Résultat attendu : `1`. Si le résultat est `0` ou « No such file », s'arrêter et
   vérifier où sont rangées les clés (`app/.env`) avant d'aller plus loin.
2. Ajouter la ligne (remplacer `COLLER_LE_JETON_ICI` par le jeton de l'étape 1.2).
   Le `\n` du début garantit que la ligne ne se colle pas à la dernière ligne du fichier :
   ```bash
   printf '\nPROJETS_PARTENAIRES_TOKEN=%s\n' 'COLLER_LE_JETON_ICI' >> /root/bazaart-plateforme/app/.env.local
   ```
   Contrôle (affiche le début des 3 dernières lignes, sans révéler les secrets) :
   ```bash
   tail -n 3 /root/bazaart-plateforme/app/.env.local | cut -c1-30
   ```
   La dernière ligne doit commencer par `PROJETS_PARTENAIRES_TOKEN=`, et la ligne d'avant
   doit être intacte.
3. Vider le cache :
   ```bash
   cd /root/bazaart-plateforme && docker compose --env-file .env.local -f docker-compose.prod.yml exec -T platform_app php bin/console cache:clear --env=prod
   ```

### 1.4 Vérifier

```bash
# Avec le bon jeton : 200
curl -s -o /dev/null -w "%{http_code}\n" https://app.bazaart.fr/projets/COLLER_LE_JETON_ICI
# Avec un faux jeton : 404
curl -s -o /dev/null -w "%{http_code}\n" https://app.bazaart.fr/projets/faux
# En-têtes de confidentialité : doivent afficher X-Robots-Tag et Referrer-Policy
curl -sI https://app.bazaart.fr/projets/COLLER_LE_JETON_ICI | grep -iE "x-robots-tag|referrer-policy"
```

Le lien complet s'affiche aussi dans le back-office, en haut de `/admin/fiches-projets`
(encadré « Lien à partager », bouton « Copier »). S'il indique « Lien désactivé », le jeton
n'est pas lu : revoir l'étape 1.3.

### 1.5 Donner l'accès au back-office aux membres de l'équipe

Le back-office des fiches est réservé au rôle **ROLE_ADMIN**. Pour chaque membre :

```bash
cd /root/bazaart-plateforme && docker compose --env-file .env.local -f docker-compose.prod.yml exec -T platform_app php bin/console app:promote-admin adresse@exemple.fr
```

La personne doit ensuite **se déconnecter puis se reconnecter**. Attention : ROLE_ADMIN
donne accès à **tout** le back-office (utilisateurs, versements, réglages).

---

## 2. Utilisation (pour l'équipe)

### 2.1 Les fiches

`/admin/fiches-projets` : liste de toutes les fiches, publiées ou non.

| Action | Où |
|---|---|
| Créer une fiche | bouton « + Nouvelle fiche » |
| Modifier les informations | bouton « Modifier » |
| Écrire la présentation (texte et images) | bouton « Présentation » |
| Voir la fiche comme un partenaire, même non publiée | bouton « Aperçu » |
| Changer l'ordre | modifier les nombres de la colonne « Ordre », puis « Enregistrer l'ordre » (plus petit = plus à gauche) |
| Publier | case « Publié » dans la fiche |
| Supprimer | bouton « Supprimer » (efface aussi la présentation et les images) |

- **Rien n'est visible des partenaires tant que la case « Publié » n'est pas cochée.**
- La **description** fait 600 caractères au maximum (compteur sous le champ).
- **Référente** : prénom et nom seulement, sans adresse e-mail. Le contact affiché aux
  partenaires est toujours bonjourbazaart@gmail.com.
- L'**adresse de la fiche** (ex. `…/an-ba-lanme-chapitre-2`) est fixée à la création :
  elle ne change pas si le titre change, pour que les liens déjà envoyés restent valables.

### 2.2 La présentation (ce que le partenaire lit en descendant)

Page « Présentation » de chaque fiche : une suite de **blocs**. Un bloc contient un
intertitre (facultatif), du texte et, si besoin, **une** image avec un texte alternatif et
une légende. Pour plusieurs images, créer plusieurs blocs. Boutons « Monter » et
« Descendre » pour changer l'ordre.

Dans le texte, une **ligne vide** crée un nouveau paragraphe. Pas de mise en forme (gras,
liens) : c'est voulu, pour qu'aucun code ne puisse être glissé dans la page.

### 2.3 Les images

- JPEG, PNG ou WebP, **5 Mo maximum**, 8 000 px de côté maximum.
- Les images ne sont **pas réduites** automatiquement : préférer des images d'environ
  **1 600 px de large** (moins de 1 Mo), sinon la page sera lente sur mobile.
- Les photos de téléphone peuvent contenir le lieu où elles ont été prises (données
  « EXIF ») : elles sont envoyées telles quelles. Exporter la photo (ou la faire passer
  par un outil de retouche) avant de l'envoyer si c'est un problème.
- **Texte alternatif** : une courte description de l'image pour les personnes qui ne la
  voient pas (lecteurs d'écran). Sans texte saisi : pour le visuel principal, le titre du
  projet est utilisé ; pour une image de bloc, la légende, sinon l'intertitre, sinon le
  mot « Image » (peu utile : mieux vaut toujours remplir ce champ).

### 2.4 Partager le lien

Copier le lien depuis l'encadré « Lien à partager » et l'envoyer **uniquement** aux
partenaires concernés : toute personne qui a ce lien voit toutes les fiches publiées.

---

## 3. Changer le jeton (lien compromis, fin d'un partenariat…)

1. Générer un nouveau jeton (étape 1.2).
2. Dans `/root/bazaart-plateforme/app/.env.local`, remplacer la valeur de la ligne
   `PROJETS_PARTENAIRES_TOKEN=` (par exemple avec `nano`).
3. Vider le cache (étape 1.3, point 3).
4. L'ancien lien répond aussitôt « page introuvable » (404). Envoyer le nouveau lien aux
   partenaires qui doivent garder l'accès.

Pour **éteindre** la page : vider la valeur (`PROJETS_PARTENAIRES_TOKEN=`) puis vider le cache.

---

## 4. Ce qu'il faut savoir (limites)

- **Un seul lien pour tous les partenaires** : changer le jeton coupe l'accès de tout le monde.
- **Le jeton apparaît dans le journal d'accès du serveur web** (Nginx, partagé avec la
  vitrine) et dans l'historique du navigateur des partenaires. Le masquer dans le journal
  demande une ligne dans `docker/nginx/app.bazaart.fr.conf` et un redémarrage de
  `bazaart_nginx` (coupure de quelques secondes des deux sites) : non fait pour l'instant.
- La page n'est **pas indexée** (en-tête `X-Robots-Tag` et balise `robots`), n'apparaît ni
  dans le menu ni dans un plan de site, et n'envoie aucune donnée à un service tiers
  (pas de Google Analytics, pas de bandeau cookies, aucun cookie).
- Quand un partenaire clique un lien externe, l'adresse de la page (avec le jeton) n'est
  pas transmise (`Referrer-Policy: no-referrer`).

---

## 5. Remplir les fiches depuis un fichier (import)

La commande `app:import-association-projects` remplit les fiches **existantes** à partir
d'un dossier qui contient `projets.json` (une fiche par projet) et `images/` (les visuels).

### 5.1 Ce que fait l'import, et ce qu'il ne fait jamais

- Il retrouve chaque fiche par son **titre exact** (même texte, caractère pour caractère ;
  seul l'encodage invisible des accents, parfois différent sur Mac, est neutralisé). Un
  titre inconnu ne crée rien : il est signalé (avec le titre voisin s'il ne diffère que par
  un espace, une apostrophe ou une majuscule).
- Il remplit **uniquement les champs vides**. Un champ déjà rempli dans le back-office
  n'est jamais écrasé : il est listé dans le compte rendu.
- Il ne touche **jamais** à la case « Publié ». Une fiche **déjà publiée** n'est pas
  remplie du tout (rien de non relu ne doit être visible des partenaires).
- Il ne modifie **jamais** la fiche « Formations ».
- Type et état ne sont jamais modifiés ; une différence avec le fichier est signalée.
- Les visuels passent par les mêmes contrôles qu'un envoi depuis le back-office ; leur
  texte alternatif est le titre de la fiche.
- Il peut être **relancé** : rien n'est rempli deux fois. Un **journal**
  (`app/var/association-projects-import-journal-prod.json` sur le serveur, ignoré par git)
  retient, par fiche, chaque champ que l'import a rempli **ou trouvé déjà rempli**, et le
  visuel qu'il a envoyé : si l'équipe vide un champ ou retire un visuel, une relance ne le
  remplit pas à nouveau. **Ne pas supprimer ce fichier.**
- Les champs sur une ligne dans le back-office (dates, lieux, artistes, référente) ne
  doivent pas contenir de retour à la ligne : sinon le champ n'est pas rempli et c'est
  signalé (le formulaire l'effacerait sans prévenir au prochain enregistrement).
- Le compte rendu ne contient **aucun texte des fiches** (seulement les titres et les noms
  de champs) : on peut le copier-coller sans risque.

**Format de `projets.json`** : `{ "projets": [ { … }, … ] }` (ou directement la liste).
Clés de chaque fiche : `titre`, `type`, `etat`, `dates`, `lieux`, `artistes_concernes`,
`description`, `partenaires_confirmes`, `partenaires_pressentis`, `financeurs_sollicites`,
`apport_partenaire`, `referente`, `visuel` (nom d'un fichier du dossier `images/`).
`null` = champ laissé tel quel. Mêmes limites que le formulaire (description : 600
caractères ; images : JPEG, PNG ou WebP, 5 Mo).

**Ces fichiers ne doivent jamais être commités** (le dépôt GitHub est public). Ils vont
dans `app/var/import/`, que git ignore (`app/.gitignore`, règle `/var/`). Attention :
`var/import/` à la **racine** du dépôt, lui, n'est pas ignoré.

### 5.2 Procédure sur le serveur

Toutes les commandes serveur se lancent depuis `/root/bazaart-plateforme`.

1. **Sauvegarder la base** (sur le serveur). Le fichier doit faire plus de 0 octet :
   ```bash
   mkdir -p /root/backups && docker exec bazaart_platform_postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > /root/backups/avant-import-projets_$(date +%F_%H%M).sql && ls -lh /root/backups/ | tail -1
   ```
2. **Copier le dossier** (depuis le Terminal du **Mac**) :
   ```bash
   ssh root@206.189.3.112 "mkdir -p /root/bazaart-plateforme/app/var/import"
   scp -r ~/Downloads/import-projets/projets.json ~/Downloads/import-projets/images root@206.189.3.112:/root/bazaart-plateforme/app/var/import/
   ```
3. **Donner l'accès en lecture au serveur web, puis vérifier** (sur le serveur).
   `git status` ne doit rien afficher (les données sont bien ignorées par git) :
   ```bash
   cd /root/bazaart-plateforme && chmod -R a+rX app/var/import && ls -la app/var/import app/var/import/images && git status --porcelain
   ```
4. **Prévenir l'équipe** : personne ne garde une page « Modifier une fiche » ouverte
   pendant l'import. Un formulaire ouvert avant l'import puis enregistré après remettrait
   à vide les champs importés.
5. **Essai** (rien n'est enregistré). Code de sortie attendu : `0`.
   ```bash
   cd /root/bazaart-plateforme && docker compose --env-file .env.local -f docker-compose.prod.yml exec -T -u www-data platform_app php bin/console app:import-association-projects --dry-run; echo "code de sortie : $?"
   ```
6. **Import réel** : la même commande **sans** `--dry-run`. Puis la relancer une seconde
   fois : elle doit annoncer « Champs remplis (visuel compris) : 0 ».
7. **Contrôles** : aucune fiche publiée par l'import, et pas d'erreur dans les journaux :
   ```bash
   cd /root/bazaart-plateforme && docker compose --env-file .env.local -f docker-compose.prod.yml exec -T platform_app php bin/console dbal:run-sql "SELECT position, title, is_published, image_path IS NOT NULL AS visuel FROM association_projects ORDER BY position"
   cd /root/bazaart-plateforme && docker compose --env-file .env.local -f docker-compose.prod.yml logs platform_app --since=10m 2>&1 | grep -iE "critical|exception" || echo "Aucune erreur dans les journaux"
   ```
   Les journaux ne montrent que les pages web : ouvrir d'abord `/admin/fiches-projets` et
   l'aperçu de deux ou trois fiches. Pour l'import lui-même, la preuve est son compte rendu
   et son code de sortie.
8. **Nettoyer**, une fois la relecture de l'équipe terminée :
   `rm -rf /root/bazaart-plateforme/app/var/import`. Le journal, lui, reste dans
   `app/var/` : si un nouveau fichier est importé plus tard, il empêchera toujours de
   re-remplir ce que l'équipe a vidé.

**Pourquoi `-u www-data` ?** En production, la commande refuse de tourner en root : le
dossier des images serait créé au nom de root, et le back-office (qui tourne sous
`www-data`) ne pourrait plus y enregistrer d'image.

**Code de sortie `1`** : il reste une erreur, un titre introuvable ou une fiche non
touchée (publiée, exclue, en double). Lire le compte rendu : les champs valides ont été
remplis, les autres non.

**En cas d'échec pendant l'enregistrement**, rien n'est enregistré (retour arrière en
base, images de cette exécution supprimées). Pour revenir à l'état d'avant un import
**réussi**, la sauvegarde de l'étape 1 contient la base complète : demander de l'aide
avant de la restaurer (une restauration remplace toute la base, pas seulement les fiches).

---

## 6. Pour les développeurs

| Élément | Fichier |
|---|---|
| Entités | `app/src/Entity/AssociationProject.php`, `AssociationProjectBlock.php` |
| Jeton, images, slug, présentation des données | `app/src/Service/AssociationProject/` |
| Pages publiques + API | `app/src/Controller/AssociationProjectPublicController.php` |
| Back-office | `app/src/Controller/AdminAssociationProject*.php`, `app/templates/admin/association_projects/` |
| Gabarits publics | `app/templates/association_projects/` (gabarit autonome `_layout.html.twig`) |
| Carrousel | `app/public/js/association-projects.js`, `app/public/css/association-projects.css` |
| Accès public | `app/config/packages/security.yaml`, règle `^/projets/` |
| Import des fiches | `app/src/Command/ImportAssociationProjectsCommand.php`, `app/src/Service/AssociationProject/AssociationProjectImporter.php` |
| Tests | `app/tests/E2E/AssociationProjectsTest.php`, `app/tests/E2E/AssociationProjectImportTest.php`, `app/tests/Unit/Service/AssociationProject/` |

Routes publiques (toutes exigent le jeton) : `/projets/{jeton}`, `/projets/{jeton}/api`,
`/projets/{jeton}/{slug}`, `/projets/{jeton}/{slug}/visuel`,
`/projets/{jeton}/{slug}/image/{bloc}`.

- **Ne placer sous `/projets/` que des routes protégées par le jeton** : tout ce préfixe est
  public dans `security.yaml` (un test le vérifie).
- Après une modification de `association-projects.css` ou `association-projects.js`,
  incrémenter `assets.version` dans `app/config/packages/framework.yaml`, sinon les
  navigateurs gardent l'ancienne version en cache.
- Tests : `docker compose exec app php bin/phpunit tests/E2E/AssociationProjectsTest.php tests/E2E/AssociationProjectImportTest.php tests/Unit/Service/AssociationProject`

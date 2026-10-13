# ADR-0039 : page « Projets en cours » de l'association, sans connexion

- **Date** : 2026-10-13
- **Statut** : proposé
- **Décidé par** : Gaëlle

## Contexte

L'association veut présenter ses projets en cours à ses partenaires (MansA, Cariborizon,
DAC Guadeloupe, Région Guadeloupe, puis d'autres structures) pour que chacun voie, projet
par projet, où il peut l'accompagner. Les partenaires n'ont pas de compte sur la
plateforme et ne doivent pas en créer : la page doit s'ouvrir **sans connexion**, mais
rester privée (pas d'indexation, lien envoyé à la main). Les trois membres de l'équipe
doivent mettre les fiches à jour elles-mêmes depuis le back-office, y compris un texte
de présentation avec parfois des images.

Contraintes : pas de nouvelle dépendance, aucune autre page ni entité modifiée, dépôt
GitHub public (aucun secret dans le code), stack réelle Symfony 7.4 + Twig + PostgreSQL
(pas de build front), images de production dans un volume que Nginx ne voit pas.

Ce besoin n'est pas dans le cahier des charges V3 : c'est un ajout arbitré par Gaëlle.

## Options envisagées

### Accès
1. **Comptes partenaires** (rôle dédié, mot de passe) : contrôle fin, mais création et
   gestion de comptes, friction pour les partenaires. Écarté par l'association.
2. **Adresse secrète** `/projets/{jeton}` (retenu) : aucune friction ; la règle
   `^/projets/` passe en `PUBLIC_ACCESS` dans `security.yaml` (même mécanisme que
   `/articles` ou `/formations`) et le contrôleur vérifie le jeton. Limite : qui a le lien
   voit la page.

### Rendu de la page
1. **Page qui charge l'API en JavaScript** : une seule source, mais page vide sans JS et
   temps de chargement visible.
2. **Rendu serveur + API JSON séparée** (retenu, « le plus logique techniquement ») : les
   deux utilisent le même `AssociationProjectPresenter`, donc les mêmes données ; la page
   fonctionne sans JavaScript, le JS n'ajoute que le carrousel 3D.

### Texte de présentation avec images
1. **Éditeur de texte riche** (WYSIWYG) : nouvelle dépendance, HTML stocké à nettoyer.
2. **Blocs** (retenu) : intertitre facultatif + texte simple + image facultative avec
   texte alternatif et légende ; ordre géré par Monter / Descendre. Aucune dépendance,
   aucun HTML stocké (texte échappé à l'affichage).

### Stockage des images
1. `public/uploads/` (comme le forum) : en développement, l'image serait visible sans jeton.
2. **`var/secure_uploads/association-projects/<env>/`** (retenu, comme
   `CreatorDocumentStorage`) : hors du dossier public, servi par PHP après vérification
   du jeton (ou du rôle admin), avec la validation de `ForumImageService`.

## Décision

- Entités `AssociationProject` et `AssociationProjectBlock` (tables `association_projects`,
  `association_project_blocks`). Nom distinct de `App\Entity\Project`, qui est l'outil
  interne de gestion de projet (Espace projets, ADR-0037).
- Jeton dans `PROJETS_PARTENAIRES_TOKEN` (`app/.env.local` sur le serveur), vide par
  défaut = page éteinte. Comparaison `hash_equals`. Mauvais jeton, fiche inconnue ou non
  publiée : **404**, jamais 403.
- Routes publiques (contrôleur `AssociationProjectPublicController`, les seules autorisées
  sous `/projets/`) : page, fiche, `/api`, visuel, image de bloc. Un bloc est toujours
  cherché dans le projet publié désigné par le slug.
- En-têtes `X-Robots-Tag: noindex, nofollow` et `Referrer-Policy: no-referrer` sur toutes
  les réponses publiques ; balises meta équivalentes. Gabarit autonome : ni menu, ni pied
  de page, ni bandeau cookies, ni Google Analytics, ni écran de chargement.
- Back-office `/admin/fiches-projets` (ROLE_ADMIN). Pas `/admin/projets-…` : la règle
  `^/admin/projets` réserve ce préfixe à ROLE_PROJECT.
- Slug calculé une fois à la création, puis figé (les liens envoyés restent valables),
  sans `iconv` (qui abîme les accents sur Alpine).
- Carrousel 3D en CSS + JavaScript natif, avec repli en défilement horizontal natif
  (mobile, `prefers-reduced-motion`, sans JavaScript).

## Conséquences

- **Code** : entités, enums, repositories, services (`src/Service/AssociationProject/`),
  DTO, 3 contrôleurs, gabarits `templates/association_projects/` et
  `templates/admin/association_projects/`, `public/css/association-projects.css`,
  `public/js/association-projects.js`, 2 migrations (schéma + 9 fiches non publiées),
  `security.yaml` (1 règle), `services.yaml`, `app/.env.example`, 1 lien dans la barre
  latérale admin, tests (`AssociationProjectsTest`, `SlugAndTokenTest`).
- **Cahier des charges V3** : à compléter (module hors périmètre initial, 2 tables).
- **Mise en service manuelle** : générer et poser le jeton sur le serveur, donner
  ROLE_ADMIN aux membres de l'équipe (voir `docs/projets-association.md`).
- **À surveiller / limites assumées** :
  - un seul lien pour tous les partenaires : un e-mail transféré suffit à ouvrir la page,
    et changer le jeton coupe l'accès de tous ;
  - le jeton apparaît en clair dans le journal d'accès du Nginx partagé avec la vitrine
    (le masquer demande une ligne `map` dans `docker/nginx/app.bazaart.fr.conf` et un
    redémarrage de `bazaart_nginx` : reporté, à décider) ;
  - images non redimensionnées (pas d'extension GD) et métadonnées des photos (dont le
    lieu de prise de vue) conservées ;
  - tout ce qui est sous `/projets/` est public : un test vérifie que seules les routes à
    jeton y vivent.
- **Évolution prévue** : pages projets des artistes. Le même mécanisme (règle publique +
  vérification d'un jeton) s'y prête ; il faudra probablement un jeton par projet (stocké
  en base) plutôt qu'un jeton commun, et un autre préfixe d'URL.

## Complément : import des fiches depuis un fichier (octobre 2026)

Pour remplir les 8 premières fiches à partir d'un `projets.json` fourni par l'équipe, on a
retenu une **commande console lancée sur le serveur** (`app:import-association-projects`),
le fichier étant copié par `scp` dans `app/var/import/` (ignoré par git).

- **Écarté : une migration de données.** Le contenu aurait dû être commité dans un dépôt
  public, et les images ne passent pas par une migration.
- **Écarté : importer en local puis transférer la base.** Risque d'écraser la production,
  et les images auraient été copiées sans passer par le contrôle d'upload.
- **Règles** : rattachement par titre exact, aucun champ déjà rempli écrasé, « publié »
  jamais modifié, fiche publiée et fiche « Formations » non touchées, visuels envoyés par
  `AssociationProjectImageStorage::store()`, journal empêchant de re-remplir un champ vidé
  par l'équipe, une transaction avec verrou par fiche, essai `--dry-run`.
- **Limite connue** : une page « Modifier une fiche » ouverte avant l'import puis
  enregistrée après remet à vide les champs importés (le formulaire réécrit tous les
  champs). D'où la consigne : aucun formulaire ouvert pendant l'import.

Procédure : `docs/projets-association.md`, section 5.

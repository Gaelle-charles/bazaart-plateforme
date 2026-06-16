# ADR-0011 — Forum : images jointes et réactions emoji

- **Date** : 2026-06-15
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le module Communauté (Forum) du CDC V3 §5.3 ne prévoit pour `ForumThread` et
`ForumReply` qu'un champ `content` texte. Le jour du lancement (2026-06-15),
Gaëlle a demandé deux ajouts pour enrichir l'expérience communautaire :

1. Permettre aux membres de **joindre une image** à leurs sujets et réponses.
2. Ajouter des **réactions emoji** (« j'aime, flammes, etc. ») pour un peu
   d'originalité et d'interaction légère.

Ces deux fonctionnalités sont un **écart assumé au périmètre V1** : non spécifiées
au CDC, mais arbitrées par Gaëlle. Le lead a signalé le risque d'introduire une
fonctionnalité touchant upload/stockage/sécurité le jour J ; Gaëlle a maintenu
« maintenant ».

## Options envisagées

### Image jointe
1. **1 image par post (champ `imagePath`)** — réutilise le pattern d'upload
   existant (avatars, couvertures d'articles). Petit périmètre, aucun risque XSS
   (l'image est un champ séparé, le contenu reste rendu en `|nl2br`).
   Inconvénient : une seule image par post, stockage dans le webroot (dette §4.4
   déjà présente ailleurs).
2. **Pièces jointes multiples (entité dédiée, stockage hors webroot)** — propre et
   conforme §4.4, mais gros chantier (1–2 j/h) et trop risqué le jour du lancement.
3. **Image par URL externe** — ultra-léger mais mauvaise UX et risques SSRF /
   tracking pixel ; déconseillé.

### Réactions
1. **Réactions multi-types façon Slack/Discord** (un user peut poser plusieurs
   types différents, une seule fois chacun, toggle) — entité `ForumReaction`
   inspirée de `PostLike`, jeu fixe de 5 emojis.

## Décision

- **Image : Option 1.** Champ `imagePath` (nullable) sur `ForumThread` et
  `ForumReply`. Upload via `ForumImageService` : validation MIME stricte côté
  serveur (JPEG/PNG/WebP/GIF), 5 Mo max, nom unique, stockage `public/uploads/forum/`.
  Présent sur la page « Nouveau sujet » et le formulaire de réponse. **Non ajouté**
  au compositeur inline de l'index (JS fragile, risque écarté le jour J).
- **Réactions : Option 1.** Enum `ForumReactionType` (👍 j'aime, 🔥 flamme,
  👏 bravo, ❤️ cœur, 💡 inspirant), entité `ForumReaction` (cible polymorphe
  thread|reply, toggle, 2 index UNIQUE). Endpoint `POST /forum/react` (JSON pour le
  JS, fallback non-JS). Affichage initial chargé en batch (anti-N+1).

## Conséquences

- **Code** : nouveaux `ForumReactionType`, `ForumReaction`, `ForumReactionRepository`,
  `ForumImageService` ; `ForumService` (signatures `createThread`/`addReply` étendues
  d'un `?UploadedFile`, nettoyage des fichiers à la suppression, `toggleReaction`) ;
  `ForumController` (action `react`, données réactions dans `thread()`) ; migration
  `Version20260615235907` ; templates `new_thread` et `thread`.
- **Validation** : PHPStan niveau 6 OK, migration appliquée + `schema:validate` OK,
  `lint:twig` OK.
- **Écart au CDC V3 §5.3** : à refléter dans le cahier des charges (champ image +
  table `forum_reactions`).
- **À surveiller** :
  - Stockage des images dans le webroot (dette §4.4 « hors webroot » commune à
    tout le projet) — à traiter globalement post-lancement.
  - Volume disque sur le droplet (2 Go + swap) si beaucoup d'images.
  - Évolution possible V2 : pièces jointes multiples + stockage hors webroot ;
    image dans le compositeur inline de l'index.
- **Config locale** : `DATABASE_URL` et `DEFAULT_URI` ajoutés à `app/.env.local`
  (gitignoré) pour permettre migrations / PHPStan / lint en local.

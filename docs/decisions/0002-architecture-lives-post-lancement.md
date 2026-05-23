# ADR-0002 — Lives natifs reportés post-lancement + architecture retenue

- **Date** : 2026-05-22
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le module Lives planifiés V1 du CDC prévoit : annonce d'un live avec lien externe (Twitch, Jitsi,
Google Meet), inscription des participants, rappel email 1h avant, upload manuel du replay vers
Bunny Stream après la session.

Deux sujets distincts sont tranchés ici :

1. **Les lives natifs intégrés** (streaming en temps réel sur bazaart.fr) — prévus en V2 dans le
   CDC. La question est l'architecture à retenir pour cette V2.

2. **Le module Lives planifiés V1** (lien externe + rappel) — maintenu dans le périmètre V1 de la
   Communauté, mais son périmètre est précisé et borné.

La décision porte principalement sur l'architecture V2 des lives natifs, arbitrée maintenant pour
ne pas conditionner des choix techniques V1 (notamment Bunny Stream).

## Options envisagées pour les lives natifs (V2)

1. **WebRTC + LiveKit** — Avantages : ultra-faible latence (<500 ms), multi-intervenants natif,
   idéal pour ateliers interactifs, open-source self-hostable.
   Inconvénients : infrastructure serveur TURN/SFU complexe, dimensionnement difficile à la charge,
   coût opérationnel significatif si managed, intégration SDK JS non triviale.

2. **HLS via Bunny Stream Live** — Avantages : réutilise la brique Bunny déjà retenue pour les
   formations (ADR-0001), latence acceptable (3-10 s, suffisante pour des broadcasts), replay
   automatique sans post-traitement, CDN mondial inclus, coût prévisible et faible, intégration
   simple (iframe + API Bunny). Inconvénients : latence trop haute pour des échanges interactifs
   en temps réel, pas adapté à un atelier bi-directionnel.

3. **Embed Twitch / YouTube Live** — Avantages : zéro infra, zéro développement. Inconvénients :
   dépendance plateforme tierce, pas de contrôle, fuite de l'audience vers Twitch/YouTube, pas
   de replay natif sur bazaart.fr.

## Décision

**Architecture retenue pour les lives natifs (V2)** :
- **Broadcast HLS via Bunny Stream Live** pour le cas d'usage principal (conférences, showcases,
  présentations) : latence 3-10 s acceptable, replay automatique, synergie avec les formations.
- **Chat propriétaire via Mercure + Redis** : Mercure (hub SSE, bundle Symfony natif) pour le
  push temps réel des messages de chat ; Redis pour le pub/sub et la persistence courte durée.
  Choix cohérent avec la stack existante, pas de WebSocket custom à gérer.
- **WebRTC / LiveKit gardé en réserve** pour un éventuel mode "atelier interactif" (≤ 10
  participants, bi-directionnel) — à évaluer selon les besoins de la communauté post-lancement.

**Périmètre V1 Lives planifiés (dans Communauté)** :
- Maintenu, mais borné à : entités `Live` + `LiveAttendee`, page calendrier, page détail, création
  par un membre, inscription, rappel email (via Symfony Messenger, pas n8n pour simplifier).
- Le champ `external_url` est obligatoire en V1 — le lien de connexion est fourni par l'hôte
  (Twitch, Jitsi, Google Meet, Zoom, autre).
- Upload replay en V1 : **simplifié** — l'hôte colle une URL externe (Bunny, Vimeo, YouTube) dans
  un champ `replay_url` de type string. Pas d'upload direct vers Bunny en V1 (l'intégration API
  Bunny est reportée avec la Formation).
- Le job n8n de rappel est simplifié en V1 : un Symfony Command ou Messenger ScheduledTask
  (cron) remplace n8n pour ne pas ajouter de dépendance externe.

## Conséquences

- **En V1** : entité `Live` simplifiée (pas de `replay_video_url` Bunny, juste `replay_url`
  string nullable). Pas d'intégration API Bunny en V1.
- **En V2** : installer le bundle Mercure, configurer le hub Mercure (peut tourner dans Docker
  comme container additionnel), intégrer Bunny Stream Live côté RTMP (l'hôte stream avec OBS ou
  similaire vers un endpoint Bunny).
- **Infrastructure V2** : ajouter le container `mercure` au `docker-compose.yml`. Redis est déjà
  présent. Pas de nouveau service majeur.
- **CLAUDE.md** : divergence à signaler — la section 5 (Architecture BDD) liste `lives` et
  `live_attendees` dans les nouvelles tables V1 (maintenu), et la section 6 (Roadmap) mentionne
  "Lives natifs intégrés" en V2 (confirmé). Pas de divergence majeure, mais le CLAUDE.md ne
  mentionne pas Mercure — à ajouter lors de la prochaine mise à jour arbitrée par Gaëlle.
- **CDC V3** : l'architecture Mercure + HLS Bunny n'est pas mentionnée dans le CDC (qui restait
  intentionnellement ouvert sur le choix technique). Cette ADR la fixe. Le CDC section 5
  (Module 7) reste la référence fonctionnelle.

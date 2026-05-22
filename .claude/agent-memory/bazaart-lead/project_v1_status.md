---
name: project-v1-status
description: État précis du code V1 au 22 mai 2026 — ce qui est fait, ce qui manque, par module
metadata:
  type: project
---

État relevé le 2026-05-22. Deadline V1 : 2026-06-15 (24 jours restants).

**Why:** Planning CDC V3 — 38 jours depuis le 8 mai. On est maintenant en semaine 2 du plan et la semaine 1 (Ressourcerie) n'est pas encore terminée.
**How to apply:** Utiliser ce tableau pour prioriser les tâches, éviter de refaire ce qui est déjà là, et alerter sur les glissements de planning.

---

## ✅ CE QUI EST FAIT

### Corrections rapides (semaine du 12 mai — FAITES)
- `updatedAt` ajouté à `OrganizationProfile` (migration Version20260511211725)
- Enums PHP 8.1 créés : `ArticleStatus`, `ResourceStatus`, `ScrapedResourceStatus`, `SubmitterRole`

### Entités existantes adaptées
- `Resource` : champs `submitterRole`, `autoPublished`, `publishedAt`, `validatedAt`, `validatedBy`, `status` — FAIT
- `OrganizationProfile` : champs `isStructurePartner`, `structureActivatedAt`, `structureActivationValidatedBy`, `updatedAt` — FAIT (BDD + entité)

### Modules existants fonctionnels
- Authentification : login/register classique + Google OAuth (`GoogleAuthenticator`)
- Hub social : posts, comments, likes, articles, annuaire artistes
- Ressourcerie basique : catalogue `Resource`, formulaire de soumission, pages admin (pending, all resources, scraped)
- Agent IA scraping : 7 scrapers actifs (ADAGP, CNAP, CNM, Culture.gouv, MusiquesActuelles, ProHelvetia, SAIF)
- Admin : dashboard basique, gestion users, validation ressources scrapées

---

## ❌ CE QUI MANQUE (V1)

### MODULE RESSOURCERIE (reste ~5 j/h, était semaine 1)
- `ROLE_STRUCTURE` + `ROLE_MODERATOR` non implémentés (pas dans security.yaml, pas de voter)
- Pas de `StructureController` → routes `/structure/register` et `/structure/dashboard` inexistantes
- Workflow d'activation admin (`/admin/structures/pending`) manquant
- `ResourceFavorite` entity manquante (M2M User × Resource)
- `ResourceAlert` entity manquante (préférences alertes)
- Alertes email + job n8n quotidien non implémentés

### MODULE COMMUNAUTÉ (reste ~8 j/h, était semaine 2-3)
- Entities **Forum** manquantes : `ForumCategory`, `ForumThread`, `ForumReply`
- Pages forum (liste catégories, liste threads, détail thread, création, réponse)
- Modération forum (lock, pin, signalement)
- Entities **Messagerie** manquantes : `Conversation`, `ConversationParticipant`, `Message`
- Pages messagerie (liste conversations, fil, envoi, initier)
- Entity `Notification` manquante + génération événements
- API `/api/notifications/unread-count` + Stimulus polling
- Page `/notifications` + préférences dans profil
- Entities **Lives** manquantes : `Live`, `LiveAttendee`
- Pages lives (calendrier, détail, création, inscription, upload replay)
- Job n8n rappels lives (1h avant)

### MODULE FORMATION (0% — reste ~6 j/h, était semaine 3-4)
- Entities manquantes : `Course`, `CourseModule`, `Lesson`, `LessonResource`, `CourseEnrollment`, `LessonProgress`
- Migrations correspondantes
- Catalogue formations public + détail
- Espace apprenant + lecteur vidéo Bunny Stream
- Suivi de progression (`LessonProgress`)
- Interface admin : création formation, modules, leçons, upload Bunny Stream
- Intégration Bunny Stream (API + iframe token signé)

### TRANSVERSE / RGPD (à finaliser avant 15 juin)
- `ROLE_STRUCTURE` + `ROLE_MODERATOR` dans `security.yaml` (hiérarchie des rôles)
- Voters (pas de `if $user->getRoles()...`)
- Rate limiting `/login` + `/register` (max 5 tentatives / 15 min / IP)
- Pages légales : `/confidentialite`, `/cgu`, `/mentions-legales`
- Bannière consentement cookies
- Espace RGPD utilisateur (export JSON + demande suppression)
- Page admin `/admin/system-health`

### TESTS
- Tests PHPUnit (cible 30% couverture)
- 5-8 scénarios end-to-end (inscription, soumission ressource, thread forum, message privé, inscription formation)
- Test de restauration sauvegarde sur staging

### INFRA
- Environnement staging (staging.bazaart.fr) à configurer
- Upgrade droplet 4 GB (semaine du 9 juin)
- Script `bin/deploy.sh` ou GitHub Actions

---

## ALERTE PLANNING
On est le 22 mai. Le plan CDC prévoyait la Ressourcerie finie au 18 mai, la Communauté au 25 mai.
On a ~2 semaines de retard sur le plan initial. Il reste 24 jours pour livrer 19 j/h de travail estimé.
Marge de sécurité quasi nulle — toute extension de scope est à refuser fermement.

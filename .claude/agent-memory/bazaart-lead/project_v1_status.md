---
name: project-v1-status
description: État précis du code V1 au 25 mai 2026 — ce qui est fait, ce qui manque, par module
metadata:
  type: project
---

État mis à jour le 2026-05-25. Deadline V1 : 2026-06-15 (21 jours restants).

**Why:** Planning CDC V3 — deadline Mansa immuable. Utiliser ce tableau pour prioriser, éviter les doublons, alerter sur les glissements.
**How to apply:** Toujours vérifier ici avant de planifier une nouvelle tâche.

---

## ✅ CE QUI EST FAIT

### MODULE RESSOURCERIE — ✅ COMPLET
- `ROLE_STRUCTURE` + `ROLE_MODERATOR` dans `security.yaml`
- Voters : `ResourceVoter`, `StructureVoter`, `ForumVoter`, `LiveVoter`, `MessagingVoter`
- `OrganizationProfile` étendu (structureApplicationAt, isStructurePartner, etc.)
- `StructureController`, `StructureService`
- `AdminController` étendu (structures pending, activate, reject, audit fields)
- `ResourceFavorite` + `ResourceAlert` entities + toggle AJAX + formulaire préférences
- `ResourceAlertService` + `SendResourceAlertsCommand`
- Templates email resource_alert

### MODULE COMMUNAUTÉ — ✅ COMPLET (Forum, Messagerie, Notifications)

#### Forum ✅
- Entities : `ForumCategory`, `ForumThread`, `ForumReply`
- Repositories : pagination complète, tri NULLS LAST corrigé (25 mai)
- `ForumService` : createThread (slug déduplication + slugs réservés), addReply,
  toggleLock, togglePin, deleteThread, deleteReply, incrementViews (atomique), reportThread
- `ForumController` : 10 routes (+ report ajouté le 25 mai)
- `ForumVoter`, `SeedForumCategoriesCommand` (5 catégories + threads d'amorce)
- Templates : index, category, thread (report button), new_thread
- Migration : Version20260523131534
- Corrections 25 mai : slug "nouveau" réservé, NULLS FIRST PostgreSQL, route report,
  incrementViews race condition, seed 5ème catégorie, style inline extrait

#### Messagerie ✅
- `Message` entity, `MessageRepository`, `MessagingService`, `MessagingVoter`
- `MessagingController` : index, new, show, send (4 routes)
- Templates : messaging/index, messaging/new, messaging/show

#### Notifications ✅
- `Notification` entity, `NotificationRepository`, `NotificationService`, `NotificationType` enum
- `NotificationController`, `ApiNotificationController`
- `NotificationExtension` (Twig global)
- Template : notifications/index

### MODULE FORMATION — ✅ COMPLET (25 mai 2026)
- Entities : `Course`, `CourseModule`, `Lesson`, `LessonResource`, `CourseEnrollment`, `LessonProgress`
- `CourseLevel` enum
- Migration : Version20260525100937 (6 tables)
- `CourseService` : generateSlug, enrollUser, updateLessonProgress, recalculateProgress,
  isAllowedVideoUrl (liste blanche YouTube/Vimeo/Bunny Stream)
- `CourseController` : 6 routes publiques (catalogue, show, enroll, learn, lesson, progress AJAX)
- `AdminCourseController` : 5 routes admin (index, new, edit, modules/lessons, publish)
- `CourseRepository::findAllWithModulesAndLessons()` (FETCH JOIN, anti-N+1)
- Templates : course/{index, show, learn, lesson}, admin/course/{index, new, edit}
- Design Street respecté : .cf- (public), .adc- (admin), border-radius: 0
- Option B vidéo : iframe YouTube/Vimeo (videoBunnyId nullable, player Bunny = V2)

### Design système (25 mai 2026)
- `base_dashboard.html.twig` : layout sidebar artiste/structure, design Street .sd-*
- `base_admin.html.twig` : refactorisé vers .sd-* (même sidebar Street)
- `dashboard/index.html.twig` + `structure/dashboard.html.twig` : sidebar fonctionnelle
- `admin/dashboard.html.twig` : refonte Street (SVG icons, header deux niveaux, sparklines)

### Modules existants fonctionnels (avant V1)
- Auth : login/register classique + Google OAuth
- Hub social : posts, comments, likes, articles, annuaire artistes
- Ressourcerie basique (catalogue, soumission, admin)
- Agent IA scraping : 7 scrapers actifs

---

## ❌ CE QUI MANQUE (V1)

### MODULE COMMUNAUTÉ — Lives planifiés (borné CDC)
- Entity `Live` (`external_url` obligatoire, `replay_url` nullable, pas d'upload Bunny)
- Entity `LiveAttendee`
- Rappel email via Symfony Mailer/Messenger (pas n8n)
- Pages : calendrier, détail, création (admin), inscription
- `LiveVoter` existe déjà

### TRANSVERSE / RGPD (~3 j/h)
- Rate limiting `/login` + `/register` (max 5 / 15 min / IP)
- Pages légales : `/confidentialite`, `/cgu`, `/mentions-legales`
- Bannière consentement cookies
- Espace RGPD utilisateur (export JSON + demande suppression)

### TESTS (~2 j/h)
- PHPUnit 30% couverture cible
- 5 scénarios E2E : inscription, soumission ressource, thread forum, message privé, inscription live

### INFRA
- Cron job quotidien `app:send-resource-alerts`
- `DEFAULT_URI=https://bazaart.fr` dans `.env.local` prod
- Staging (staging.bazaart.fr)
- Upgrade droplet 4 GB (semaine du 9 juin)
- `composer require --dev phpstan/phpstan phpstan/extension-installer` (PHPStan non installé en container)
- `docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction` (Formation + Forum)
- `docker compose exec app php bin/console app:forum:seed-categories` (5 catégories + threads)

---

## DÉCISIONS PRODUIT (ne pas revenir dessus)
- Email confirmation inscription Formation → V2
- Dépublication formation → V2 (action publish irréversible en V1)
- Bunny Stream player → V2 (V1 = iframe YouTube/Vimeo uniquement)
- admin@bazaart.fr : adresse admin en dur dans ForumService::reportThread() → TODO prod

## ALERTE PLANNING
21 jours restants au 25 mai. Priorité : Lives (dernier module manquant), puis RGPD.
Formation et Forum terminés et reviewés. Messagerie et Notifications semblent complètes
mais n'ont pas encore été auditées — les passer en review avant merge.

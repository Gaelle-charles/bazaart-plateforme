---
name: project-v1-status
description: État précis du code V1 au 25 mai 2026 — ce qui est fait, ce qui manque, par module
metadata:
  type: project
---

État mis à jour le 2026-05-25. Deadline V1 : **2026-06-23** (décalée du 15 au 23 juin, confirmé le 2026-06-10).

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

### MODULE COMMUNAUTÉ — Lives planifiés ✅ COMPLET (25 mai 2026)
- Entities : `Live`, `LiveAttendee` — migration Version20260525212745 + 220000
- `LiveStatus` enum (SCHEDULED/LIVE/ENDED/CANCELLED)
- `LiveService`, `LiveController` (index, show, attend, unattend + CSRF)
- `AdminLiveController` (index, new, edit, cancel + validation URL)
- `LiveVoter` (CREATE/EDIT/CANCEL/REGISTER/VIEW/MANAGE)
- `SendLiveRemindersCommand` (--dry-run)
- Templates : live/index, live/show, admin/live/index, admin/live/form
- Emails : rappel 24h avant, annulation (txt + html)
- Lives dans sidebar admin uniquement (artistes ne créent pas de lives en V1)

### TRANSVERSE / RGPD ✅ COMPLET (25 mai 2026)
- `UserChecker` : bloque les comptes anonymisés (form login + Google OAuth)
- Rate limiting : login_throttling natif Symfony (5/15min) + register_limiter (sliding_window)
- `trusted_proxies` configuré pour la vraie IP derrière le proxy DigitalOcean
- Pages légales publiques : `/confidentialite`, `/cgu`, `/mentions-legales` (LegalController)
- Bannière cookies RGPD (SameSite=Lax, Secure conditionnel HTTPS/HTTP)
- Espace RGPD utilisateur : export JSON art.20 (incl. Formation) + anonymisation art.17
- `User.anonymizedAt` + migration Version20260525223758
- ⚠️ Pages légales contiennent des placeholders [À COMPLÉTER] : SIRET, adresse, DPO contact
- ⚠️ trusted_proxies = REMOTE_ADDR (ok actuel, à changer en IP fixe si LB DigitalOcean ajouté)

### Design système (25 mai 2026)
- `base_dashboard.html.twig` : layout sidebar artiste/structure, design Street .sd-*
- `base_admin.html.twig` : refactorisé vers .sd-* (même sidebar Street)
- `dashboard/index.html.twig` + `structure/dashboard.html.twig` : sidebar fonctionnelle
- `admin/dashboard.html.twig` : refonte Street (SVG icons, header deux niveaux, sparklines)

### Modules existants fonctionnels (avant V1)
- Auth : login/register classique + Google OAuth
- Hub social : posts, comments, likes, articles, annuaire artistes
- Ressourcerie basique (catalogue, soumission, admin)
- Agent IA scraping : 10 scrapers actifs (3 nouveaux européens ajoutés le 26 mai)

### Scraping expansion (26 mai 2026) ✅ COMPLET
- `AppSetting` entity + `AppSettingRepository` + migration Version20260526030315 (table `app_settings`)
- `SettingService` : get/set/upsert/upsertWithoutFlush/flush, clé API lisible depuis BDD ou env
- `LlmExtractorService` : Mistral Small 3.2 (défaut, json_object natif) + Anthropic Haiku (fallback), switchable via `llm_provider` AppSetting
- 3 nouveaux scrapers EU : `OnTheMoveScraper`, `ResartisScraper`, `CultureMovesEuropeScraper`
- `AdminSettingController` : /admin/settings + /admin/settings/test-mistral + /test-anthropic
- `SeedSettingsCommand` : 4 settings (anthropic_api_key, scraping_enabled, llm_provider, mistral_api_key)
- Google Sheets : marqué @deprecated (GoogleSheetsService, FormatSheetsCommand, toSheetRow())
- Documentation cron : docs/scraping-cron.md

### Feature 1 — Sources pilotables depuis l'admin (26 mai 2026) ✅ COMPLET + RELU
- `ScrapingSource` entity + `ScrapingSourceType` enum (RSS/HtmlLlm/HtmlCss) + `ScrapingRunStatus` enum (NeverRun/Success/Error)
- Migration Version20260526150555 : table `scraping_sources` + colonne `disciplines` dans `scraped_resources`
- `ScrapingSourceRepository` : findAllActive(), findAllOrderedByNom(), findBySlug(), findByUrl()
- `ScraperRegistry` : annuaire slug → AbstractScraper (10 scrapers), getBySlug(), getKnownSlugs()
- `GenericScraper` : scraper générique RSS 2.0 + Atom + HTML_LLM (sans classe PHP dédiée)
- `ScrapeOpportunitiesCommand` : refondé — lit sources depuis BDD, plus de liste hardcodée, markRunSuccess/Error par source
- `SeedScrapingSourcesCommand` : app:seed-scraping-sources (idempotente, 10 sources)
- `AdminScrapingSourceController` : CRUD /admin/scraping-sources (ROLE_ADMIN, CSRF)
- Template scraping_sources.html.twig + lien sidebar base_admin.html.twig
- ⚠️ Clé Mistral API à saisir dans /admin/settings pour activer scrapers HTML_LLM (On The Move, EACEA)
- Dry-run validé : 70 opportunités collectées

---

## ❌ CE QUI MANQUE (V1)

### TESTS (~2 j/h)
- PHPUnit 30% couverture cible
- 5 scénarios E2E : inscription, soumission ressource, thread forum, message privé, inscription live

### INFRA
- Cron job quotidien `app:send-resource-alerts`
- `DEFAULT_URI=https://bazaart.fr` dans `.env.local` prod
- Staging (staging.bazaart.fr)
- Upgrade droplet 4 GB (semaine du 9 juin)
- ✅ PHPStan niveau 6 installé (10 juin 2026) : phpstan/phpstan ^2.2 + extension-installer + phpstan-doctrine en require-dev, `app/phpstan.neon` (level 6, paths src). Lancer avec `--memory-limit=512M` (php.ini container à 128M, trop bas). Baseline `app/phpstan-baseline.neon` = 79 erreurs préexistantes hors périmètre gelées (dette à traiter module par module). Code neuf doit rester à 0 erreur hors baseline.
- `docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction` (Formation + Forum)
- `docker compose exec app php bin/console app:forum:seed-categories` (5 catégories + threads)

---

## DÉCISIONS PRODUIT (ne pas revenir dessus)
- Email confirmation inscription Formation → V2
- Dépublication formation → V2 (action publish irréversible en V1)
- Bunny Stream player → V2 (V1 = iframe YouTube/Vimeo uniquement)
- admin@bazaart.fr : adresse admin en dur dans ForumService::reportThread() → TODO prod

## ALERTE PLANNING
21 jours restants au 25 mai. TOUS les modules V1 sont terminés et commités sur `demo`.
Reste : tests PHPUnit + infra + remplissage pages légales + review Messagerie/Notifications.

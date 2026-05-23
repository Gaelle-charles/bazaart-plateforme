---
name: project-v1-status
description: État précis du code V1 au 23 mai 2026 — ce qui est fait, ce qui manque, par module
metadata:
  type: project
---

État relevé le 2026-05-23. Deadline V1 : 2026-06-15 (23 jours restants).

**Why:** Planning CDC V3 — deadline Mansa immuable. Utiliser ce tableau pour prioriser, éviter les doublons, alerter sur les glissements.
**How to apply:** Toujours vérifier ici avant de planifier une nouvelle tâche.

---

## ✅ CE QUI EST FAIT

### Corrections rapides (semaine du 12 mai — FAITES)
- `updatedAt` ajouté à `OrganizationProfile` (migration Version20260511211725)
- Enums PHP 8.1 : `ArticleStatus`, `ResourceStatus`, `ScrapedResourceStatus`, `SubmitterRole`, `AlertFrequency`

### MODULE RESSOURCERIE — ✅ COMPLET (23 mai 2026)
- `ROLE_STRUCTURE` + `ROLE_MODERATOR` dans `security.yaml` (role_hierarchy OK)
- Voters : `ResourceVoter`, `StructureVoter`, `ForumVoter` (partiel au départ, mis à jour), `LiveVoter`
- `OrganizationProfile` étendu : `structureApplicationAt`, `isStructurePartner`, `structureActivatedAt`, `structureActivationValidatedBy`
- `StructureController` : `/structure/register` + `/structure/dashboard`
- `StructureService` : applyAsStructure(), activateStructure(), rejectStructureApplication()
- `AdminController` étendu : `/admin/structures/pending`, activate/{id}, reject/{id}, publishResource (audit fields), verifyScrapedOpportunity (audit fields)
- `ResourceFavorite` entity + toggle AJAX (`/resources/{id}/favorite`)
- `ResourceAlert` entity + formulaire préférences (`/resources/alerts`)
- `ResourceAlertService` + `SendResourceAlertsCommand` (--dry-run, --force-weekly, Europe/Paris timezone)
- Templates email : `resource_alert.html.twig` + `resource_alert.txt.twig`
- Templates : `resource/favorites.html.twig`, `resource/my.html.twig`, `resource/alerts.html.twig`

### MODULE COMMUNAUTÉ — Forum ✅, Messagerie ✅, Notifications ✅ (23 mai 2026)
- Entities : `ForumCategory`, `ForumThread`, `ForumReply` (auto-référentielle pour imbrication V2)
- Repositories : `ForumCategoryRepository` (findAllActive, findBySlug), `ForumThreadRepository` (findByCategory avec pagination, findLatestByCategory, countByCategory), `ForumReplyRepository` (findByThread, countByThread)
- `ForumService` : createThread (slug déduplication globale), addReply, toggleLock, togglePin, deleteThread, deleteReply, incrementViews
- `ForumController` : 9 routes (index, category, thread, new_thread, reply, lock, pin, delete_thread, delete_reply)
- `ForumVoter` : mis à jour avec `instanceof ForumThread/ForumReply` (plus de method_exists())
- `SeedForumCategoriesCommand` : 4 catégories par défaut (idempotent)
- Templates : `forum/index.html.twig`, `forum/category.html.twig`, `forum/thread.html.twig` (|nl2br XSS safe), `forum/new_thread.html.twig`
- Migration : `Version20260523131534` (tables forum_categories, forum_threads, forum_replies)
- Lien Forum ajouté dans `base_app.html.twig` sidebar
- BUG FIXES post-review : XSS |nl2br sur contenus, ordre CSRF→autorisation dans actions modération, slug collision inter-catégories

### Modules existants fonctionnels (avant V1)
- Auth : login/register classique + Google OAuth
- Hub social : posts, comments, likes, articles, annuaire artistes
- Ressourcerie basique (catalogue, soumission, admin)
- Agent IA scraping : 7 scrapers actifs

---

## ❌ CE QUI MANQUE (V1)

### MODULE COMMUNAUTÉ (reste ~2 j/h)
- **Lives planifiés** (périmètre borné ADR-0002) :
  - Entity `Live` (`external_url` obligatoire, `replay_url` string nullable, pas d'upload Bunny en V1)
  - Entity `LiveAttendee`
  - Rappel email via Symfony Messenger ScheduledTask (pas n8n)
  - Pages : calendrier, détail, création (admin), inscription

### MODULE FORMATION — ⛔ RETIRÉ DU PÉRIMÈTRE V1 (ADR-0001)
Reporté V1.5 (juillet 2026). Ne pas créer d'entités Formation.

### TRANSVERSE / RGPD (~3 j/h)
- Rate limiting `/login` + `/register` (max 5 / 15 min / IP)
- Pages légales : `/confidentialite`, `/cgu`, `/mentions-legales`
- Bannière consentement cookies
- Espace RGPD utilisateur (export JSON + demande suppression)

### TESTS (~2 j/h)
- PHPUnit 30% couverture cible
- 5 scénarios E2E : inscription, soumission ressource, thread forum, message privé, inscription live

### INFRA
- Cron job quotidien pour `app:send-resource-alerts`
- `DEFAULT_URI=https://bazaart.fr` dans `.env.local` prod
- Staging (staging.bazaart.fr)
- Upgrade droplet 4 GB (semaine du 9 juin)
- `composer require --dev phpstan/phpstan phpstan/extension-installer` (PHPStan non installé)
- `docker compose exec app php bin/console app:forum:seed-categories` à lancer après migration Forum

---

## ALERTE PLANNING
Le 23 mai, il reste 23 jours. Priorité absolue : Messagerie privée (sans elle, la Communauté est incomplète).
Forum terminé. Lives = scope minimum. RGPD = non négociable avant le 15 juin.
Marge quasi nulle — refuser tout ajout de scope hors CDC V3.

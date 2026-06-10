---
name: project-context
description: Contexte projet Bazaart — deadline V1 15 juin 2026, stack technique, état d'avancement
metadata:
  type: project
---

Plateforme bazaart.fr pour artistes de la diaspora afro-atlantique.

**Why:** Lancement officiel 15 juin 2026 (clôture incubation Mansa). Planning serré.

**Stack:** Symfony 7.4 / PHP 8.3 / PostgreSQL 16 / Redis / Docker / Twig + Stimulus + Tailwind.

**Code Symfony dans:** `/Users/belamour/bazaart/app/`

**Chantier 2A — ScrapedResource deadlineDate + EventListener (27 mai 2026) :**
- Nouveau champ `deadlineDate` (datetime_immutable nullable) sur `ScrapedResource` — logique métier uniquement, géré par le listener
- `DeadlineParserService` — parse 3 formats (ISO 8601, français court JJ/MM/AAAA, français long "31 mai 2026"), jamais d'exception
- `ScrapedResourceListener` — EventListener #[AsEntityListener] prePersist+preUpdate : html_entity_decode sur title/description + parsing deadlineDate
- `ScrapedResourceRepository::archiveExpired()` — nouvelle version DQL (UPDATE direct, 1 requête), ancienne renommée `archiveExpiredLegacy()` @deprecated
- Feature flag `archive_use_legacy` dans /admin/settings pour rollback sans redéploiement
- `BackfillDeadlineDateCommand` (`app:backfill-deadline-date`) — one-shot idempotent --dry-run, 27/27 deadlines parsées sans erreur
- Migrations : `Version20260528000131` (ajout colonne) + `Version20260528000226` (nettoyage commentaire SQL)
- `LlmExtractorService::normalizeType()` — 5 nouveaux types (Mentorat, Tutorat, Accompagnement, Formation, Appel à candidatures) + ordre spécifique→générique dans le map

**CnmScraper — API REST WP (26 mai 2026, fin de journée) :**
- La page /appels-a-projets/ du CNM utilise WordPress Interactivity API (WP 6.5+) : contenu rendu côté client JS, DomCrawler ne peut pas lire les cartes depuis le HTML statique.
- Solution : utiliser l'API REST WordPress directement : `https://cnm.fr/wp-json/wp/v2/posts?categories=42` (ID 42 = catégorie "appels-a-projets", vérifié le 26 mai 2026).
- CnmScraper::scrapeApiPage() remplace scrapeAppelsPage() — httpClient vers JSON, pas de DomCrawler.
- Résultat : 49 opportunités (9 RSS + 40 API) au lieu de 9 auparavant.
- Règle à retenir : si un site WordPress est sur WP 6.5+ et utilise Query Loop Block, l'API REST WP est toujours plus fiable que le scraping HTML.

**Tests E2E PHPUnit — installés et 25/25 passants (26 mai 2026 soir) :**
- symfony/test-pack installé (PHPUnit 12.5) — base de test `bazaart_test` créée + migrée
- 5 classes E2E dans `tests/E2E/` : RegistrationTest, ResourceSubmissionTest, ForumThreadTest, MessagingTest, CourseEnrollmentTest
- AbstractE2ETestCase : purgeDatabase() TRUNCATE CASCADE, createTestUser/Artist/Admin/Regular/OrganizationProfile, loginAs(), getCsrfTokenFromHtml(), getCsrfToken()
- framework.yaml when@test : register_limiter limit 1000, CSRF gardé activé (tokens extraits depuis HTML des GET)
- Bug de routage ForumController corrigé : requirements['threadSlug'] = '(?!nouveau$).+' exclut le mot réservé "nouveau"
- Champ "content" (pas "body") dans ForumService::createThread(), "resourceTypeId" (pas "resourceType") dans ResourceService::createResource()

**Feature 2 — Découverte automatique de sources (26 mai 2026 après-midi) :**
- `SuggestedSourceStatus` enum (AValider/Validee/Rejetee)
- `ScrapingSource` — nouveau champ `estAgregateur` (bool, default false) + getter/setter
- `ScrapingSourceRepository` — méthode `findActiveAggregators()` ajoutée
- `SuggestedSource` — entité complète (table `suggested_sources`) + lifecycle callback PrePersist
- `SuggestedSourceRepository` — findAllByStatut, findPending, countByStatut, existsByUrl, findAllOrderedByDate
- Migration `Version20260526154321` — est_agregateur + table suggested_sources
- `LlmExtractorService` — méthode `discoverSources()` + private callMistralApiForDiscovery/callAnthropicApiForDiscovery + mapItemsToSources
- `SeedSettingsCommand` — 2 nouveaux settings : discovery_enabled (true) + discovery_max_suggestions (30)
- `SeedScrapingSourcesCommand` — champ est_agregateur ajouté sur toutes les sources (on-the-move/resartis/culture-moves-eu = true, reste = false)
- `DiscoverSourcesCommand` — commande app:discover-sources --dry-run --source=<slug>
- `AdminSuggestedSourceController` — routes index/validate/reject, CSRF, déduplication, création ScrapingSource
- `admin/suggested_sources.html.twig` — thème Street, 3 sections (À valider / Validées / Rejetées), formulaires CSRF inline
- `base_admin.html.twig` — lien "Découverte sources" ajouté dans la sidebar
- PHPStan : non installé dans le container (pas dans composer.json)

**État V1 (au 26 mai 2026) — Refonte scraping (matin) :**

**Refonte complète du système de scraping — 26 mai 2026 :**
- Nouveaux enums : `ScrapingSourceType` (RSS/HtmlLlm/HtmlCss) et `ScrapingRunStatus` (NeverRun/Success/Error)
- Nouvelle entité `ScrapingSource` (table `scraping_sources`) — sources gérées en BDD depuis /admin/scraping-sources
- `ScrapingSourceRepository` — findAllActive(), findAllOrderedByNom(), findBySlug(), findByUrl()
- `ScrapedResource` — nouveau champ `disciplines` (VARCHAR 255, nullable)
- Migration `Version20260526150555` — crée scraping_sources + ajoute disciplines sur scraped_resources
- `ScraperRegistry` — annuaire slug → classe PHP (10 scrapers), getBySlug(), getKnownSlugs()
- `GenericScraper` — scraper générique (RSS + HTML_LLM) pour sources sans classe dédiée
- `LlmExtractorService` — support Mistral Small 3.2 : callMistralApi() (response_format json_object natif), testMistralConnection(), cleanHtml() amélioré (supprime nav/header/footer/aside), extractFromHtml() switchable via setting 'llm_provider'
- `ScrapeOpportunitiesCommand` — refondé : liste depuis ScrapingSourceRepository (plus de liste hardcodée), ScraperRegistry, GenericScraper, markRunSuccess/Error sur chaque source
- `SeedScrapingSourcesCommand` — commande app:seed-scraping-sources (idempotente, 10 sources, dédup par URL)
- `SeedSettingsCommand` — 2 nouveaux settings : 'llm_provider' (default 'mistral') et 'mistral_api_key'
- `AdminScrapingSourceController` — CRUD admin /admin/scraping-sources (index, create, toggle, delete), CSRF, validation
- Template `admin/scraping_sources.html.twig` — thème Street, tableau avec badges statut + chips type
- `AdminSettingController` — route POST /admin/settings/test-mistral ajoutée
- Template `admin/settings.html.twig` — select pour llm_provider, bouton test Mistral AJAX
- `base_admin.html.twig` — lien "Sources scraping" ajouté dans la sidebar

**Scraping — Correctifs antérieurs (26 mai 2026 session précédente) :**
- AppSetting entity + AppSettingRepository (table `app_settings`) — clé API Anthropic, activation scraping
- SettingService (get/set/upsert) — lecture BDD des paramètres
- LlmExtractorService — extracteur LLM via claude-haiku-4-5, lit la clé depuis SettingService
- 3 scrapers européens : OnTheMoveScraper, ResartisScraper (verify_peer=false), CultureMovesEuropeScraper → EACEA
- ScrapedResourceStatus : case Archived = 'archived', ScrapedResourceRepository : archiveExpired()
- docs/scraping-cron.md — documentation cron DigitalOcean

**État V1 (au 25 mai 2026) :**
- Ressourcerie : 75% fait (Resource, OrganizationProfile avec champs Structure, enums ResourceStatus/SubmitterRole)
- Communauté / Forum : TERMINÉ le 23 mai — entités ForumCategory/ForumThread/ForumReply, ForumService, ForumController (9 routes), ForumVoter mis à jour, 4 templates, commande seed, migration Version20260523131534
- Communauté / Messagerie : TERMINÉ le 23 mai — entités Conversation/ConversationParticipant/Message, ConversationRepository/MessageRepository, MessagingVoter, MessagingService, MessagingController (4 routes), 3 templates, migration Version20260523133318 (à appliquer)
- Communauté / Lives : TERMINÉ le 25 mai — entités Live/LiveAttendee + enum LiveStatus, LiveRepository/LiveAttendeeRepository, LiveService (8 méthodes), LiveController (4 routes), AdminLiveController (5 routes), commande app:live:send-reminders, LiveVoter mis à jour (typé avec entité Live), 6 templates Twig (live/index, live/show, admin/live/index, admin/live/form, emails/live_reminder + live_cancellation html+txt). Migration Version20260525212745 appliquée.
- Communauté : reste notifications
- Formation : entités créées le 25 mai (Course, CourseModule, Lesson, LessonResource, CourseEnrollment, LessonProgress + enum CourseLevel + 6 repositories). Migration Version20260525100937 écrite manuellement (docker CLI non accessible). À appliquer : `php bin/console doctrine:migrations:migrate --no-interaction`.
- RGPD V1 : TERMINÉ le 25 mai — rate limiting /login (login_throttling security.yaml) + /register (RateLimiterFactory), 3 pages légales publiques (LegalController), espace RGPD (RgpdController/RgpdService), export JSON, anonymisation compte, bannière cookies dans base.html.twig, lien "Mes données" sidebar artiste. Migration Version20260525223758 appliquée (ajout anonymized_at sur users). Package symfony/rate-limiter installé.

**Entités existantes importantes :**
- `User` — rôles JSON, pas de `updatedAt`, bcrypt, a maintenant `anonymizedAt` (RGPD)
- `OrganizationProfile` — a déjà `isStructurePartner`, `structureActivatedAt`, `structureActivationValidatedBy`, `updatedAt`
- `Resource` — a déjà `submitterRole`, `autoPublished`, `publishedAt`, `validatedAt`, `validatedBy`
- Enums : `ResourceStatus`, `SubmitterRole`, `ArticleStatus`, `ScrapedResourceStatus`

**Voters créés le 22 mai 2026 :**
- `ResourceVoter`, `ForumVoter`, `StructureVoter`, `LiveVoter` dans `src/Security/Voter/`

**Chantier scraping WS3 — Orchestrateur RSS app:read-feeds (10 juin 2026) :**
- `FeedReadResult` (readonly DTO) — distingue échec fetch (success=false) de succès vide (success=true, items=[]). Méthodes factory ok()/failure().
- `FeedReaderService::readWithResult()` — nouvelle méthode rétro-compatible. read() délègue à readWithResult(). fetchFeedContentWithError() retourne aussi le message d'erreur.
- `ReadFeedsCommand` (app:read-feeds) — --dry-run + --source. Switch scraping_enabled respecté. Filtre PHP getType()===RSS. Suivi de santé : setLastSuccessfulFetch, resetConsecutiveFailures, incrementConsecutiveFailures, auto-désactivation à 5 échecs (niveau WARNING). Tableau récapitulatif SymfonyStyle. Log structuré par source + résumé global.
- `ScrapeOpportunitiesCommand` — skip type===RSS en tête de boucle (commentaire FR expliquant la raison). Import ScrapingSourceType ajouté.
- `docs/scraping-cron.md` — refondu : deux pipelines (RSS 6h, LLM 3x/sem), entrée cron RSS ajoutée, note migration Symfony Scheduler post-lancement.
- Validation : lint:container OK, doctrine:schema:validate OK (pas de migration = aucun changement de schéma en WS3). PHPStan non installé.
- dry-run app:read-feeds : 3 sources RSS, 24 items trouvés en 6478ms. app:scrape-opportunities : sources RSS absentes = exclusion OK.

**Chantier scraping WS2 — Pipeline RSS natif (10 juin 2026) :**
- `laminas/laminas-feed` (v2.26.1) installé — parse RSS 2.0 + Atom 1.0 + RSS 1.0 de façon unifiée
- `HtmlSanitizerService` — strip_tags total + html_entity_decode + collapse espaces + mb_substr 2000 chars. Aucune dépendance externe. Sécurité XSS + contrôle coût LLM.
- `PersistResult` (readonly DTO) — porte les compteurs inserted/reactivated/updated/skipped
- `ScrapedResourcePersister::persistBatch()` — factorise les 5 cas de dédup qui étaient inline dans ScrapeOpportunitiesCommand. Guard intra-lot + findByUrl() + flush() unique.
- `FeedReaderService::read()` — télécharge via HttpClient (15s, User-Agent BazaartBot), parse via laminas FeedReader::importString(), filtre mots-clés, détecte type, sanitize description via HtmlSanitizerService. Retourne ScrapedOpportunity[]. Pas de LLM, pas de persistance.
- `ScrapeOpportunitiesCommand` refactorisé — délègue la persistance à ScrapedResourcePersister (comportement identique). EntityManager conservé pour markRunSuccess/Error sur ScrapingSource + archiveExpired.
- `GenericScraper::scrapeRss()` marquée @deprecated — conservée, WS3 la contournera.
- PHPStan non installé dans le container (pas dans composer.json) — à signaler dans les rapports.

**Chantier RSS QA (10 juin 2026 — commits 2f2dfad + 600bc57) :**
- `DeadlineParserService::extractFromText()` — fallback supprimé : désormais UNIQUEMENT par mots-cues. Sans cue → null. Recette validée sur artcena.fr (11 items, deadline_date = NULL partout).
- `tests/Unit/Service/DeadlineParserServiceTest.php` — 5 tests unitaires (avec cue, sans cue, vide, ISO+cue, événement sans cue). Tous passants.
- PHPStan niveau 6 installé : phpstan/phpstan ^2.2, phpstan/extension-installer ^1.4, phpstan/phpstan-doctrine ^2.0
- `app/phpstan.neon` — config niveau 6, périmètre src/, extension Doctrine, baseline incluse
- `app/phpstan-baseline.neon` — gèle 79 erreurs préexistantes hors périmètre RSS
- `app/tests/bootstrap_phpstan.php` — loader ObjectManager pour phpstan-doctrine
- Erreurs corrigées périmètre RSS : 6 erreurs → 0 (DeadlineParserService, FeedReaderService, ScrapedResourcePersister, ScrapeOpportunitiesCommand)
- ResourceVoter : 3 annotations @phpstan-ignore-line obsolètes supprimées
- Sortie PHPStan finale : 0 erreur (79 baselinées). lint:container OK, schema:validate OK.

**How to apply:** Consulter `docs/cahier-des-charges-v3.md` avant toute tâche d'architecture. Priorité au fonctionnel V1.

---
name: feedback_e2e_tests_patterns
description: Patterns et anti-patterns identifiés lors de la relecture de l'infrastructure de tests E2E (relecture 2026-05-26)
metadata:
  type: feedback
---

Relecture des 5 tests E2E + AbstractE2ETestCase + AppFixtures + phpunit.dist.xml + .env.test (2026-05-26).
Tous les 25 tests passent (PHPUnit 12.5.27, 71 assertions).

**Points corrects à ne pas signaler en fausse alerte :**
- `purgeDatabase()` utilise `RESTART IDENTITY CASCADE` — le CASCADE propage la troncature aux tables liées (posts, comments, notifications, resource_disciplines, etc.) même si elles ne sont pas listées explicitement.
- La méthode `getCsrfToken(string $tokenId)` dans AbstractE2ETestCase est déclarée mais non utilisée par les tests actuels — elle est là comme utilitaire préventif pour le ForumThreadTest (documenté dans le commentaire). Ne pas signaler comme code mort critique.
- Le champ `confirm_password` envoyé dans RegistrationTest::testRegisterWithValidData() est inoffensif — RegisterDTO::fromArray() l'ignore (tableau passthrough). Pas de bug, juste du bruit dans la requête.
- bcrypt `cost: 4` dans security.yaml `when@test` est intentionnel et acceptable (minimum autorisé).
- `REMOTE_ADDR` dans `trusted_proxies` est la configuration recommandée pour un seul serveur Docker.
- `.env.test` contient uniquement des valeurs factices pour les services tiers — aucun secret réel.
- `requirements: ['threadSlug' => '(?!nouveau$).+']` est une lookahead négative ANSI correcte. Elle exclut UNIQUEMENT le segment exact "nouveau" (minuscules), ce qui est le comportement voulu (les slugs "nouveau-a1b2c3" passent bien).

**Anti-patterns et points de vigilance trouvés :**

1. POLITIQUE MOT DE PASSE INCOHÉRENTE (CDC §9 : min 10 chars + majuscule + chiffre) :
   - RegisterDTO::isPasswordStrong() vérifie uniquement >= 8 caractères, sans vérifier majuscule ni chiffre.
   - AuthController affiche "au moins 8 caractères" (pas 10).
   - RegistrationTest commente "Respecte les règles : 8+ chars" — le test documente la règle incorrecte.
   - testRegisterWithShortPasswordShowsError() envoie "court" (5 chars) — un mot de passe de 9 chars passerait alors que le CDC l'interdit.
   - À corriger dans RegisterDTO + AuthController + commentaires des tests.

2. FORM_LOGIN CSRF DÉSACTIVÉ (enable_csrf: false par défaut Symfony) :
   - security.yaml ne définit pas `enable_csrf: true` sur form_login.
   - `debug:config security` confirme : `enable_csrf: false`.
   - RegistrationTest::testLoginWithValidCredentials() récupère et envoie un _csrf_token qui est silencieusement IGNORÉ par Symfony Security.
   - Le test passe grâce aux credentials valides, pas grâce au CSRF.
   - Ne couvre pas le cas "tentative de login avec CSRF invalide" — si enable_csrf était activé, un token vide devrait être rejeté.

3. PURGE N'INCLUT PAS app_settings, scraping_sources, suggested_sources :
   - Ces 3 tables existent en BDD de test mais ne sont pas listées dans TRUNCATE.
   - `RESTART IDENTITY CASCADE` ne les atteint PAS car elles n'ont pas de FK vers les tables listées (vérification pg_constraint confirmée).
   - Si des tests futurs créent des AppSetting ou ScrapingSource, il y aura une pollution inter-tests.
   - Pas critique pour les 5 tests actuels, mais la liste devra être mise à jour.

4. COUVERTURE PARTIELLE DES CAS D'ERREUR FORMULAIRES :
   - Aucun test pour une soumission de ressource avec des champs manquants (titre vide, description trop courte).
   - Aucun test pour un token CSRF invalide → ResourceController / ForumController.
   - Aucun test pour une double inscription (CourseEnrollmentTest manque le scénario "déjà inscrit").
   - Non bloquant pour V1 (couverture 30% cible), mais à noter pour la roadmap tests V2.

5. PHPSTAN NON INSTALLÉ dans le container bazaart_app :
   - `vendor/bin/phpstan` absent du container.
   - Le CLAUDE.md exige PHPStan niveau 6 sur tous les nouveaux modules.
   - phpunit.dist.xml n'inclut pas PHPStan en extension.
   - Relecture manuelle des types effectuée à la place : aucune violation critique détectée.

**Why:** L'infrastructure de tests est globalement saine et fonctionnelle. Les risques principaux sont la politique de mot de passe non conforme au CDC et le CSRF login non testé.
**How to apply:** Vérifier systématiquement lors des relectures futures : (1) politique mdp dans DTO vs CDC §9, (2) enable_csrf sur form_login, (3) liste des tables dans purgeDatabase() après nouvelles migrations.

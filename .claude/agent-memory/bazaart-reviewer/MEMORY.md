# MEMORY — Relecteur QA bazaart.fr

- [getRoles() vs isGranted() — hiérarchie Symfony non propagée](feedback_getRoles_hierarchy.md) — Anti-pattern récurrent : voters qui vérifient les rôles via getRoles() brut au lieu de Security::isGranted()
- [method_exists() placeholder dans les voters anticipatoires](feedback_method_exists_placeholder.md) — Hack documenté acceptable en V1 pour ForumVoter/LiveVoter ; à upgrader vers interface en V2
- [Paramètre $user inutilisé dans les méthodes always-true](feedback_canSubmit_parameter_unused.md) — canSubmit/canCreate/canRegister reçoivent $user sans l'utiliser
- [État des Voters V1 — bogue critique StructureVoter](project_v1_voters.md) — ROLE_ADMIN bloqué sur dashboard structure à cause de getRoles() brut (corrigé dans la PR Structure mai 2026)
- [Migration manquante — colonnes Structure](feedback_migration_manquante_structure.md) — Anti-pattern récurrent : colonnes entité ajoutées sans migration Doctrine correspondante
- [Union type Entity|string comme signal d'erreur](feedback_apply_union_type_antipattern.md) — Service retourne string pour erreur de validation : fragile, à remplacer par exception ou objet résultat
- [Flash 'info' invisible dans base_admin](feedback_flash_info_invisible_admin.md) — base_admin.html.twig ne rend que 'success' et 'error', jamais 'info'
- [CASE WHEN dans DQL orderBy() — syntaxe risquée](feedback_dql_case_orderby.md) — ResourceAlertRepository utilise une expression CASE SQL brute dans orderBy() DQL ; peut échouer selon la version Doctrine
- [Routes avec préfixe de classe — nommage concaténé](feedback_route_prefix_concatenation.md) — `name: 'app_resource_'` sur la classe + `name: 'show'` sur la méthode = `app_resource_show` : ne pas signaler en fausse alerte
- [Module Forum — patterns et anti-patterns identifiés](feedback_forum_module_patterns.md) — XSS contenu brut, N+1 index, ordre CSRF/autorisation inversé, collision slug inter-catégories
- [Module Messagerie — patterns et anti-patterns identifiés](feedback_messaging_module_patterns.md) — N+1 compteurs non-lus, redirect sans ancre, removeParticipant() bug copier-coller, DQL brut andWhere(), NULLS LAST manquant PostgreSQL
- [Module Notifications — patterns et anti-patterns identifiés](feedback_notifications_module_patterns.md) — IDOR silencieux markAsRead, XSS préventif data.threadTitle, PII email en JSON, N+1 Twig extension sans mémoise, getLink() URLs en dur
- [Fixtures : autoPublished toujours false — incohérence métier](feedback_fixtures_autopublished.md) — autoPublished doit être true pour admin/structure, false uniquement pour artist
- [Fixtures : validatedAt/validatedBy non-null pour auto-publiées — incohérence](feedback_fixtures_validatedby_structure.md) — ces champs doivent rester null pour les ressources structure/admin
- [Templates Twig Ressources — patterns et anti-patterns identifiés](feedback_twig_resource_patterns.md) — CSRF absent submit.html.twig, statusFilter non transmis par my(), N+1 disciplines dans favorites, badge--archived sans style, pagination KNP orpheline
- [Templates Twig Auth + Community — patterns et anti-patterns identifiés](feedback_twig_auth_and_community_patterns.md) — CSRF register ignoré par contrôleur, edit.html.twig sans action= ni CSRF, URL AJAX like en dur dans feed.html.twig

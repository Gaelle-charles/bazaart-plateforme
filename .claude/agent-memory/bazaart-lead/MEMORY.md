# MEMORY — Bazaart Lead Agent

- [Profil utilisatrice](user_gaelle.md) — Gaëlle, co-fondatrice Pôle Lab, dev full-stack en apprentissage, délai serré V1 15 juin 2026
- [État V1 (MAJ 13 juin)](project_v1_status.md) — Plateforme ~95% : 3 modules fonctionnels ; reste = opérationnel (contenu + crons) + 2 gaps UX admin ; souhait scraping élargi V2
- [Workflow Git](feedback_git_workflow.md) — main=stable, demo=travail, jamais de feature/* ni dev, merger uniquement sur confirmation Gaëlle
- [Audit design Street (24 mai)](project_design_audit.md) — Statuts conformité par template, 3 critiques (home, CSRF, XSS), sidebar mobile absente
- [⚠️ Rappel lancement — pages légales](project_legal_reminder.md) — placeholders [À COMPLÉTER] à remplir avant le 15 juin (SIRET, adresse, DPO, raison sociale)
- [⚠️ Infra plateforme & cohabitation vitrine](project_plateforme_infra.md) — app.bazaart.fr (bazaart_platform_app) cohabite avec la vitrine sur le même droplet ; règles non négociables + pièges nginx (incident 11 juin) ; ancienne vitrine Next.js supprimée 15 juin ; swap 1Go configuré
- [Mise en service manuelle](project_mise_en_service_manuelle.md) — code déployé ≠ en service : seeds, clés en BDD, cron à lancer à la main ; checklist + récup accès admin + bug Resartis SSL
- [Validation Twig](feedback_twig_validation.md) — lint:twig ≠ test de rendu : vérifier code HTTP + taille après déploiement d'un template dynamique (incident timezone 'fr' 14 juin)
- [Pas de tiret cadratin](feedback_no_em_dash.md) — ne JAMAIS utiliser « — » dans les titres et le contenu du site (préférence éditoriale Gaëlle)
- [SEO technique à faire (19-20 juin)](project_seo_todo.md) — sitemap.xml, robots.txt, JSON-LD Article planifiés ; blog déjà public + meta + articles réécrits (15 juin)
- [Intégration Stripe (15 juin)](project_stripe.md) — IDs produits/prix LIVE+TEST, webhook créé, architecture Option B (pas de Connect), entités Subscription+CoursePayment, vars env prod à configurer

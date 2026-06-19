# MEMORY — Bazaart Lead Agent

- [Profil utilisatrice](user_gaelle.md) — Gaëlle, co-fondatrice Pôle Lab, dev full-stack en apprentissage, délai serré V1 15 juin 2026
- [État V1 (MAJ 15 juin)](project_v1_status.md) — Plateforme ~97% : tous modules déployés prod ; reste = switcher Stripe LIVE + crons + légal + 1 formation + tests
- [Workflow Git](feedback_git_workflow.md) — main=stable, demo=travail, jamais de feature/* ni dev, merger uniquement sur confirmation Gaëlle
- [Audit design Street (24 mai)](project_design_audit.md) — Statuts conformité par template, 3 critiques (home, CSRF, XSS), sidebar mobile absente
- [⚠️ Rappel lancement — pages légales](project_legal_reminder.md) — placeholders [À COMPLÉTER] à remplir avant le 15 juin (SIRET, adresse, DPO, raison sociale)
- [⚠️ NE JAMAIS confondre les sites](feedback_site_naming.md) — on développe app.bazaart.fr (plateforme), JAMAIS bazaart.fr (vitrine, jamais touchée/supprimée)
- [⚠️ Infra plateforme & cohabitation vitrine](project_plateforme_infra.md) — app.bazaart.fr (bazaart_platform_app) cohabite avec la vitrine sur le même droplet ; règles non négociables + pièges nginx (incident 11 juin) ; ancienne vitrine Next.js supprimée 15 juin ; swap 1Go configuré
- [Mise en service manuelle](project_mise_en_service_manuelle.md) — code déployé ≠ en service : seeds, clés en BDD, cron à lancer à la main ; checklist + récup accès admin + bug Resartis SSL
- [Validation Twig](feedback_twig_validation.md) — lint:twig ≠ test de rendu : vérifier code HTTP + taille après déploiement d'un template dynamique (incident timezone 'fr' 14 juin)
- [Animations = scroll-linked réversible](feedback_animations_scroll.md) — animations pilotées par le scroll (up/down), pas reveal-once ; « retirer X » ≠ retirer l'animation autour (incident hero 19 juin)
- [Pas de tiret cadratin](feedback_no_em_dash.md) — ne JAMAIS utiliser « — » dans les titres et le contenu du site (préférence éditoriale Gaëlle)
- [SEO technique à faire (19-20 juin)](project_seo_todo.md) — sitemap.xml, robots.txt, JSON-LD Article planifiés ; blog déjà public + meta + articles réécrits (15 juin)
- [Intégration Stripe (15 juin)](project_stripe.md) — IDs produits/prix LIVE+TEST, webhook créé, architecture Option B (pas de Connect), entités Subscription+CoursePayment, vars env prod à configurer
- [⚠️ Docker local éteint](feedback_docker_local.md) — état Docker local variable, vérifier `docker ps` ; service prod = `platform_app` dans `docker-compose.prod.yml`
- [Design prototype Street](reference_design_prototype.md) — standalone.html = appli React (JS externes absents) ; design system dans le CSS, écrans précis → demander une capture
- [⚠️ Site « down » côté Gaëlle](feedback_site_down_client_side.md) — vérifier d'ABORD son Mac/réseau (NAT64 `64:ff9b::`, iCloud Private Relay) avant de toucher au serveur (incident 16 juin)
- [⚠️ Incident réseau Mac en cours](project_mac_network_incident.md) — stack réseau incohérent après cmds diagnostic ; prochaine étape = redémarrer le Mac et retester

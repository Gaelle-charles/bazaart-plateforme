---
name: project-v1-status
description: État précis du code V1 au 15 juin 2026 — ce qui est fait, ce qui manque, par module
metadata:
  type: project
---

État mis à jour le 2026-06-15. **Deadline V1 : 2026-06-22** (confirmée par Gaëlle).

**Why:** Planning CDC V3 — deadline Mansa immuable. Utiliser ce tableau pour prioriser.
**How to apply:** Toujours vérifier ici avant de planifier une nouvelle tâche.

## MAJ 2026-06-15 — plateforme déployée sur app.bazaart.fr, ~97% V1

### CE QUI A ÉTÉ FAIT DANS CETTE SESSION (15 juin)

#### Forum — compositeur inline ✅
- Nouvelle route `POST /forum/nouveau` → `ForumController::quickPost()` (`app_forum_quick_post`)
- Formulaire inline sur `forum/index.html.twig` : 2 états (replié/étendu) via JS vanilla
- État replié : `[Avatar] [placeholder] [Écrire]`
- État étendu : `[Avatar] [Nouveau post...] [Annuler]` + champs (catégorie select, titre, textarea)
- Bug CSS corrigé : `[hidden] { display: none !important }` dans le `<style>` du template
  (sans ça, `display: flex` de `.fi-composer__form-area` écrasait l'attribut `hidden`)
- UX : catégorie à un seul endroit (dans le formulaire), fonds blancs (#fff) sur les champs
- Clés Stripe LIVE interceptées par GitHub Push Protection (jamais pushées) → redactées dans project_stripe.md

#### Stripe — intégration complète ✅ (faite avant cette session)
- Abonnements mensuel (9,90€) et annuel (79€) via Stripe Checkout (mode subscription)
- Paiements formations via Stripe Checkout (mode payment, un achat par formation)
- Webhook `/stripe/webhook` (PUBLIC_ACCESS) + vérification HMAC
- Entités : `Subscription`, `CoursePayment`, champs Stripe sur `Course`
- Route `/tarifs` publique dans nav
- Architecture Option B (pas de Connect, reversements manuels)
- En **mode TEST** sur le serveur (sk_test_xxx) — à switcher en LIVE après validation

#### Blog — seeds + images ✅ (faite avant cette session)
- 4 articles publiés avec images Unsplash, blog public pour SEO

#### Forum — seeds prod ✅
- `app:forum:seed-categories` exécutée : 5 catégories + 5 threads d'amorce en prod
- Bug corrigé : filtre ROLE_ADMIN en PHP (colonne JSON roles incompatible avec LIKE PostgreSQL)

---

## ✅ MODULES COMPLETS (voir historique sessions précédentes pour détails)

- **Ressourcerie** : ROLE_STRUCTURE, scraping, voters, alertes email
- **Forum** : 10 routes + compositeur inline (15 juin)
- **Messagerie** : conversations 1-à-1
- **Notifications** : temps réel
- **Formation** : catalogue + parcours apprenant + Stripe paiement
- **Lives** : planification + rappels
- **Blog** : public, SEO, articles seedés
- **RGPD** : pages légales, export JSON, anonymisation
- **Scraping** : 10 sources actives, LLM Mistral + Claude fallback

---

## ❌ CE QUI RESTE (V1 — livraison 22 juin)

### OPÉRATIONNEL (priorité max)
- [ ] Switcher Stripe de TEST → LIVE sur le serveur (`.env.local` prod : `sk_live_xxx`)
- [ ] Activer cron `app:send-resource-alerts` (quotidien)
- [ ] Activer cron `app:live:send-reminders` (horaire)
- [ ] Remplir les placeholders légaux [À COMPLÉTER] : SIRET, adresse, DPO (voir [[project-legal-reminder]])
- [ ] Créer au moins 1 formation publiée en admin avant le lancement
- [ ] Tester le flow Stripe LIVE complet (abonnement + achat formation)

### TECHNIQUE
- [ ] PHPUnit 30% couverture (5 scénarios E2E)
- [ ] `DEFAULT_URI=https://app.bazaart.fr` dans `.env.local` prod (pour les emails)

### SEO (19-20 juin selon plan)
- [ ] sitemap.xml, robots.txt, JSON-LD Article (voir [[project-seo-todo]])

---

## COMMANDE DÉPLOIEMENT PROD (à retenir)
```bash
# Sur le serveur
cd /root/bazaart-plateforme
git pull origin main
docker compose -f docker-compose.prod.yml exec platform_app php bin/console cache:clear
```
Le service se nomme `platform_app` dans `docker-compose.prod.yml` (pas `bazaart_platform_app`).

## DÉCISIONS PRODUIT (ne pas revenir dessus)
- Email confirmation inscription Formation → V2
- Bunny Stream player → V2
- Stripe Connect → V2 (reversements manuels en V1)
- Scraping LinkedIn + web ouvert élargi → V2

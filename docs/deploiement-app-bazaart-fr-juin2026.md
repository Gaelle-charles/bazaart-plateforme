# Runbook de mise en ligne — app.bazaart.fr (juin 2026)

Mise en production de la plateforme **app.bazaart.fr** (conteneur `bazaart_platform_app`).
Contenu livré depuis le dernier déploiement : module Formation (formations-événement +
paiement + annulation/remboursement), confirmation d'email + onboarding obligatoire,
Ressourcerie enrichie (discipline/ville/pays/niveau, import CSV, dédup, masquage deadlines
passées, découverte d'URL-liste).

> ⚠️ RÈGLE ABSOLUE : on ne touche QUE la plateforme (`bazaart_platform_app` /
> `/root/bazaart-plateforme` / `bazaart_platform_postgres`). **JAMAIS la vitrine bazaart.fr**
> (`bazaart_app`, `/root/bazaart2`). nginx est partagé : prudence.
> Aucune commande destructive sans accord explicite de Gaëlle.

---

## 0. Pré-requis / rappels infra
- Dépôt : `Gaelle-charles/bazaart-plateforme.git`, branche **`main`** = déployée.
- Serveur : `/root/bazaart-plateforme`, compose `docker-compose.prod.yml`, service `platform_app`,
  conteneur `bazaart_platform_app` (code `/var/www/html`), BDD `bazaart_platform_postgres`.
- La clé Mistral et les réglages LLM sont déjà en BDD prod (`app_settings`).

## 1. Sauvegarde AVANT toute chose (obligatoire)
```bash
# Sur le droplet
docker exec bazaart_platform_postgres pg_dump -U <user> bazaart > /root/backups/platform_$(date +%F_%H%M).sql
# Tag de l'état actuel de main (rollback rapide)
cd /root/bazaart-plateforme && git tag pre-deploy-$(date +%F-%H%M)
```

## 2. Pré-check CRITIQUE — éviter le lock-out (confirmation d'email)
Le nouveau gating bloque les comptes `is_verified = false`. La migration de backfill
(`Version2026...` créée à cet effet) passe les comptes existants à `is_verified = true`.
→ Elle s'exécute à l'étape 5 (migrate). **Vérifier juste après migrate** qu'aucun compte
existant n'est resté à false (cf. étape 6). Idéalement, faire le déploiement en heure creuse
(fenêtre courte entre git pull et migrate).

Vérif lecture seule AVANT (pour mesurer le risque) :
```bash
docker exec bazaart_platform_postgres psql -U <user> -d bazaart -c \
  "SELECT count(*) FILTER (WHERE is_verified=false) AS non_verifies, count(*) AS total FROM users;"
```

## 3. Merge demo -> main (sur feu vert Gaëlle UNIQUEMENT)
```bash
# En local
git checkout main && git pull origin main
git merge --no-ff demo
git push origin main
```

## 4. Déploiement sur le droplet
```bash
cd /root/bazaart-plateforme
git pull origin main
# Dépendance ajoutée (symfonycasts/verify-email-bundle) -> composer install obligatoire
docker exec bazaart_platform_app composer install --no-dev --optimize-autoloader
```

## 5. Migrations (toutes les nouvelles passent ici)
```bash
docker exec bazaart_platform_app php bin/console doctrine:migrations:migrate --no-interaction
```
Migrations attendues : formations-événement (`Version20260618154123`), remboursement
(`181356`), onboarding (`210427`), ressourcerie champs (`222051`), + backfill `is_verified`
(`Version2026...`). Puis :
```bash
docker exec bazaart_platform_app php bin/console cache:clear --env=prod
docker exec bazaart_platform_app php bin/console cache:warmup --env=prod
```

## 6. Vérifications post-déploiement
- `is_verified` : `SELECT count(*) FILTER (WHERE is_verified=false) FROM users;` doit être 0
  (sinon les comptes restants seraient bloqués → corriger).
- `onboarding_completed` des comptes existants = true (migration `210427`).
- Connexion d'un compte EXISTANT → OK (pas bloqué, pas forcé en onboarding).
- Pages clés en 200 : accueil plateforme, `/resources`, `/formations`, `/dashboard`, `/admin`.
- `doctrine:schema:validate` OK.

## 7. Mise en service manuelle (le code seul ne suffit pas)
- **Stripe LIVE** (formations-événement payantes + remboursements) : clés LIVE dans la config
  prod + enregistrer le webhook `charge.refunded` (et les events de paiement) sur l'endpoint
  Stripe de la plateforme.
- **Découverte/scraping** : valider `app:discover-listing-urls --url=... -v` sur le droplet
  (réseau prod), puis lancer la découverte CSV puis le scraping ; vérifier le cron.
- **MAILER_DSN** : la prod utilise Brevo (déjà en `.env.local` prod) — vérifier l'envoi réel
  des emails (confirmation + bienvenue + annulation).

## 8. Rollback (si problème)
```bash
cd /root/bazaart-plateforme && git reset --hard pre-deploy-<tag>   # avec accord Gaëlle
docker exec ... pg_restore/psql < /root/backups/platform_<...>.sql # si migration à annuler
docker exec bazaart_platform_app php bin/console cache:clear --env=prod
```

## 9. À finaliser hors technique (Gaëlle / comité)
- **CGV** : politique de remboursement (rétractation 14 j + avant événement) à publier.
- Pages légales `[À COMPLÉTER]` (SIRET, adresse, DPO) avant usage réel du paiement.

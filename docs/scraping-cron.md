# Configuration du cron pour le scraping automatique

## Vue d'ensemble : deux pipelines, deux cadences

Depuis le chantier WS3 (juin 2026), le scraping est divisé en **deux commandes distinctes**
avec des cadences adaptées à leur coût et leur nature :

| Commande | Sources traitées | Cadence | Coût | Fichier de log |
|---|---|---|---|---|
| `app:read-feeds` | Flux RSS uniquement | **Toutes les 6h** | Léger (HTTP + XML) | `bazaart-feeds.log` |
| `app:scrape-opportunities` | HTML+LLM et HTML+CSS | **3x/semaine** (lun/mer/ven 7h) | Coûteux (clé API LLM) | `bazaart-scraping.log` |

### Pourquoi deux cadences ?

- **RSS (léger, fréquent)** : lire un flux RSS se résume à un téléchargement HTTP + parsing XML.
  Pas de clé API, pas de LLM, exécution < 5s par source. On peut se permettre une fréquence
  élevée (6h) pour rester réactif aux nouvelles opportunités publiées.

- **Scrape HTML+LLM (coûteux, espacé)** : chaque source envoie un HTML volumineux à un LLM
  (Anthropic/Mistral) via API payante. Le coût en tokens et en latence impose une cadence raisonnable
  (3x/semaine). Certaines sources ont aussi des pages qui changent lentement.

### Note sur la migration future

> **Hors scope actuel (V1)** : l'orchestration est assurée par cron car Supervisor n'est pas
> opérationnel sur la droplet au moment du développement V1. Une migration vers
> **Symfony Scheduler + worker Messenger** est prévue post-lancement pour bénéficier
> d'un suivi des jobs, de retries automatiques et d'une interface de monitoring.

---

## Objectif

Lancer automatiquement les deux commandes de scraping selon leurs cadences respectives
sur le serveur DigitalOcean de production.

---

## Prérequis

1. **Clé API Anthropic configurée** dans l'admin : `/admin/settings` → champ `anthropic_api_key`
   Sans cette clé, les scrapers HTML+LLM (On The Move, Resartis, Culture Moves Europe) retourneront [] silencieusement.
   Les flux RSS (`app:read-feeds`) ne nécessitent **pas** de clé API.

2. **Containers Docker lancés** au moment du cron :
   ```bash
   docker compose ps   # Vérifier que le container "app" est Up
   ```

3. **Répertoire de déploiement connu** : `/home/bazaart` (ou adapter selon la configuration du serveur).

---

## Configuration crontab sur le serveur

Se connecter au serveur DigitalOcean et éditer la crontab root :

```bash
ssh root@206.189.3.112
sudo crontab -e
```

Ajouter les deux lignes suivantes :

```cron
# ── Flux RSS : toutes les 6h (léger, pas de LLM) ─────────────────────────────────
0 */6 * * * cd /home/bazaart && docker compose exec -T app php bin/console app:read-feeds --env=prod 2>&1 >> /var/log/bazaart-feeds.log

# ── Scraping HTML+LLM : 3x/semaine lun/mer/ven à 7h00 UTC (coûteux, clé API) ─────
0 7 * * 1,3,5 cd /home/bazaart && docker compose exec -T app php bin/console app:scrape-opportunities --env=prod 2>&1 >> /var/log/bazaart-scraping.log
```

### Explication de l'expression cron RSS (`0 */6 * * *`)

| Partie | Valeur | Signification |
|--------|--------|---------------|
| `0` | Minute | À la minute 0 |
| `*/6` | Heure | Toutes les 6 heures : 0h, 6h, 12h, 18h UTC |
| `* * *` | Jour/mois/jour-semaine | Tous les jours, tous les mois |

Exemples d'exécution : 0h00, 6h00, 12h00, 18h00 UTC = 2h/8h/14h/20h heure de Paris (été, CEST UTC+2).

### Explication de l'expression cron scrape (`0 7 * * 1,3,5`)

| Partie | Valeur | Signification |
|--------|--------|---------------|
| `0 7 * * 1,3,5` | Expression cron | 7h00 UTC, lundi (1), mercredi (3), vendredi (5) |
| `cd /home/bazaart` | Changement de répertoire | **OBLIGATOIRE** — `docker compose` doit être lancé depuis le répertoire contenant `docker-compose.yml` |
| `&&` | Opérateur conditionnel | N'exécute la suite que si le `cd` réussit |
| `docker compose exec -T` | Commande Docker | `-T` est **obligatoire en cron** (expliqué ci-dessous) |
| `app` | Nom du container | Doit correspondre au nom dans `docker-compose.yml` |
| `--env=prod` | Environnement Symfony | Force l'utilisation de la config production |
| `2>&1` | Redirection stderr | Redirige les erreurs dans le même flux que stdout |
| `>> /var/log/bazaart-*.log` | Redirection sortie | Ajoute les logs à la suite du fichier (pas d'écrasement) |

---

---

## Pourquoi `-T` est obligatoire en cron

La commande `docker compose exec` alloue par défaut un TTY (terminal interactif), ce qui nécessite une session terminal interactive.

Un processus cron **n'a pas de TTY** — il tourne en arrière-plan sans terminal.

Sans `-T`, Docker affiche une erreur et la commande échoue :
```
the input device is not a TTY
```

Avec `-T` (disable pseudo-TTY allocation), Docker n'essaie pas d'allouer un TTY et la commande fonctionne en mode non-interactif.

---

---

## Gestion des logs

Les fichiers `/var/log/bazaart-feeds.log` et `/var/log/bazaart-scraping.log` grossissent à chaque exécution.
Pour éviter qu'ils prennent trop de place :

### Option 1 : logrotate (recommandée pour la production)

Créer le fichier `/etc/logrotate.d/bazaart-scraping` :

```
/var/log/bazaart-feeds.log
/var/log/bazaart-scraping.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
}
```

### Option 2 : nettoyage manuel mensuel

```bash
# Sur le serveur, supprimer les logs de plus de 30 jours
find /var/log/ -name "bazaart-feeds.log" -mtime +30 -delete
find /var/log/ -name "bazaart-scraping.log" -mtime +30 -delete
```

### Option 3 : limiter la taille dans le cron (approche simple)

```cron
# Limite à 10MB pour les feeds RSS
0 */6 * * * cd /home/bazaart && docker compose exec -T app php bin/console app:read-feeds --env=prod 2>&1 | tail -c 10485760 > /var/log/bazaart-feeds.log

# Limite à 10MB pour le scrape LLM
0 7 * * 1,3,5 cd /home/bazaart && docker compose exec -T app php bin/console app:scrape-opportunities --env=prod 2>&1 | tail -c 10485760 > /var/log/bazaart-scraping.log
```

---

---

## Vérification

Après avoir configuré le cron, vérifier :

```bash
# Voir la crontab configurée
sudo crontab -l

# Tester manuellement les deux commandes (hors cron, mode dry-run)
cd /home/bazaart && docker compose exec -T app php bin/console app:read-feeds --env=prod --dry-run
cd /home/bazaart && docker compose exec -T app php bin/console app:scrape-opportunities --env=prod --dry-run

# Vérifier les logs après les premiers crons automatiques
tail -f /var/log/bazaart-feeds.log
tail -f /var/log/bazaart-scraping.log
```

---

## Ajustement du fuseau horaire

La crontab s'exécute en **UTC**. Le serveur DigitalOcean est configuré en UTC.

7h00 UTC = 9h00 heure de Paris en été (CEST, UTC+2) = 8h00 en hiver (CET, UTC+1).

Pour lancer à 9h00 heure de Paris été/hiver de façon invariante, remplacer `0 7` par `0 8` (UTC+1 hiver) ou `0 7` (UTC+2 été) — ou utiliser deux entrées cron selon la saison. La solution la plus simple reste UTC.

---

---

## Lancer un seul pipeline en debug (option --source)

Les deux commandes acceptent l'option `--source="Nom exact"` pour cibler une seule source.

```bash
# Déboguer un seul flux RSS
docker compose exec app php bin/console app:read-feeds --source="CNM - Centre National de la Musique" --dry-run

# Déboguer un seul scraper HTML+LLM
docker compose exec app php bin/console app:scrape-opportunities --source="on-the-move.org" --dry-run
```

### Sources RSS connues
Ces sources sont désormais traitées par `app:read-feeds` (plus par `app:scrape-opportunities`) :
- `cnm.fr`, `cnap.fr`, et toute source avec `type = RSS` dans la BDD
  (visible depuis `/admin/scraping-sources` → colonne "Type")

### Sources HTML+LLM / HTML+CSS
Ces sources restent traitées par `app:scrape-opportunities` :
- `on-the-move.org`, `resartis.org`, `culturemoveseurope.eu`, `prohelvetia.ch`, `saif.fr`,
  `musiquesactuelles.fr`, `adagp.fr`, `culture.gouv.fr`, `cnap.fr` (si type HtmlCss)

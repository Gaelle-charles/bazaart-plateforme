# Configuration du cron pour le scraping automatique

## Objectif

Lancer automatiquement `app:scrape-opportunities` trois fois par semaine (lundi, mercredi, vendredi à 7h00 UTC) sur le serveur DigitalOcean de production.

---

## Prérequis

1. **Clé API Anthropic configurée** dans l'admin : `/admin/settings` → champ `anthropic_api_key`
   Sans cette clé, les scrapers européens (On The Move, Resartis, Culture Moves Europe) retourneront [] silencieusement.

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

Ajouter la ligne suivante :

```cron
# Scraping automatique 3x/semaine (lun/mer/ven à 7h00 UTC)
0 7 * * 1,3,5 cd /home/bazaart && docker compose exec -T app php bin/console app:scrape-opportunities --env=prod 2>&1 >> /var/log/bazaart-scraping.log
```

**Explication de chaque partie :**

| Partie | Valeur | Signification |
|--------|--------|---------------|
| `0 7 * * 1,3,5` | Expression cron | 7h00 UTC, lundi (1), mercredi (3), vendredi (5) |
| `cd /home/bazaart` | Changement de répertoire | **OBLIGATOIRE** — `docker compose` doit être lancé depuis le répertoire contenant `docker-compose.yml` |
| `&&` | Opérateur conditionnel | N'exécute la suite que si le `cd` réussit |
| `docker compose exec -T` | Commande Docker | `-T` est **obligatoire en cron** (expliqué ci-dessous) |
| `app` | Nom du container | Doit correspondre au nom dans `docker-compose.yml` |
| `php bin/console app:scrape-opportunities` | Commande Symfony | La commande principale de scraping |
| `--env=prod` | Environnement Symfony | Force l'utilisation de la config production |
| `2>&1` | Redirection stderr | Redirige les erreurs dans le même flux que stdout |
| `>> /var/log/bazaart-scraping.log` | Redirection sortie | Ajoute les logs à la suite du fichier (pas d'écrasement) |

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

## Gestion des logs

Le fichier `/var/log/bazaart-scraping.log` grossit à chaque exécution. Pour éviter qu'il prenne trop de place :

### Option 1 : logrotate (recommandée pour la production)

Créer le fichier `/etc/logrotate.d/bazaart-scraping` :

```
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
find /var/log/ -name "bazaart-scraping.log" -mtime +30 -delete
```

### Option 3 : limiter la taille dans le cron (approche simple)

```cron
# Limite à 10MB : si le fichier dépasse, on le tronque avant d'écrire
0 7 * * 1,3,5 cd /home/bazaart && docker compose exec -T app php bin/console app:scrape-opportunities --env=prod 2>&1 | tail -c 10485760 > /var/log/bazaart-scraping.log
```

---

## Vérification

Après avoir configuré le cron, vérifier :

```bash
# Voir la crontab configurée
sudo crontab -l

# Tester manuellement la commande (hors cron)
cd /home/bazaart && docker compose exec -T app php bin/console app:scrape-opportunities --env=prod --dry-run

# Vérifier les logs après le premier cron automatique
tail -f /var/log/bazaart-scraping.log
```

---

## Ajustement du fuseau horaire

La crontab s'exécute en **UTC**. Le serveur DigitalOcean est configuré en UTC.

7h00 UTC = 9h00 heure de Paris en été (CEST, UTC+2) = 8h00 en hiver (CET, UTC+1).

Pour lancer à 9h00 heure de Paris été/hiver de façon invariante, remplacer `0 7` par `0 8` (UTC+1 hiver) ou `0 7` (UTC+2 été) — ou utiliser deux entrées cron selon la saison. La solution la plus simple reste UTC.

---

## Lancer un seul scraper en cron (debug)

Si un scraper pose problème, le lancer seul avec l'option `--source` :

```bash
docker compose exec -T app php bin/console app:scrape-opportunities --env=prod --source=on-the-move.org
```

Sources disponibles : `cnap.fr`, `cnm.fr`, `prohelvetia.ch`, `saif.fr`, `musiquesactuelles.fr`, `adagp.fr`, `culture.gouv.fr`, `on-the-move.org`, `resartis.org`, `culturemoveseurope.eu`

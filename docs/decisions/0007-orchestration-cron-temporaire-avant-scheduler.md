# ADR-0007 — Orchestration via cron (temporaire) plutôt que Symfony Scheduler + worker

- **Date** : 2026-06-10
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le chantier RSS a besoin d'une exécution planifiée : lecture des flux RSS toutes les 6 heures,
scraping LLM selon la cadence existante (lundi / mercredi / vendredi à 7h UTC). La demande
initiale mentionnait **Symfony Scheduler** (et explicitement « pas n8n »).

Or :

- `symfony/scheduler` n'est **pas installé** (seul `symfony/messenger` l'est).
- Surtout, Symfony Scheduler suppose un **worker permanent** (`messenger:consume`) tournant
  en continu sur la droplet, supervisé par un gestionnaire de processus.
- **Supervisor n'est pas installé ni opérationnel** sur la droplet (la stack prod = nginx,
  certbot, app, postgres, redis ; aucun worker, aucun service systemd applicatif).
- L'automatisation actuelle du scraping repose déjà sur le **cron de l'hôte** et fonctionne.

Mettre en place un worker à quelques jours du lancement (23 juin 2026) ajouterait un point de
défaillance et une charge d'infra non maîtrisée. Il fallait trancher l'orchestration **sans
bloquer** le chantier.

## Options envisagées

1. **Symfony Scheduler + worker `messenger:consume`** — Cadences gérées en PHP, versionnées.
   Avantages : solution cible « propre », fréquences différenciées natives.
   Inconvénients : nouvelle dépendance + **worker long-running à superviser** (Supervisor/systemd
   à installer et fiabiliser), point de défaillance supplémentaire, risque à J-13 du lancement.

2. **Cron de l'hôte (commande console)** — `app:read-feeds` toutes les 6h (`0 */6 * * *`),
   `app:scrape-opportunities` sur la cadence existante (lun/mer/ven 7h). Avantages : zéro
   infra nouvelle, mécanisme déjà maîtrisé et fiable, aucun worker à surveiller.
   Inconvénients : on s'écarte temporairement de la cible Scheduler ; deux logiques de
   planification (cron) au lieu d'une (PHP).

## Décision

**Option 2 retenue, à titre TEMPORAIRE** : l'orchestration passe par le cron de l'hôte via la
commande `app:read-feeds`. Aucune installation de `symfony/scheduler`, aucun worker.

La logique métier de routage (RSS → `FeedReaderService`, autres → pipeline scraping) et de
suivi de santé est portée par le code applicatif, donc **réutilisable telle quelle** le jour
de la migration vers Scheduler.

## Conséquences

- **Condition de migration vers Symfony Scheduler + worker** : la bascule est planifiée
  **post-lancement**, et **conditionnée à l'installation et la fiabilisation d'un gestionnaire
  de processus (Supervisor ou systemd) sur la droplet**, prévue **conjointement au retrait de
  n8n**. Tant que Supervisor n'est pas opérationnel, on reste sur cron.
- **Cron** : ajouter `0 */6 * * *` pour `app:read-feeds` (cf. `docs/scraping-cron.md`) ;
  l'entrée existante de `app:scrape-opportunities` (lun/mer/ven 7h) reste inchangée.
- **Pas de double-traitement** : `app:scrape-opportunities` exclut les sources `type=RSS`
  (cf. ADR-0004).
- **À surveiller** : lors de la migration Scheduler, supprimer les entrées cron correspondantes
  pour éviter une double exécution (cron + worker).
- **CDC V3 / CLAUDE.md** : la cible Scheduler + le retrait de n8n sont à inscrire dans la
  roadmap post-lancement lors de la prochaine mise à jour arbitrée.

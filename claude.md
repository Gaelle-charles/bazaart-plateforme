# CLAUDE.md — Bazaart

## 👋 À propos de moi (l'utilisatrice de ce projet)

Je suis Gaëlle Charles-Belamour, co-fondatrice du Pôle Lab chez Bazaart. Je développe la plateforme bazaart.fr en tant que développeuse web full-stack en apprentissage.

**Merci de :**
- Intégrer **beaucoup de commentaires explicatifs** dans le code que tu produis (en français)
- Expliquer le **pourquoi** autant que le **comment** des choix techniques
- Signaler les conventions Symfony utilisées pour que je les intègre
- Aller à l'essentiel quand c'est urgent — verbosité pédagogique = qualité, pas blocage

---

## 🧭 Documents de référence

Avant toute tâche significative, **consulter dans cet ordre** :

1. 📘 **`docs/cahier-des-charges-v3.md`** — Cahier des charges complet V3 (mai 2026).
   Document de référence absolu pour le périmètre V1 / V2 / V3, le modèle de données, les entités à créer, les rôles, le planning serré (15 juin 2026), et les choix techniques.
   → À lire pour toute question d'architecture, de modèle de données, ou de scope.

2. Le présent fichier (`CLAUDE.md`) — Conventions de code et raccourcis utiles.

3. `CLAUDE.md.backup-2026-05-11` — version précédente archivée. **Ne plus l'utiliser comme référence.**

⚠️ **En cas de divergence entre ce CLAUDE.md et le cahier des charges V3, le CDC fait foi.** Signaler la divergence pour mise à jour.

---

## 🎯 Priorité actuelle (semaine du 12 mai 2026)

**Deadline V1 : 15 juin 2026 — soit 38 jours calendaires.**

Stratégie de la semaine : **mix corrections rapides + démarrage V1 Ressourcerie**.

### Corrections rapides (J1–J2)
1. Ajouter `$updatedAt` à `OrganizationProfile` (manquant, prévu de longue date)
2. Convertir les constantes `STATUS_*` en enums PHP 8.1 (Article, Resource, ScrapedResource)

### Démarrage V1 Ressourcerie (J3–J5)
3. Créer le rôle `ROLE_STRUCTURE` + workflow d'activation admin
4. Page `/structure/register` + dashboard `/structure/dashboard`
5. Adaptation entité `Resource` (champs `submitter_role`, `auto_published`, `published_at`, `validated_at`, `validated_by_id`)

📍 Détails complets dans `docs/cahier-des-charges-v3.md` sections 4 et 9.

---

## 1. Présentation du projet

**Bazaart** est une plateforme numérique structurante dédiée aux artistes de la diaspora afro-atlantique. Elle prolonge en digital l'action des trois pôles physiques de l'association : Events, Studio (formation), Lab (cultural engineering).

**Lancement officiel** : 15 juin 2026 lors de la clôture de l'incubation Mansa.

---

## 2. Stack technique

| Couche | Technologie | Version |
|---|---|---|
| Backend | PHP, Symfony | 8.3+ / 7.x |
| ORM | Doctrine ORM + Migrations | 3.x |
| Base de données | PostgreSQL | 16 |
| Cache / Sessions / Queue | Redis | 7.x |
| Serveur web | Nginx | 1.24+ |
| Templating | Twig | 3.x |
| Front interactif | Stimulus + Turbo | 3.x / 8.x |
| CSS | Tailwind CSS | 3.x |
| Conteneurisation | Docker / Docker Compose | latest |
| Vidéo (V1) | Bunny Stream | — |
| Workflows asynchrones | n8n self-hosted | latest |
| IA (V2) | Anthropic Claude API | claude-opus-4-7 |
| Paiement (V2) | Stripe (admin-only) | — |
| Emails (dev) | Mailpit (smtp://mailpit:1025) | — |
| Emails (prod) | Brevo ou Resend | — |
| Hébergement | DigitalOcean droplet | — |

### ⚠️ Changements depuis mars 2026
- Hashage password : **Argon2id → bcrypt** (par défaut Symfony Security)
- **Pas de JWT en V1** (Symfony Security classique suffit). JWT prévu V2 pour l'API Studio IA.
- **Pas de Stripe Connect** : seulement Stripe simple admin-only en V2.
- **Bunny Stream** ajouté comme solution vidéo formations.
- **PHPStan niveau 6** obligatoire sur tous nouveaux modules.

---

## 3. Environnement Docker

### Containers actifs

| Container | Rôle | Port |
|---|---|---|
| `bazaart_app` | PHP 8.3 FPM + Symfony | 9000 |
| `bazaart_nginx` | Serveur web | 8080 |
| `bazaart_postgres` | Base de données | 5432 |
| `bazaart_redis` | Cache | 6379 |
| `bazaart_mail` | Mailpit (emails dev) | 8025 / 1025 |

### Variables d'environnement clés (.env.local)

```
APP_ENV=dev
APP_SECRET=<random>
DATABASE_URL=postgresql://bazaart_user:bazaart_pass@postgres:5432/bazaart?serverVersion=16&charset=utf8
MAILER_DSN=smtp://mailpit:1025
REDIS_URL=redis://redis:6379
BUNNY_STREAM_API_KEY=
BUNNY_STREAM_LIBRARY_ID=
ANTHROPIC_API_KEY=
N8N_WEBHOOK_BASE_URL=
SENTRY_DSN=
```

### Commandes Docker utiles

```bash
docker compose up -d                       # Démarrer tous les containers
docker compose down                        # Stopper
docker compose exec app sh                 # Entrer dans le container PHP
docker compose logs -f                     # Logs en temps réel
docker compose exec app php bin/console    # Lancer une commande Symfony
```

---

## 4. Conventions de développement

### Langue
- **Code** : anglais (classes, variables, méthodes)
- **Commentaires** : français, **abondants** pour faciliter mon apprentissage
- **Contenu utilisateur** : français (V1)

### Standards PHP / Symfony
- **PSR-12** pour le formatage
- **PHPStan niveau 6** minimum sur nouveaux modules
- **Attributs PHP 8** partout (jamais d'annotations `/** @ORM\... */`)
- Services injectés par **autowiring**
- **Pas de logique métier** dans les controllers → toujours dans des **Services**
- **DTOs** pour valider les entrées (jamais de tableaux nus)
- Réponses API en JSON via `JsonResponse`

### Structure des dossiers

```
app/
├── src/
│   ├── Controller/       # Controllers fins, délèguent aux services
│   ├── Entity/           # Entités Doctrine
│   ├── Repository/       # Requêtes Doctrine
│   ├── Service/          # Logique métier
│   ├── DTO/              # Data Transfer Objects (validation entrées)
│   ├── EventListener/    # Listeners Symfony
│   ├── Security/         # Voters, Authenticators
│   ├── Enum/             # Enums PHP 8.1 (Status, Roles, etc.)
│   └── MessageHandler/   # Handlers Messenger (async)
├── config/
├── migrations/
├── tests/
└── docs/                 # Documentation projet (cahier des charges...)
```

### Nommage

| Élément | Convention | Exemple |
|---|---|---|
| Entité | PascalCase singulier | `ForumThread` (pas `ForumThreads`) |
| Controller | PascalCase + Controller | `StructureController` |
| Service | PascalCase + Service | `ResourceValidationService` |
| Enum | PascalCase | `ResourceStatus`, `SubmitterRole` |
| Route name | snake_case | `structure_register` |
| Route URL | kebab-case | `/forum/{category-slug}` |
| Table BDD | snake_case | `forum_threads` |
| Colonne BDD | snake_case | `created_at` |

---

## 5. Architecture de la base de données

### Tables V1 (au 15 juin 2026)

**Existantes (à conserver/adapter)**
```
users
artist_profiles
organization_profiles            ← à étendre (compte Structure)
disciplines, artist_disciplines
resources, resource_types        ← à étendre
posts, post_likes, comments
articles
scraped_resources
```

**Nouvelles à créer pour V1** (cf. CDC V3 section 5)
```
resource_favorites          ─ M2M User × Resource
resource_alerts             ─ Préférences alertes par utilisateur
forum_categories
forum_threads
forum_replies
conversations
conversation_participants
messages
notifications
lives                       ─ Lives planifiés (lien externe V1)
live_attendees
courses                     ─ Formations
course_modules
lessons
lesson_resources
course_enrollments
lesson_progress
```

### Règles Doctrine
- Toujours définir `cascade` **explicitement** (jamais par défaut)
- `nullable: true` **uniquement** si vraiment optionnel
- Timestamps (`createdAt`, `updatedAt`) via `#[ORM\HasLifecycleCallbacks]` + traits, ou via Gedmo Timestampable
- Toujours générer une migration après modification d'entité :
  ```bash
  docker compose exec app php bin/console doctrine:migrations:diff
  docker compose exec app php bin/console doctrine:migrations:migrate
  ```
- **Jamais** `doctrine:schema:update` → toujours via migrations

---

## 6. Roadmap

⚠️ **La roadmap autoritaire est dans `docs/cahier-des-charges-v3.md` sections 4, 5 et 6.**
Ce résumé est un repère rapide. En cas de divergence, le CDC fait foi.

### V1 — Livrable 15 juin 2026 (38 jours)
- [ ] **Ressourcerie** (75 % fait) — Compte structure, dashboard structure, soumission artiste avec validation, alertes email — 5 j/h restants
- [ ] **Communauté** (50 % fait) — Forum, messagerie 1-à-1, notifications, planification de lives (lien externe) — 8 j/h
- [ ] **Formation** (0 % fait) — Catalogue admin, parcours (modules → leçons), Bunny Stream, suivi progression — 6 j/h
- [ ] **Dashboard admin** transverse (bases en place) — charge intégrée aux modules

### V2 — juillet à septembre 2026
- Studio IA (collab Goumies_créatives)
- Billetterie événements (Stripe admin-only)
- Lives natifs intégrés
- Quiz, attestations PDF, paiement à la formation
- Recommandations IA personnalisées

### V3 — à partir d'octobre 2026
- Module Archivage / Catalogue d'œuvres (cadrage Wendie × Felix)

---

## 7. Rôles et permissions

```
ROLE_USER          ─ Tout utilisateur authentifié (de base)
ROLE_ARTIST        ─ Profil artiste activé
ROLE_STRUCTURE     ─ Compte structure validé par admin (NOUVEAU V1)
ROLE_MODERATOR     ─ Modérateur communauté (NOUVEAU V1)
ROLE_ADMIN         ─ Équipe Bazaart
```

**Hiérarchie** (config/packages/security.yaml) :
- `ROLE_ADMIN` hérite de tous les autres
- `ROLE_MODERATOR`, `ROLE_STRUCTURE`, `ROLE_ARTIST` héritent de `ROLE_USER`

**Sécurité** : toujours utiliser des **Voters** pour les autorisations (jamais `if ($user->getRoles()...)`).

---

## 8. Entités principales — Spécifications V1

⚠️ Spécifications complètes dans `docs/cahier-des-charges-v3.md` section 5.

### User (existante, à conserver)
```
- id, email (unique), password (bcrypt)
- roles: json, default: ["ROLE_USER"]
- createdAt (readonly), isVerified
```

### Resource (existante, à adapter)
Ajouter :
- `submitterRole: enum (ADMIN | STRUCTURE | ARTIST)`
- `status: enum (DRAFT | PENDING_VALIDATION | PUBLISHED | REJECTED | ARCHIVED)`
- `publishedAt, validatedAt, validatedBy (FK User)`
- `autoPublished: bool`

### OrganizationProfile (existante, à adapter — priorité)
Ajouter :
- ⚠️ **`updatedAt: datetime`** — manquant, à ajouter en priorité
- `isStructurePartner: bool`
- `structureActivatedAt: datetime` (nullable)
- `structureActivationValidatedBy: FK User` (nullable)

### Nouvelles entités V1
→ Spécifications complètes dans `docs/cahier-des-charges-v3.md` section 5.

---

## 9. Sécurité

- **HTTPS** obligatoire en production
- Mots de passe hashés avec **bcrypt** (algo par défaut Symfony Security 7.x)
- Politique mot de passe : min. 10 caractères, 1 majuscule, 1 chiffre
- **CSRF** sur tous les formulaires
- **Validation côté serveur systématique** (Symfony Validator)
- **Rate limiting** sur `/login` et `/register` : max 5 tentatives / 15 min / IP
- Headers HTTP : `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, CSP adaptée aux iframes (Bunny Stream, Twitch, YouTube)

### RGPD (à finaliser avant 15 juin)
- Politique de confidentialité publique sur `/confidentialite`
- CGU sur `/cgu`
- Mentions légales sur `/mentions-legales`
- Bannière de consentement cookies
- Espace utilisateur RGPD : export JSON, demande de suppression
- Registre des traitements
- Consentement explicite traitement IA (V2)

---

## 10. Tests

- **PHPUnit** avec fixtures Symfony
- Couverture cible V1 : **30 %** (réaliste vu le délai)
- Priorité : services critiques + 5 à 8 scénarios end-to-end

### Parcours end-to-end critiques V1
1. Inscription + login
2. Soumettre une ressource (artiste)
3. Créer un thread forum
4. Envoyer un message privé
5. S'inscrire à une formation

```bash
docker compose exec app php bin/phpunit
docker compose exec app vendor/bin/phpstan analyse src --level=6
```

---

## 11. Commandes fréquentes

```bash
# Entrer dans le container
docker compose exec app sh

# Créer / modifier
php bin/console make:entity
php bin/console make:controller

# Migrations
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
php bin/console doctrine:schema:validate

# Vérifications
php bin/console lint:container
vendor/bin/phpstan analyse src --level=6

# Cache et debug
php bin/console cache:clear
php bin/console debug:router
php bin/console debug:container

# Tests
php bin/phpunit
```

---

## 12. Ce qu'il ne faut PAS faire

- ❌ Mettre de la logique métier dans les controllers
- ❌ Utiliser les annotations Doctrine en commentaires (`/** @ORM\... */`)
- ❌ Commiter `.env.local` avec des credentials réelles
- ❌ Supprimer une migration déjà appliquée
- ❌ Utiliser `doctrine:schema:update` (toujours via migrations)
- ❌ Lancer `composer update` sans demande explicite
- ❌ Faire des décisions de scope sans consulter `docs/cahier-des-charges-v3.md`
- ❌ Ajouter des fonctionnalités V2 ou V3 pendant le développement V1 (planning serré)
- ❌ Push direct sur `main` (passer par `dev` puis merge)

---

## 13. Hébergement et infrastructure

- **Production** : DigitalOcean droplet, IP `206.189.3.112`
- **Dimensionnement actuel** : 2 GB → upgrade prévu à 4 GB semaine du 9 juin
- **Sauvegardes** : `pg_dump` quotidien (7 jours local + 30 jours externe)
- **SSL** : Let's Encrypt via Certbot
- **Monitoring** : Sentry (erreurs) + UptimeRobot (uptime)

### Domaines (à configurer)
- `bazaart.fr` — production
- `staging.bazaart.fr` — staging
- `n8n.bazaart.fr` — interface n8n (Basic Auth + IP allowlist)

---

## 14. Workflow Git

- `main` → production (déploiement manuel via SSH + `git pull`)
- `dev` → intégration / staging
- `feature/<module>-<description>` → branches de travail, mergées dans `dev`

Toujours un commit par fonctionnalité, message descriptif en français.

---

## 15. Notes pour Claude Code et le subagent `symfony-backend`

- **Toujours lire `docs/cahier-des-charges-v3.md` AVANT toute tâche significative** (modification d'entité, création de module, choix d'architecture).
- En cas de divergence entre ce CLAUDE.md et le CDC V3, **le CDC fait foi**. Signaler la divergence pour mise à jour de ce fichier.
- **Beaucoup de commentaires en français** dans le code généré.
- Expliquer les choix techniques quand ils ne sont pas évidents.
- Le délai V1 est court (38 jours au 11 mai). **Priorité au fonctionnel** plutôt qu'à la perfection.
- Pour toute hésitation sur le scope d'une fonctionnalité, **lister les options sans implémenter** et attendre validation.
- Le subagent `symfony-backend` doit renvoyer ses résultats au format structuré défini dans son system prompt (Summary / Files changed / Commands run / Migrations / Validation status / Open questions).

# Scraping — Documentation complète Bazaart

> **Document vivant** — À mettre à jour à chaque modification du système de scraping.
> Dernière mise à jour : **26 mai 2026**

---

## Sommaire

1. [Vue d'ensemble — comment ça marche](#1-vue-densemble)
2. [Architecture — les briques du système](#2-architecture)
3. [Les 3 méthodes de scraping](#3-les-3-méthodes-de-scraping)
4. [Les 10 scrapers actifs](#4-les-10-scrapers-actifs)
5. [Déduplication et cycle de vie d'une opportunité](#5-déduplication-et-cycle-de-vie)
6. [Score de pertinence Afrodiaspora](#6-score-de-pertinence-afrodiaspora)
7. [Découverte automatique de nouvelles sources](#7-découverte-automatique-de-nouvelles-sources)
8. [Interface admin — ce que tu vois](#8-interface-admin)
9. [Commandes disponibles](#9-commandes-disponibles)
10. [Ajouter une nouvelle source](#10-ajouter-une-nouvelle-source)
11. [Problèmes connus et limites V1](#11-problèmes-connus-et-limites-v1)
12. [Roadmap V2](#12-roadmap-v2)
13. [Changelog](#13-changelog)

---

## 1. Vue d'ensemble

Le système de scraping de Bazaart collecte automatiquement des **opportunités culturelles** (bourses, résidences, appels à projets, prix…) sur une dizaine de sites institutionnels français et européens.

**Objectif** : éviter à l'équipe Bazaart de chercher manuellement les opportunités pour les mettre dans la Ressourcerie. Le scraper fait une première passe, l'admin valide ce qui est pertinent en 1 clic.

### Flux simplifié

```
Sites web ──► Scrapers PHP ──► scraped_resources (BDD)
                                       │
                              Admin valide ou rejette
                                       │
                              Resource publiée dans la Ressourcerie
```

### Fréquence recommandée

- **Manuel** : bouton "▶ Lancer le scraping" dans `/admin/scraped-opportunities`
- **Automatique** : cron 3×/semaine (lundi, mercredi, vendredi à 7h UTC) — cf. `docs/scraping-cron.md`
- **Durée** : 20 à 90 secondes selon le nombre de sources actives et la réponse des serveurs

---

## 2. Architecture

### Fichiers clés

```
app/src/
├── Command/
│   ├── ScrapeOpportunitiesCommand.php    ← Point d'entrée principal (app:scrape-opportunities)
│   ├── SeedScrapingSourcesCommand.php    ← Initialise les sources en BDD
│   └── DiscoverSourcesCommand.php        ← Découverte de nouvelles sources via LLM
│
├── Service/
│   ├── ScraperRegistry.php               ← Annuaire slug → classe PHP
│   ├── GenericScraper.php                ← Scraper générique (RSS ou HTML+LLM)
│   ├── LlmExtractorService.php           ← Extraction HTML via Mistral ou Claude
│   ├── AfrodiasporaRelevanceScorer.php   ← Calcule le score ★★★☆☆
│   │
│   └── Scraper/
│       ├── AbstractScraper.php           ← Classe mère : fetch(), fetchHtml(), cleanText()
│       ├── AbstractRssScraper.php        ← Classe mère RSS : parse XML + filtre mots-clés
│       ├── CnmScraper.php
│       ├── CnapScraper.php
│       ├── AdagpScraper.php
│       ├── SaifScraper.php
│       ├── OnTheMoveScraper.php
│       ├── ResartisScraper.php
│       ├── ProHelvetiaScraper.php
│       ├── MusiquesActuellesScraper.php
│       ├── CultureMovesEuropeScraper.php
│       └── CultureGouvScraper.php        ← Désactivé (JS/Algolia)
│
├── Entity/
│   ├── ScrapingSource.php                ← Source gérée en BDD (table scraping_sources)
│   ├── ScrapedResource.php               ← Opportunité collectée (table scraped_resources)
│   └── SuggestedSource.php              ← Source suggérée par LLM (table suggested_sources)
│
├── Repository/
│   ├── ScrapingSourceRepository.php
│   ├── ScrapedResourceRepository.php     ← archiveExpired(), countPending()...
│   └── SuggestedSourceRepository.php
│
└── Controller/
    ├── AdminController.php               ← runScraping() (AJAX + redirect)
    ├── AdminScrapingSourceController.php ← CRUD /admin/scraping-sources
    └── AdminSuggestedSourceController.php
```

### Comment les pièces s'assemblent

1. `ScrapeOpportunitiesCommand` lit les sources actives depuis la BDD (`scraping_sources`)
2. Pour chaque source :
   - Si elle a un `scraperSlug` → `ScraperRegistry` retourne la classe PHP dédiée
   - Si pas de slug → `GenericScraper` prend le relais (RSS ou HTML+LLM selon le type)
3. Le scraper retourne une liste de `ScrapedOpportunity` (DTO simple)
4. La commande déduplique par URL et enregistre en BDD (`scraped_resources`)
5. `archiveExpired()` archive automatiquement les opportunités dont la deadline est passée

---

## 3. Les 3 méthodes de scraping

### Méthode 1 — RSS (la plus fiable)

**Qu'est-ce qu'un flux RSS ?** C'est un fichier XML que les sites publient pour diffuser leurs actualités. Le format est standardisé — ça ne change presque jamais, c'est donc très stable à parser.

**Comment ça marche ici :**
- On télécharge le fichier XML (`/feed/`, `/rss.xml`…)
- On le parse avec `SimpleXML` (outil PHP intégré)
- On filtre les items par mots-clés : `appel`, `candidature`, `bourse`, `résidence`, `prix`...
- On crée un `ScrapedOpportunity` pour chaque item pertinent

**Formats supportés :**
- RSS 2.0 : les items sont dans `$feed->channel->item`
- Atom (RFC 4287) : les items sont dans `$feed->entry` (pas de `channel`), les liens sont dans l'attribut `href` et non en valeur texte

**Limites :**
- La deadline stockée = **date de publication** (pas la vraie deadline de candidature)
- Le RSS ne contient que les X derniers articles (souvent 10-20)
- Certains sites n'ont pas de RSS (CNAP, SAIF, On The Move...)

**Code impliqué :** `AbstractRssScraper`, `GenericScraper::scrapeRss()`

---

### Méthode 2 — HTML + sélecteurs CSS (la plus précise, site-spécifique)

**Comment ça marche ici :**
- On télécharge le HTML de la page avec `HttpClient`
- On le parse avec `DomCrawler` (l'équivalent de jQuery, côté PHP)
- On cible des éléments HTML précis avec des sélecteurs CSS (comme `.ma-classe a`, `h3 a`, `article h2`...)
- On extrait le titre, l'URL, parfois la date

**Avantages :**
- Très précis — on cible exactement ce qu'on veut
- Pas de quota API (aucun LLM impliqué)
- On peut extraire les vraies deadlines si elles sont dans le HTML

**Limites :**
- **Fragile** : si le site refait son design, les sélecteurs cassent
- **Impossible à généraliser** : chaque site a sa propre structure → une classe PHP dédiée par site
- **Bloqué par le JavaScript** : si le contenu est rendu côté client (SPA React, Algolia...), le HTML reçu est vide → cette méthode échoue

**Exemple concret — On The Move :**

La page `/news/deadlines` a cette structure :
```html
<span class="field-content">
  <a href="/news/goethe-grants-2026" class="lit under">Goethe-Institut: Mobility Grants</a>
  <div class='st'>Deadline: <time datetime="2026-06-15T12:00:00Z" class="datetime">15 Jun 2026</time></div>
</span>
```
Sélecteurs utilisés :
- `a.lit.under` → titre + href relatif
- `closest('.field-content')` puis `time.datetime` → deadline ISO précise

**Code impliqué :** `AbstractScraper::fetch()`, classes `*Scraper.php` avec méthode CSS

---

### Méthode 3 — HTML + LLM (la plus flexible, coûte des tokens)

**Comment ça marche ici :**
- On télécharge le HTML brut de la page
- On le nettoie (suppression des balises `<script>`, `<style>`, `<nav>`...)
- On l'envoie à un LLM avec un prompt structuré :
  > "Extrait les opportunités culturelles de ce HTML. Retourne un JSON avec : titre, type, URL, deadline, description."
- Le LLM retourne un JSON que l'on mappe vers des `ScrapedOpportunity`

**LLM utilisé :**
- **Principal** : Mistral Small 3.2 (`mistral-small-latest`) — plus rapide et moins cher
- **Fallback** : Anthropic Claude Haiku — si Mistral échoue ou si la clé est absente

**Avantages :**
- Fonctionne même si le HTML est complexe ou non structuré
- Comprend le contexte (sait distinguer une bourse d'un communiqué de presse)
- Peut extraire des deadlines formulées en texte libre ("Candidatures avant le 15 juin")

**Limites :**
- **Coûte des tokens API** (quota limité, surtout en développement)
- **Non déterministe** : deux appels identiques peuvent donner des résultats légèrement différents
- **Lent** : 5-15 secondes par page (vs 1-2s pour RSS ou CSS)
- **Limite de contexte** : les pages > 50KB sont tronquées avant envoi au LLM

**Code impliqué :** `LlmExtractorService::extractFromHtml()`, `GenericScraper::scrapeHtmlLlm()`

---

### Cas spécial — WordPress REST API (CNM)

Le site du CNM utilise WordPress Interactivity API (WP 6.5+) : les cartes d'appels à projets sont rendues **côté client par JavaScript**. Le HTML statique est donc vide → méthode CSS impossible.

**Solution trouvée :** WordPress expose une REST API JSON publique :
```
GET https://cnm.fr/wp-json/wp/v2/posts?categories=42&per_page=20&page=1&orderby=date&order=desc&_fields=id,title,link,date,excerpt
```
- Catégorie 42 = "appels-a-projets" (vérifié via `/wp-json/wp/v2/categories`)
- On récupère 40 articles (2 pages de 20) — les plus récents
- La deadline est extraite de l'extrait via 3 patterns regex :
  - `"Date limite de candidature : 15 juin 2026"`
  - `"jusqu'au 31 mai 2026"`
  - `"avant le 15 juin 2026"`

**Code impliqué :** `CnmScraper::scrapeApiPage()`, `CnmScraper::extractDeadlineFromExcerpt()`

---

## 4. Les 10 scrapers actifs

### Tableau récapitulatif

| Slug | Classe | Site | Méthode | Discipline | Zone | Actif | Agrégateur |
|---|---|---|---|---|---|---|---|
| `cnm` | `CnmScraper` | cnm.fr | RSS + WP API | Musique | France | ✅ | ❌ |
| `cnap` | `CnapScraper` | cnap.fr | HTML CSS | Arts plastiques | France | ✅ | ❌ |
| `adagp` | `AdagpScraper` | adagp.fr | HTML CSS | Arts graphiques | France | ✅ | ❌ |
| `saif` | `SaifScraper` | saif.fr | HTML CSS | Photographie | France | ✅ | ❌ |
| `on-the-move` | `OnTheMoveScraper` | on-the-move.org | HTML CSS | Mobilité | Europe | ✅ | ✅ |
| `resartis` | `ResartisScraper` | resartis.org | RSS + HTML LLM | Résidences | Monde | ✅ | ✅ |
| `prohelvetia` | `ProHelvetiaScraper` | prohelvetia.ch | RSS | Toutes disciplines | Suisse | ✅ | ❌ |
| `musiques-actuelles` | `MusiquesActuellesScraper` | musiquesactuelles.fr | RSS | Musique | France | ✅ ⚠️ | ❌ |
| `culture-moves-eu` | `CultureMovesEuropeScraper` | culture.ec.europa.eu | HTML LLM | Creative Europe | UE | ✅ | ✅ |
| `culture-gouv` | `CultureGouvScraper` | culture.gouv.fr | HTML CSS | Toutes disciplines | France | ❌ | ❌ |

> **⚠️ Agrégateur** = ce site liste des opportunités d'autres organismes, pas seulement les siennes. Il est analysé par `app:discover-sources` pour trouver de nouvelles sources à ajouter.

---

### Détails par scraper

#### CNM — Centre National de la Musique
- **URL BDD** : `https://cnm.fr/feed/`
- **Méthode** : RSS pour les actualités + WordPress REST API pour les appels à projets
- **Particularité** : le site utilise WP Interactivity API (JS côté client) → les cartes d'appels ne sont pas dans le HTML statique. Solution : REST API `/wp-json/wp/v2/posts?categories=42`
- **Volume** : ~49 opportunités par run (10 RSS + 40 API)
- **Deadline** : extraite via regex depuis l'extrait (3 patterns) — la date de publication n'est PAS utilisée (risque d'archivage prématuré)
- **Historique des URLs testées** :
  - ❌ `/appels-a-projets/` — rendu JS, 0 liens dans le HTML statique
  - ✅ `/feed/` — RSS actif, 10 dernières actualités
  - ✅ `/wp-json/wp/v2/posts?categories=42` — REST API WP, 40 appels à projets

#### CNAP — Centre National des Arts Plastiques
- **URL** : `https://www.cnap.fr/actualites`
- **Méthode** : HTML CSS — sélecteur `h3 a` (10 éléments par page)
- **Particularité** : toutes les actualités sont gardées (pas de filtre par mots-clés) car le CNAP ne publie que des contenus liés aux arts plastiques
- **Volume** : ~10 opportunités par run

#### ADAGP — Arts graphiques et plastiques
- **URL** : `https://www.adagp.fr/fr/actualites`
- **Méthode** : HTML CSS — parcours des `<article>`, priorité au `h3 a` (titre sémantique), fallback sur tout lien vers `/fr/actualites/`
- **Filtre** : mots-clés appel, candidature, bourse, résidence, prix, aide, soutien...
- **Pages** : page 1 + page 2 (`?page=1`)
- **Historique** : l'ancienne version ciblait directement `a[href*="/fr/actualites/"]` → récupérait les liens image (texte vide, filtre rejetait tout). Fix : parcourir les `<article>` et prendre le `h3 a`

#### SAIF — Auteurs arts visuels et image fixe
- **URL** : `https://www.saif.fr/soutien-a-la-creation/les-bourses-de-la-saif/` + `/les-prix-de-la-saif/`
- **Méthode** : HTML CSS — tous les liens `a[href]` filtrés par mots-clés
- **Filtres supplémentaires** : ignore les PDFs (`.pdf` dans l'URL), ignore les liens hors domaine `saif.fr`
- **Historique des URLs testées** :
  - ❌ `/fr/bourses-et-prix` — chemin inexistant (404)
  - ❌ `/fr/aides-a-la-creation` — chemin inexistant (404)
  - ✅ `/soutien-a-la-creation/les-bourses-de-la-saif/` — HTTP 200, 136KB

#### On The Move — Mobilité culturelle internationale
- **URL** : `https://on-the-move.org/news/deadlines`
- **Méthode** : HTML CSS — sélecteurs `a.lit.under` (titres) + `time.datetime` (deadlines ISO)
- **Deadline** : extraite directement depuis l'attribut `datetime` de la balise `<time>` → format ISO 8601 → converti en `dd/mm/yyyy`
- **Historique des approches** :
  - ❌ v1 : RSS `/calls/rss.xml` — HTTP 404 (flux supprimé)
  - ❌ v2 : Fallback LLM sur `/calls` — 0 résultat (page vide, LLM instable, quota consommé)
  - ✅ v3 : CSS pur sur `/news/deadlines` — ~30-50 opportunités avec deadlines réelles

#### Resartis — Résidences mondiales
- **URL** : `https://www.resartis.org/feed/` + fallback `https://www.resartis.org/residencies/`
- **Méthode** : RSS en priorité, puis HTML + LLM en fallback
- **Particularité SSL** : le certificat de resartis.org n'est pas reconnu par le container Docker (CA racine absente). `verify_peer = false` activé via `fetchHtmlInsecure()`. Le trafic reste chiffré — seule la vérification du certificat est contournée.
- **Score** : AfrodiasporaRelevanceScorer appliqué (résidences en Afrique, Caraïbes…)

#### Pro Helvetia — Fondation suisse pour la culture
- **URL** : `https://prohelvetia.ch/fr/feed/`
- **Méthode** : RSS pur — flux stable, mis à jour régulièrement
- **Volume** : ~5 opportunités par run (filtrage strict)
- **Disciplines couvertes** : Arts visuels, Design, Musique, Arts numériques

#### Musiques Actuelles en France
- **URL** : `https://musiquesactuelles.fr/feed/`
- **Méthode** : RSS
- **Statut** : ⚠️ **Domaine inaccessible** depuis mai 2026 (HTTP 000 — connexion refusée ou DNS mort). Le scraper retourne `[]` silencieusement sans faire planter la commande.
- **Alternative envisagée** : `https://www.irma.asso.fr/feed/` (Institut de Ressources Musicales Actuelles)

#### EACEA / Creative Europe — Subventions UE
- **URL** : `https://culture.ec.europa.eu/fr/funding`
- **Méthode** : HTML + LLM (Mistral ou Anthropic)
- **Volume HTML** : ~179KB
- **Historique des URLs testées** :
  - ❌ `culturemoveseurope.eu/opportunities/` — DNS mort depuis Docker
  - ❌ `eacea.ec.europa.eu/grants_en` — HTTP 404 depuis mai 2026
  - ✅ `culture.ec.europa.eu/fr/funding` — HTTP 200, 179KB

#### Ministère de la Culture — DÉSACTIVÉ
- **URL** : `https://www.culture.gouv.fr/Actualites`
- **Statut** : ❌ **Désactivé** — le site utilise JavaScript/Algolia pour charger son contenu. Le HTML statique reçu est une coquille vide (~17KB). La liste des appels est entièrement rendue côté client.
- **Pour réactiver** : trouver un flux RSS officiel de culture.gouv.fr ou une API publique.

---

## 5. Déduplication et cycle de vie

### Cycle de vie d'une opportunité

```
1. SCRAPED → scraped_resources, status = 'pending'
                        │
             L'admin consulte /admin/scraped-opportunities
                        │
         ┌──────────────┼──────────────┐
         ▼              ▼              ▼
     VÉRIFIÉ        REJETÉ         (ignoré)
   status=verified  status=rejected
         │
   Resource créée
   dans la Ressourcerie
   (status=published)
```

### Déduplication intelligente par URL

À chaque run, pour chaque opportunité collectée :

| Cas | Action |
|---|---|
| URL **inconnue** | Insertion en BDD (status = `pending`) |
| URL **connue**, status `pending` ou `rejected` | Mise à jour des champs (titre, description, deadline…) — le status n'est pas modifié |
| URL **connue**, status `verified` | **Ignorée** — on préserve le travail de modération de l'admin |

> C'est pourquoi un run peut afficher "0 nouvelle(s), 70 mise(s) à jour" : toutes les URLs étaient déjà connues, on a juste rafraîchi les données.

### Archivage automatique des deadlines passées

Après chaque run, `archiveExpired()` analyse les opportunités `pending` et archive celles dont la deadline est clairement passée.

**Formats de deadline reconnus :**
1. ISO 8601 court : `2026-05-31`
2. Français court : `31/05/2026`
3. Français long : `31 mai 2026`

**Règle de grâce de 48h :** une opportunité créée il y a moins de 48h n'est jamais archivée, même si sa deadline semble passée. Raison : certains scrapers stockaient la date de publication dans le champ deadline — sans cette protection, les nouvelles opportunités étaient archivées immédiatement avant que l'admin les voie.

**Deadlines ignorées** : vide, `-`, `—`, format non reconnu → pas d'archivage (mieux vaut laisser l'admin décider).

---

## 6. Score de pertinence Afrodiaspora

Chaque opportunité reçoit un score de **0 à 5 étoiles** (★★★☆☆) calculé par `AfrodiasporaRelevanceScorer`.

### Algorithme

1. Fusionner `titre + description` en minuscules
2. Compter les mots-clés **haute pertinence** trouvés (max 3 points)
3. Compter les mots-clés **pertinence moyenne** trouvés (max 2 points)
4. Score final = min(haute + moyenne, 5)

### Mots-clés haute pertinence (1pt chacun, max 3)

> caraïbe, caribéen, antilles, guadeloupe, martinique, réunion, mayotte, guyane, dom-tom, dom/tom, outre-mer, afrique, africain, afrodiaspora, diaspora afro, diaspora africaine

### Mots-clés pertinence moyenne (1pt chacun, max 2)

> francophonie, francophone, international, mobilité internationale, résidence internationale, maghreb, sénégal, côte d'ivoire, mali, cameroun, congo, bénin, togo, burkina, haïti, jamaïque, trinidad

### Utilisation du score

- **Affiché** dans le terminal lors d'un run (`★★★☆☆`)
- **Stocké** dans `scraped_resources.relevance_score` (entier 0-5)
- **Utilisé** pour trier les opportunités par priorité dans l'interface admin (les plus pertinentes en haut)

> **Note** : les scrapers CSS purs (OnTheMove, SAIF, ADAGP, CNAP) ne calculent pas le score (0 par défaut) — pas de dépendance LLM. L'admin peut filtrer manuellement.

---

## 7. Découverte automatique de nouvelles sources

En plus du scraping d'opportunités, un second mécanisme permet de **trouver de nouvelles sources** à ajouter au système.

### Principe

Certaines sources sont des **agrégateurs** : elles ne publient pas leurs propres opportunités mais listent celles d'autres organismes. Exemples : On The Move, Resartis, EACEA.

La commande `app:discover-sources` analyse le HTML de ces agrégateurs et demande au LLM d'en extraire des organismes potentiellement intéressants (avec leur URL, type, discipline, zone).

### Flux

```
Agrégateur (HTML) → LLM → Liste d'organismes détectés
                                    │
                     Déduplication (pas déjà dans scraping_sources
                     ni dans suggested_sources)
                                    │
                          SuggestedSource créée (status = À valider)
                                    │
                     Admin consulte /admin/suggested-sources
                                    │
                  ┌─────────────────┴──────────────┐
                  ▼                                  ▼
             VALIDER                            REJETER
   ScrapingSource créée                   suggestion.status = Rejeté
   suggestion.status = Validée
```

### Isolation absolue

`app:discover-sources` ne touche **jamais** à `scraped_resources`. Elle ne lance **jamais** le scraping. Elle peuple uniquement `suggested_sources`.

### Commande

```bash
docker compose exec app php bin/console app:discover-sources
docker compose exec app php bin/console app:discover-sources --dry-run
docker compose exec app php bin/console app:discover-sources --source="On The Move"
```

---

## 8. Interface admin

### `/admin/scraped-opportunities` — La page principale

4 onglets :

| Onglet | Description |
|---|---|
| **À vérifier** | Opportunités `pending` — à valider ou rejeter |
| **Vérifié** | Déjà publiées dans la Ressourcerie |
| **Rejeté** | Rejetées par l'admin |
| **Archivé** | Deadline passée (archivage automatique) |

**Actions disponibles :**
- ▶ Lancer le scraping — avec card de statut temps réel (spinner + timer + résultat)
- ✓ Vérifier — publie l'opportunité dans la Ressourcerie
- ✗ Rejeter — marque comme rejeté (sera quand même mis à jour au prochain run)
- ✎ Modifier — édite le contenu directement (titre, description, deadline, disciplines, URL)

### `/admin/scraping-sources` — Gestion des sources

- Voir toutes les sources avec leur statut (actif/inactif), type, dernière exécution
- Activer / désactiver une source
- Ajouter une source manuellement (RSS ou HTML_LLM — les sources HTML_CSS nécessitent du code)
- Voir le dernier résultat (succès + nombre d'items, ou message d'erreur)

### `/admin/suggested-sources` — Sources suggérées par LLM

- 3 sections : À valider / Validées / Rejetées
- Pour chaque suggestion : nom de l'organisme, URL, type pressenti, discipline, raison de la suggestion
- Actions : Valider (→ crée une ScrapingSource) ou Rejeter

### `/admin/settings` — Configuration

- `scraping_enabled` — active/désactive le scraping (switch admin)
- `llm_provider` — `mistral` ou `anthropic`
- `mistral_api_key` — clé API Mistral
- `discovery_enabled` — active/désactive la découverte de sources
- Bouton "Tester la connexion Mistral" (AJAX)

---

## 9. Commandes disponibles

```bash
# Scraping standard
docker compose exec app php bin/console app:scrape-opportunities

# Options utiles
docker compose exec app php bin/console app:scrape-opportunities --dry-run
# → affiche sans écrire en BDD

docker compose exec app php bin/console app:scrape-opportunities --debug
# → affiche les détails HTTP de chaque fetch (status, taille HTML, sélecteurs)

docker compose exec app php bin/console app:scrape-opportunities --source="CNM - Centre National de la Musique"
# → lance uniquement la source dont le nom correspond exactement

# Initialisation des sources en BDD
docker compose exec app php bin/console app:seed-scraping-sources
docker compose exec app php bin/console app:seed-scraping-sources --force
# → --force met à jour les métadonnées (nom, type, discipline) sans toucher au slug ni aux stats

# Découverte de nouvelles sources
docker compose exec app php bin/console app:discover-sources
docker compose exec app php bin/console app:discover-sources --dry-run
docker compose exec app php bin/console app:discover-sources --source="On The Move"
```

---

## 10. Ajouter une nouvelle source

### Cas 1 — Source RSS simple (sans code)

1. Aller sur `/admin/scraping-sources`
2. Cliquer "Ajouter une source"
3. Remplir : nom, URL du flux RSS, type = RSS, discipline, zone
4. Laisser le champ "Slug scraper" vide → `GenericScraper` prendra en charge
5. La source est active immédiatement au prochain run

### Cas 2 — Source HTML avec LLM (sans code)

Même démarche, type = HTML_LLM. Le `GenericScraper` fera le fetch + appel LLM.
> **Attention** : consomme des tokens API Mistral/Anthropic.

### Cas 3 — Source HTML avec sélecteurs CSS (nécessite du code)

Pour les sites avec une structure HTML stable et spécifique :

1. **Créer la classe** `app/src/Service/Scraper/MonSiteScraper.php` qui étend `AbstractScraper`
2. **Implémenter** `scrape(): array`, `getName(): string`, `getTestUrl(): string`
3. **Enregistrer le slug** dans `ScraperRegistry::__construct()` :
   ```php
   'mon-site' => $monSite,
   ```
4. **Ajouter la source** dans `SeedScrapingSourcesCommand.php` :
   ```php
   ['nom' => 'Mon Site', 'url' => '...', 'type' => HtmlCss, 'slug' => 'mon-site', ...]
   ```
5. **Reseed** : `php bin/console app:seed-scraping-sources`

**Checklist d'un bon scraper :**
- [ ] `declare(strict_types=1)` en en-tête
- [ ] Commentaires en français abondants
- [ ] `scrape()` ne lève jamais d'exception (tout try/catch retourne `[]`)
- [ ] Déduplication `$seenUrls` si plusieurs pages
- [ ] URLs absolues via `$this->absoluteUrl($href, self::BASE_URL)`
- [ ] Textes nettoyés via `$this->cleanText($text)`
- [ ] PHPStan niveau 6 propre

---

## 11. Problèmes connus et limites V1

### ⚠️ Musiques Actuelles — domaine inaccessible

`musiquesactuelles.fr` retourne HTTP 000 (connexion refusée) depuis mai 2026. Le scraper ne plante pas — il retourne `[]` silencieusement. Alternative envisagée : `irma.asso.fr/feed/`.

### ⚠️ Ministère de la Culture — JavaScript/Algolia

`culture.gouv.fr` charge son contenu via JavaScript côté client. Le HTML statique reçu est vide. Source désactivée en BDD (`actif = false`). Pour réactiver : trouver un RSS officiel.

### ⚠️ Deadlines en texte libre

Le champ `deadline` dans `scraped_resources` est un texte libre (pas un `datetime`). C'est un choix délibéré : les sites n'ont pas de format de date uniforme. La contrepartie : `archiveExpired()` ne reconnaît que 3 formats (ISO, `dd/mm/yyyy`, `dd mois yyyy`) — les autres sont ignorés.

### ⚠️ Score Afrodiaspora incomplet pour les scrapers CSS

Les scrapers CSS purs (OnTheMove, SAIF, ADAGP, CNAP) ne calculent pas le score AfrodiasporaRelevanceScorer — ils mettent 0 par défaut. Raison : pas de description disponible sur les pages de liste, et pas de dépendance LLM dans ces scrapers. Le score est calculé uniquement quand il y a une description.

### ⚠️ Resartis — SSL non vérifié

Le certificat de resartis.org n'est pas reconnu par le container Docker (CA racine absente). `verify_peer = false` activé. Le trafic reste chiffré — c'est un contournement technique, pas une faille de sécurité réelle.

### ⚠️ LLM — quota et instabilité

Les scrapers LLM dépendent de clés API externes. Si la clé Mistral est absente ou épuisée, `LlmExtractorService` retourne `[]` avec un warning dans les logs. La source est marquée `markRunSuccess(0)` (pas d'erreur bloquante).

### ℹ️ Performances — scraping bloquant en mode admin

Le bouton "Lancer le scraping" dans l'admin lance la commande **dans le processus PHP courant** (pas dans un subprocess). Raison : les sous-processus n'héritent pas des variables d'environnement de PHP-FPM dans Docker (DATABASE_URL, etc. → connexion BDD impossible). La contrepartie : la requête HTTP reste ouverte 20-90s. La card de statut avec timer atténue cet inconvénient côté UX.

---

## 12. Roadmap V2

### Priorité haute

- **Cron automatique** : configurer le cron `0 7 * * 1,3,5` sur le droplet DigitalOcean (cf. `docs/scraping-cron.md`)
- **Fix Musiques Actuelles** : tester `irma.asso.fr/feed/` comme remplacement
- **Fix Ministère de la Culture** : identifier un endpoint RSS/API officiel culture.gouv.fr
- **Score Afrodiaspora sur scrapers CSS** : enrichir les opportunités CNAP/SAIF/ADAGP en fetchant la page de détail pour extraire une description

### Priorité moyenne

- **Pagination automatique** : CNAP et ADAGP n'ont qu'une ou deux pages — ajouter la détection automatique du lien "page suivante"
- **Webhook n8n** : déclencher le scraping depuis n8n (workflow planifié) plutôt que depuis l'admin
- **Alertes email artistes** : notifier les artistes qui ont une alerte configurée (`resource_alerts`) dès qu'une nouvelle opportunité correspond à leurs critères
- **Archivage plus intelligent** : parser plus de formats de deadline (ex: "avant fin juin", "courant juillet")

### Priorité basse (V3)

- **Scraping asynchrone via Symfony Messenger** : lancer chaque scraper dans une tâche séparée en file d'attente Redis → résultats en parallèle, pas de timeout HTTP
- **API Culture.gouv.fr** : explorer l'API ouverte culture.data.gouv.fr pour les subventions
- **Nouveaux scrapers** : Institut Français, SACD, SCAM, Fondation de France, DRAC régionales
- **Auto-amélioration LLM** : si le LLM échoue 3 fois sur un site, créer automatiquement une `SuggestedSource` pour signalement admin

---

## 13. Changelog

> Toutes les modifications significatives du système de scraping sont documentées ici.

### Mai 2026

#### 26 mai 2026 — Card de statut temps réel

- **AdminController.php** : `runScraping()` retourne maintenant du JSON si la requête est AJAX (`isXmlHttpRequest()`)
- **scraped_opportunities.html.twig** : le formulaire utilise désormais `fetch()` + une card de statut avec spinner, timer en secondes, et rechargement automatique à la fin

#### 26 mai 2026 — Amélioration de 5 scrapers

- **OnTheMoveScraper** : réécriture complète. Ancienne approche (RSS 404 + LLM instable) → CSS pur sur `/news/deadlines`. Sélecteurs `a.lit.under` + `time.datetime`. Plus de dépendance LLM.
- **CultureMovesEuropeScraper** : URL corrigée (`eacea.ec.europa.eu/grants_en` → `culture.ec.europa.eu/fr/funding`)
- **SaifScraper** : URLs corrigées (`/fr/bourses-et-prix` → `/soutien-a-la-creation/les-bourses-de-la-saif/`). Ajout filtre PDF et vérification domaine via `parse_url`.
- **AdagpScraper** : sélecteurs corrigés. Parcours `article` → `h3 a` (évite les liens image sans texte).
- **MusiquesActuellesScraper** : ajout commentaire (domaine inaccessible). Aucune logique modifiée.

#### 26 mai 2026 — Feature : Découverte automatique de sources (app:discover-sources)

- Nouvelle entité `SuggestedSource` (table `suggested_sources`)
- Nouveau enum `SuggestedSourceStatus` (AValider / Validee / Rejetee)
- Nouvelle commande `DiscoverSourcesCommand` (app:discover-sources)
- Nouveau controller `AdminSuggestedSourceController` + template `/admin/suggested-sources`
- Ajout du champ `estAgregateur` sur `ScrapingSource`
- `LlmExtractorService` enrichi : méthode `discoverSources()`

#### 26 mai 2026 — Feature : Formulaires d'édition admin

- `AdminController::editScrapedOpportunity()` — route `GET+POST /admin/scraped-opportunities/{id}/edit`
- `AdminController::editResource()` — route `GET+POST /admin/resources/{id}/edit`
- Nouveaux templates : `scraped_opportunity_edit.html.twig`, `resource_edit.html.twig`
- Validation stricte des dates via `DateTime::getLastErrors()` (évite les dates comme "2026-02-30")
- Catch `UniqueConstraintViolationException` sur le flush (URL en doublon)

#### 26 mai 2026 — Fix : Archivage prématuré des nouvelles opportunités CNM

- **Problème** : `CnmScraper::scrapeApiPage()` stockait la date de publication (`post['date']`) comme deadline → `archiveExpired()` archivait immédiatement les articles anciens
- **Fix 1** : `extractDeadlineFromExcerpt()` — extrait la vraie deadline depuis l'extrait (3 patterns regex)
- **Fix 2** : `archiveExpired()` — grâce de 48h : les opportunités créées il y a moins de 48h ne sont jamais archivées
- 31 opportunités wrongly-archivées restaurées via SQL UPDATE

#### 26 mai 2026 — Feature : Scraping BDD-piloté + ScrapingSource

- Nouvelle entité `ScrapingSource` (table `scraping_sources`)
- `ScrapeOpportunitiesCommand` lit les sources depuis la BDD (plus de liste hardcodée)
- Nouveau `ScraperRegistry` (10 slugs → classes PHP)
- Nouveau `GenericScraper` (RSS 2.0 + Atom + HTML_LLM)
- Nouveau `AdminScrapingSourceController` + template `/admin/scraping-sources`
- `SeedScrapingSourcesCommand` (app:seed-scraping-sources) pour l'initialisation

#### 26 mai 2026 — Feature : CnmScraper WordPress REST API

- **Problème** : la page `/appels-a-projets/` utilise WP Interactivity API (JS) → 0 éléments HTML statiques
- **Solution** : REST API WP `/wp-json/wp/v2/posts?categories=42` — retourne du JSON directement
- Résultat : 9 → 49 opportunités par run

---

*Document maintenu par l'équipe Bazaart. Mettre à jour la section [Changelog](#13-changelog) à chaque modification significative du système de scraping.*

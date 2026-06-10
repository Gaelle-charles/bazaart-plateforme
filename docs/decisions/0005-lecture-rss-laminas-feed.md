# ADR-0005 — Lecture native des flux via `laminas/laminas-feed` ; `GenericScraper::scrapeRss()` déprécié

- **Date** : 2026-06-10
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Avant ce chantier, la lecture des flux RSS pour les sources sans classe dédiée passait par
`GenericScraper::scrapeRss()`, basé sur `SimpleXML`. Cette implémentation maison :

- gère RSS 2.0 et Atom via du code conditionnel manuel (`channel->item` vs `entry`,
  `<link>` texte vs `<link href>` attribut) ;
- ne couvre pas proprement les variantes (RSS 1.0 / RDF, namespaces Dublin Core, encodages
  exotiques, flux mal formés) ;
- n'expose pas d'interface unifiée.

Le chantier vise une collecte RSS robuste comme méthode primaire. Il fallait choisir entre
consolider le code SimpleXML existant ou adopter une bibliothèque éprouvée.

## Options envisagées

1. **`laminas/laminas-feed`** — Bibliothèque mature (ex-Zend), interface unifiée
   (`getTitle()`, `getLink()`, `getDateModified()`…), gère RSS 2.0 / RSS 1.0 / Atom et les
   namespaces. Avantages : robustesse réelle, moins de code conditionnel, maintenance externe.
   Inconvénients : une dépendance de prod supplémentaire ; la méthode SimpleXML existante
   devient redondante.

2. **Conserver SimpleXML maison** — Avantages : zéro dépendance.
   Inconvénients : fragilité, Atom mal géré, dette de maintenance à notre charge.

## Décision

**Option 1 retenue** : la lecture des flux RSS/Atom se fait via `laminas/laminas-feed`, dans
un nouveau `FeedReaderService`. La méthode `GenericScraper::scrapeRss()` est marquée
`@deprecated` mais **conservée** (non supprimée) pour ne pas toucher au pipeline scraping
existant et garder un filet de repli.

Justification : robustesse de parsing supérieure, code plus simple, et la dépendance est
légère et stable.

## Conséquences

- **Composer** : ajout de `laminas/laminas-feed` (+ dépendances transitives `laminas-escaper`,
  `laminas-stdlib`) en dépendance de prod. À installer au déploiement (`composer install`).
- **Sécurité** : toute description issue d'un flux passe par `HtmlSanitizerService` avant
  persistance (cf. ADR-0006). Aucun contenu de flux n'est jamais injecté dans un prompt LLM.
- **Code mort maîtrisé** : `GenericScraper::scrapeRss()` n'est plus appelée pour les sources
  RSS (routées vers `FeedReaderService`) ; elle reste disponible et documentée comme dépréciée.
- **À surveiller** : à terme, suppression de `scrapeRss()` une fois le pipeline RSS éprouvé en
  production (post-lancement).

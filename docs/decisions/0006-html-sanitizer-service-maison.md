# ADR-0006 — `HtmlSanitizerService` maison (strip total) plutôt que `symfony/html-sanitizer`

- **Date** : 2026-06-10
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Les descriptions des items RSS peuvent contenir du HTML arbitraire (parfois un article
complet). Ce HTML ne doit jamais se retrouver tel quel en base (risque XSS à l'affichage)
ni dans un prompt LLM (risque de prompt injection). Le besoin exprimé : **supprimer
totalement les tags** et **tronquer à 2000 caractères** avant persistance.

Le composant `symfony/html-sanitizer` n'était pas installé. Il fallait choisir l'outil de
nettoyage.

## Options envisagées

1. **Service maison `HtmlSanitizerService`** — `strip_tags()` total +
   `html_entity_decode(ENT_QUOTES | ENT_HTML5)` + normalisation des espaces + troncature
   `mb_substr` à 2000 caractères. Avantages : fait exactement le besoin (strip TOTAL), zéro
   dépendance, ~30 lignes, réutilisable, lisible. Inconvénients : code maison à maintenir
   (très simple).

2. **`symfony/html-sanitizer`** — Composant éprouvé. Avantages : robuste, maintenu.
   Inconvénients : il est conçu pour **conserver** un HTML sûr (whitelist de balises), pas
   pour tout supprimer. Surdimensionné et mal adapté à un strip total ; dépendance et
   configuration supplémentaires pour un résultat qu'un `strip_tags()` couvre déjà.

## Décision

**Option 1 retenue** : un `HtmlSanitizerService` maison réalise le strip total + décodage
des entités + troncature 2000 caractères.

Justification : pour un strip *total* (on ne garde aucun HTML), un composant de sanitization
par whitelist est inadapté ; un service dédié et commenté est plus juste et sans dépendance.

## Conséquences

- **Sécurité** : `FeedReaderService` appelle systématiquement `HtmlSanitizerService::sanitize()`
  sur la description avant de construire le DTO. Aucun tag HTML ne survit → pas d'XSS, pas de
  HTML transmis à un LLM.
- **Réutilisabilité** : le service pourra être réutilisé ailleurs (ex. nettoyage d'autres
  contenus externes) sans dépendre d'une config de whitelist.
- **À surveiller** : si un besoin futur exige de *conserver* du HTML sûr (mise en forme), il
  faudra alors envisager `symfony/html-sanitizer` — cas non pertinent en V1.

---
name: feedback_scraping_sources_feature
description: Patterns et anti-patterns détectés lors de la relecture de la feature ScrapingSource + Mistral + sources pilotables (mai 2026)
metadata:
  type: feedback
---

**Contexte :** Relecture feature 1 "ScrapingSource + Mistral + sources pilotables" (26 mai 2026).
Fichiers : ScrapingSource entité, ScrapingSourceType/ScrapingRunStatus enums, ScrapingSourceRepository, ScraperRegistry, GenericScraper, SeedScrapingSourcesCommand, AdminScrapingSourceController, LlmExtractorService (Mistral ajouté), ScrapeOpportunitiesCommand (refondé BDD), SeedSettingsCommand (2 nouveaux settings), AdminSettingController (testMistral), scraping_sources.html.twig, settings.html.twig, base_admin.html.twig (lien sidebar), migration Version20260526150555.

**Bugs critiques identifiés :**

1. **nb_items_dernier_run et statut_dernier_run sans DEFAULT SQL** — La migration crée les colonnes `nb_items_dernier_run INT NOT NULL` et `statut_dernier_run VARCHAR(20) NOT NULL` sans `DEFAULT 0` / `DEFAULT 'never_run'`. En pratique Doctrine envoie toujours les valeurs depuis PHP (init dans l'entité) donc pas de crash. Mais si un INSERT raw SQL est fait sans ces colonnes, il échouera. Convention : toujours aligner les DEFAULT SQL avec les valeurs par défaut PHP de l'entité.

2. **Flux Atom non supporté dans GenericScraper::scrapeRss()** — Le code parse `$feed->channel->item` (structure RSS 2.0). Les flux Atom utilisent `$feed->entry` (pas de `channel`). Si une source RSS est en réalité un flux Atom, `$feed->channel` retourne null → `$feed->channel->item` génère une notice PHP (accès propriété sur null) même avec le `?? []`. Le service retourne [] silencieusement sans markRunError visible.

3. **URL hardcodées dans le JS de settings.html.twig** — Les fonctions `testAnthropicConnection()` et `testMistralConnection()` appellent `/admin/settings/test-anthropic` et `/admin/settings/test-mistral` en dur. Si le préfixe de route change (déploiement sous-dossier), les appels AJAX cassent silencieusement. Fix : utiliser `data-url="{{ path('...') }}"` sur le bouton et lire depuis JS.

4. **Double getter isActive()/isActif() dans ScrapingSource** — L'entité expose deux méthodes avec le même rôle : `isActive()` (ligne 166) et `isActif()` (ligne 293). Twig appelle `source.actif` → PropertyAccessor trouve `isActif()` en premier. `isActive()` n'est jamais appelée (dead code). Risque de confusion lors de maintenance.

5. **$skipped jamais incrémenté dans SeedSettingsCommand** — La variable `$skipped` est déclarée ligne 125 mais les deux branches `if ($force)` et `else` incrémentent `$inserted`. `$skipped` reste à 0 et n'apparaît pas dans le message final. Le compteur de "créé ou inchangé" est incorrect.

6. **N flush dans boucle — SettingService::upsert()** — Confirmé : SettingService::upsert() appelle `$this->em->flush()` à chaque invocation. SeedSettingsCommand appelle upsert() 4 fois → 4 transactions individuelles. Anti-pattern déjà documenté. Fix : `$em->persist()` sans flush dans upsert(), puis flush unique dans SeedSettingsCommand après la boucle.

7. **Description de migration vide** — `getDescription()` retourne `''`. Convention projet : toujours décrire la migration. Fix : `'Ajout table scraping_sources + colonne disciplines dans scraped_resources'`.

**Avertissements :**

- **Validation longueur nom non appliquée** — Le controller vérifie que `$nom` n'est pas vide mais pas sa longueur maximale (255). Un nom de 500 chars tronquerait silencieusement (PostgreSQL lance une exception DataTooLong). Ajouter `mb_strlen($nom) > 255` avant persist.
- **Validation longueur url non appliquée côté PHP** — `filter_var FILTER_VALIDATE_URL` valide le format mais pas la longueur. L'entité supporte max 500 chars (colonne VARCHAR 500). Idem pour discipline (200) et zone (100).
- **createdAt non-nullable dans PHP mais pas DEFAULT en SQL** — `created_at TIMESTAMP(0) NOT NULL` sans `DEFAULT`. PrePersist le remplit toujours → OK en pratique. Mais si la migration est appliquée sur une table existante avec des lignes (pas le cas ici), elles auraient une valeur null invalide.
- **Atom vs RSS dans GenericScraper** — voir bug critique 2 ci-dessus. Ajouter un fallback `$items = $feed->channel->item ?? $feed->entry ?? []`.

**Patterns OK validés :**

- CSRF vérifié en premier dans create(), toggle(), delete() — ordre correct (avant toute logique).
- `#[IsGranted('ROLE_ADMIN')]` sur la classe → toutes les routes héritent sans redondance.
- Guard 404 (`find()` retourne null → flash error) avant toute modification.
- Filter FILTER_VALIDATE_URL sur l'URL avant persist — validation minimale robuste.
- Déduplication URL avant insert (findByUrl()) → erreur métier claire à l'admin.
- Validation type : seuls RSS et HtmlLlm acceptés dans le formulaire, HtmlCss bloqué côté serveur.
- Validation slug : getKnownSlugs() utilisé pour vérifier un slug soumis (non documenté dans le code mais présent en header de docblock).
- scraping_enabled correctement lu dans ScrapeOpportunitiesCommand (bug précédent corrigé).
- Secret écrasé si vide : protection présente dans AdminSettingController::update() (bug précédent corrigé).
- 10 slugs parfaitement cohérents entre ScraperRegistry et SeedScrapingSourcesCommand.
- testMistral() délégué à LlmExtractorService::testMistralConnection() — aucune logique HTTP dans le controller.
- Clé Mistral jamais loggée en clair (log uniquement dans warnings réseau sans valeur de la clé).
- down() migration réversible : DROP TABLE + DROP COLUMN.
- flash 'info' rendu dans base_admin.html.twig (correction antérieure validée).
- Pas de |raw dans les templates — tous les champs BDD passent par |e.
- source.nom|e('js') dans onsubmit confirm → XSS JS correctement géré.
- SeedScrapingSourcesCommand : flush unique en fin de boucle (batch correct, pas de N flushes).
- markRunSuccess()/markRunError() : modifient l'état en mémoire uniquement (flush fait par la commande) — séparation propre.
- GenericScraper gère correctement HTML_CSS retournant [] sans exception.

**Why:** Les bugs de DEFAULT SQL manquants et le double getter sont des incohérences structurelles bénignes en pratique mais qui créent de la fragilité. Le flux Atom non supporté est un bug fonctionnel silencieux qui impactera les prochaines sources ajoutées. Les URLs hardcodées en JS sont un problème de maintenabilité.

**How to apply:** Pour toute nouvelle entité : (1) vérifier que les DEFAULT SQL dans la migration correspondent aux valeurs PHP initiales, (2) éviter les getters doublons is/get sur le même champ, (3) dans le JS admin, toujours lire l'URL depuis un `data-url` Twig plutôt que de la hardcoder.

---

## Complément — Feature FeedDetectorService / detect-type (juin 2026)

Fichiers : `FeedDetectorService.php` (nouveau), `AdminScrapingSourceController.php` (route detectType ajoutée), `scraping_sources.html.twig` (bouton + JS).

**PHPStan niveau 6 : 0 erreur. Lint Twig : OK.**

**Patterns OK validés (points forts) :**

- `#[IsGranted('ROLE_ADMIN')]` déjà sur la classe → la route `/detect-type` hérite sans annotation supplémentaire.
- Validation `filter_var(FILTER_VALIDATE_URL)` dans `detectType()` avant tout appel réseau → SSRF réduit aux URLs syntaxiquement valides.
- `encodeURIComponent(url)` dans le `fetch()` JS → pas d'injection dans la query string.
- Endpoint GET, aucune mutation BDD → absence de CSRF explicitement justifiée et correcte.
- `\Throwable` attrapé dans `fetchAndInspect()` → aucune exception ne remonte jamais.
- Timeout 8s strict + maximum 6 variantes → pas de blocage côté interface.
- `feedback.textContent = message` (pas `innerHTML`) → XSS impossible sur le message retourné par le backend.
- `verify_peer: false` justifié explicitement dans le docblock (sites culturels avec SSL mal configuré, données publiques en lecture).
- Ordre de déclaration de routes : `/detect-type` avant `/{id}/edit` → routeur Symfony ne capte pas "detect-type" comme valeur d'`{id}`.
- `data.message` affiché via `textContent` (pas `innerHTML`) → sûr même si le backend renvoie un message contenant du HTML.

**Avertissements :**

1. **SSRF partiel : schémas file:// et ftp:// non bloqués** — `filter_var(FILTER_VALIDATE_URL)` valide aussi `file:///etc/passwd` et `ftp://...`. L'accès ROLE_ADMIN réduit le risque, mais un admin compromis (ou une erreur de test) pourrait déclencher une requête `file://`. Fix recommandé : ajouter une vérification du schéma dans `detectType()` :
   ```php
   $parsed = parse_url($url);
   if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
       return new JsonResponse(['error' => 'Seuls les schémas http:// et https:// sont autorisés.'], 400);
   }
   ```

2. **Pas de rate-limiting sur `/detect-type`** — Un admin peut déclencher autant d'appels AJAX vers des hôtes tiers qu'il veut (boucle JS, outil HTTP). Pour un endpoint qui fait des requêtes réseau sortantes, un rate-limiter Symfony (`RateLimiterFactory`) éviterait les abus accidentels ou intentionnels. Risque modéré car ROLE_ADMIN est un périmètre restreint.

3. **`verify_peer: false` + `verify_host: false`** — Désactiver la validation SSL est compréhensible pour la détection (sites culturels avec certificats auto-signés), mais cela ouvre à des attaques MITM si le réseau Docker n'est pas de confiance. Acceptable en V1 avec la justification documentée, à ré-évaluer si l'app tourne dans un environnement multi-tenant.

4. **`<channel>` comme marqueur RSS peut fausser la détection** — `str_contains($bodyLower, '<channel>')` est un marqueur fragile : `<channel>` est aussi utilisé dans certains podcasts HTML (balise `<channel>` non-standard) et dans des pages HTML qui décrivent un canal. Un faux-positif est peu probable mais possible. Le marqueur `<rss` ou `<feed` (Atom) sont plus fiables. Suggestion : limiter `<channel>` à un contexte où il apparaît après `<rss` ou en première position dans le body.

5. **`extractBaseUrl()` perd le port non-standard** — Si une source est sur `https://site.fr:8080/actualites/`, la base extraite est `https://site.fr` (le port est absent). Les variantes `/feed/`, `/rss.xml` etc. seront testées sans le port → 404 garantis. Fix : inclure le port dans la reconstruction :
   ```php
   $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
   return $scheme . '://' . $host . $port;
   ```
   En pratique les sites culturels utilisent 80/443 → le manque est bénin mais mérite correction.

**Suggestions :**

- Le bouton "Détecter" n'est disponible que dans le formulaire d'ajout, pas dans `scraping_source_edit.html.twig`. Si ce template existe et contient un champ URL, il serait cohérent d'y ajouter le même bouton (DX).
- `afficherFeedback('✓ ' + data.message, couleur)` : le `data.message` vient du backend PHP, donc de confiance (pas d'entrée utilisateur directe). Mais si un admin entre une URL dont le feed retourne un message forgé via redirect, ce message apparaîtrait tel quel. Risque nul ici car `textContent` est utilisé (pas `innerHTML`).

**How to apply (complément) :** Pour tout endpoint admin qui déclenche des requêtes réseau sortantes, toujours (1) valider le schéma http/https explicitement en plus de FILTER_VALIDATE_URL, (2) envisager un rate-limiter même pour ROLE_ADMIN, (3) inclure le port dans la reconstruction d'URL de base.

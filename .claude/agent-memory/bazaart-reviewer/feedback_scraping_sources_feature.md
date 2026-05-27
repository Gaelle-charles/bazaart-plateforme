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

---
name: feedback_scraping_expansion_module_patterns
description: Patterns et anti-patterns détectés lors de la relecture du module scraping étendu (AppSetting, LlmExtractor, scrapers européens, AdminSettingController) — mai 2026
metadata:
  type: feedback
---

**Contexte :** Relecture du module scraping expansion (25-26 mai 2026) — AppSetting, SettingService, LlmExtractorService, OnTheMoveScraper, ResartisScraper, CultureMovesEuropeScraper, AdminSettingController, SeedSettingsCommand, ScrapeOpportunitiesCommand.

**Relecture complémentaire scrapers (26 mai 2026) :** OnTheMoveScraper (réécriture CSS), AdagpScraper (fix sélecteurs), CultureMovesEuropeScraper (URL seule), SaifScraper (URLs corrigées + filtres PDF/domaine), MusiquesActuellesScraper (commentaire seul).

**Bugs critiques identifiés :**

1. **Secret écrasé si champ laissé vide** — AdminSettingController.update() appelle SettingService.set(key, null) dès que le champ est vide. Or le placeholder indique "laisser vide pour ne pas changer". Pour un setting isSecret=true, il faut ajouter : `if ($setting->isSecret() && $valueToSave === null) { $this->addFlash('success', ...); return $this->redirectToRoute(...); }` pour ignorer la soumission vide.

2. **scraping_enabled ignoré** — Le setting `scraping_enabled` est défini et seedé, mais ScrapeOpportunitiesCommand ne l'interroge jamais. La case "désactiver le scraping" n'a aucun effet.

3. **Logique métier dans le controller** — testAnthropic() dans AdminSettingController appelle directement $this->httpClient sur l'API Anthropic avec duplication du code de LlmExtractorService. Cette logique appartient dans un service dédié (ex: AnthropicTestService ou dans LlmExtractorService::testConnection()).

4. **declare(strict_types=1) absent** — ScrapeOpportunitiesCommand, GoogleSheetsService, ScrapedOpportunity n'ont pas la déclaration strict_types.

5. **AbstractScraper sans strict_types** — Même problème.

**Avertissements :**

- **Double calcul du score** — ScrapeOpportunitiesCommand recalcule $score deux fois : ligne preview (216) et ligne insertion BDD (248). Le score déjà calculé dans $opp->relevanceScore par les scrapers n'est pas réutilisé.
- **Incohérence description 200/300 chars** — Le prompt LLM demande "max 200 caractères" mais mapItemsToOpportunities() tronque à 300. La convention projet est 200.
- **N flush dans boucle** — SettingService.upsert() appelle $this->em->flush() à chaque appel. SeedSettingsCommand appelle upsert() dans une boucle → N flushes au lieu de 1.
- **Données disciplines perdues** — Le DTO ScrapedOpportunity.disciplines est rempli par les scrapers mais ScrapedResource n'a pas de champ disciplines → donnée perdue à l'insertion BDD. Documenter ou ajouter le champ.
- **getMessage() exposé dans JsonResponse** — testAnthropic() retourne l'exception message brut au client. Si le HttpClient inclut des détails réseau sensibles, ils seront visibles. En admin-only le risque est limité mais à nettoyer.
- **SeedSettingsCommand : $inserted incrémenté dans les deux branches** — Les deux branches `if ($force)` et `else` incrémentent $inserted. La variable $skipped n'est jamais utilisée dans le message final.
- **getDebugInfo() : type de retour `array` sans docblock** — PHPStan niveau 6 signale `array` non typé. Corriger en `array<string, mixed>`.

**Patterns OK validés :**

- CSRF correctement vérifié en premier avant toute logique dans update() et testAnthropic().
- #[IsGranted('ROLE_ADMIN')] sur la classe — toutes les routes héritent.
- Guard 404 sur le setting key avant modification.
- LlmExtractorService ne lève jamais d'exception (try/catch global + return []).
- Clé API jamais loggée en clair dans LlmExtractorService.
- Champ secret affiché en `type="password"` côté Twig, valeur jamais rendue en HTML.
- La valeur secrète utilisée uniquement en booléen dans le placeholder (truthy check) — valeur non exposée.
- Route /test-anthropic avec tiret ne peut pas être capturée par /{key}=[a-z0-9_]+ → pas de collision.
- Migration app_settings cohérente avec l'entité AppSetting.
- @deprecated correctement placé sur la classe GoogleSheetsService et la méthode toSheetRow().
- Option --source dans ScrapeOpportunitiesCommand : filtre sur getName() — correct.
- fetchHtml() ajoutée dans AbstractScraper, rétrocompatible (fetch() toujours présente).
- scrapedAt géré par #[ORM\PrePersist] dans ScrapedResource — la commande n'a pas à le setté.

**Patterns des scrapers V2 (mai 2026) :**

- **Commentaire trompeur dans OnTheMoveScraper** (ligne 151-152) : le commentaire dit "closest() n'existe pas dans DomCrawler → on utilise ancestors()" mais le code appelle directement `$linkNode->closest('.field-content')`. closest() EXISTE dans symfony/dom-crawler v7.4.6 (ligne 407 de Crawler.php). Le commentaire est périmé — il décrivait une intention de workaround finalement non implémentée.
- **Keywords redondants dans AdagpScraper** : 'appel à' et 'appels à' sont capturés par 'appel' (str_contains est substring). Les deux entrées supplémentaires n'ont aucun effet.
- **AbstractRssScraper sans declare(strict_types=1)** : tous les scrapers enfants (MusiquesActuellesScraper etc.) ont bien le declare, mais la classe parente AbstractRssScraper ne l'a pas.
- **Filtre domaine saif.fr fragile** : `str_contains($absoluteUrl, 'saif.fr')` passerait une URL comme `https://evil.com?ref=saif.fr`. Dans le contexte scraper le risque est quasi nul (les liens extraits viennent d'un site connu), mais la convention robuste est `parse_url($absoluteUrl, PHP_URL_HOST)` comparé à 'saif.fr' ou 'www.saif.fr'.
- **CultureMovesEuropeScraper garde LLM + relevanceScorer** : c'est intentionnel (pas de structure HTML stable sur la page UE), la description "changement URL seul" est conforme.
- **OnTheMoveScraper constructeur explicite non nécessaire** : le constructeur appelle `parent::__construct($httpClient)` sans rien ajouter. Peut être supprimé (AbstractScraper le gère). Pas un bug, style seulement.

**Why:** Les bugs de secret écrasé et scraping_enabled ignoré sont des incohérences fonctionnelles entre la promesse UI et le code réel. Le strict_types manquant sur les fichiers modifiés est une violation de convention CLAUDE.md.

**How to apply:** Vérifier systématiquement (1) que les settings secrets ont une garde "ne pas écraser si champ vide", (2) que les settings documentés sont effectivement lus dans le code correspondant, (3) que strict_types=1 figure sur TOUS les fichiers PHP du projet, (4) que les commentaires autour de closest()/ancestors() DomCrawler sont à jour (closest() existe dès DomCrawler 5.4+ et sur v7.x).

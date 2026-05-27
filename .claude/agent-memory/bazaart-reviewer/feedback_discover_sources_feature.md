---
name: feedback_discover_sources_feature
description: Patterns et anti-patterns détectés lors de la relecture de la Feature 2 « Découverte assistée de nouvelles sources » (mai 2026)
metadata:
  type: feedback
---

**Contexte :** Relecture feature 2 "Découverte assistée de nouvelles sources" (26 mai 2026).
Fichiers : SuggestedSourceStatus enum, SuggestedSource entité, SuggestedSourceRepository, DiscoverSourcesCommand, AdminSuggestedSourceController, suggested_sources.html.twig, ScrapingSource (champ estAgregateur), ScrapingSourceRepository (findActiveAggregators), LlmExtractorService (discoverSources ajouté), SeedScrapingSourcesCommand (estAgregateur renseigné), base_admin.html.twig (lien sidebar), migration Version20260526154321.

**Bugs identifiés :**

1. **MAJEUR — validate() logique métier non-triviale dans un controller** — Le controller AdminSuggestedSourceController::validate() orchestre 6 vérifications + création d'une ScrapingSource + modification de statut directement dans le controller. CLAUDE.md §4 dit "Pas de logique métier dans les controllers". Ici la logique reste simple et documentée — acceptable en V1 comme compromis pragmatique. À extraire dans un SuggestedSourceService::validate() en V2.

2. **MINEUR — INDEX manquant sur suggested_sources.url** — La migration crée la table sans index sur la colonne `url`. La méthode existsByUrl() fait un COUNT sur url à chaque run (potentiellement N appels par agrégateur). Sans index, scan séquentiel. Fix : `CREATE INDEX idx_suggested_sources_url ON suggested_sources (url)` dans la migration ou via un addSql supplémentaire.

3. **MINEUR — INDEX manquant sur suggested_sources.statut** — findAllByStatut() et countByStatut() filtrent systématiquement sur le champ `statut`. Sans index, scan complet. Fix : `CREATE INDEX idx_suggested_sources_statut ON suggested_sources (statut)`.

4. **MINEUR — discoverSources envoie le HTML brut (non nettoyé) au LLM** — Contrairement à extractFromHtml() qui appelle cleanHtml() pour retirer nav/header/footer avant l'envoi, discoverSources envoie le HTML brut (avec balises) tronqué à 30 000 chars. Justification partielle : les URLs sont dans les attributs href. Mais le bruit HTML gaspille des tokens et peut confondre le LLM sur le contenu. Alternative : envoyer le HTML avec les liens préservés mais le texte nettoyé (mode "avec href").

5. **MINEUR — typePressenti non validé à la création (SuggestedSource)** — L'entité `SuggestedSource::$typePressenti` est `string length:50 nullable`. Le LLM peut retourner n'importe quelle chaîne (ex: "RSS 2.0", "Atom", "JSON API"). La commande ne valide pas que la valeur est dans ['RSS', 'HTML_LLM']. Risque : la pré-sélection du dropdown dans le template peut ne pas trouver de correspondance. Comportement sûr (le select ne plante pas), mais la pré-sélection sera incorrecte.

6. **MINEUR — URL non normalisée (trailing slash)** — existsByUrl() compare en exact. Si une source est connue sous "https://example.com/" et que le LLM retourne "https://example.com" (sans slash), la déduplication échoue. Fix : normaliser l'URL avant comparaison (rtrim($url, '/') dans mapItemsToSources).

7. **COSMÉTIQUE — ScrapingSource créée par validate() sans sluggerSlug ni nom canonique** — Le `scraperSlug` est explicitement null (commenté). Mais le `nom` est copié tel quel depuis le LLM (ex: "Institut Français de Berlin"). L'admin peut corriger depuis /admin/scraping-sources, ce qui est documenté. Acceptable en V1.

**Patterns correctement implémentés :**

- `declare(strict_types=1)` présent dans tous les fichiers PHP de la feature.
- `#[IsGranted('ROLE_ADMIN')]` sur la classe — toutes les routes héritent.
- CSRF vérifié EN PREMIER dans validate() et reject() — ordre correct.
- Guard statut AValider avant toute action (idempotence) — empêche la double-soumission.
- Guard URL présente avant création ScrapingSource — validation métier correcte.
- Double déduplication URL (scraping_sources + suggested_sources) — robuste.
- existsByUrl() cherche dans TOUS les statuts (pas seulement AValider) — correct.
- Flush unique en fin de commande (pas de N flushes en boucle) — anti-pattern évité.
- `break 2` correct pour sortir des deux boucles imbriquées dès le plafond atteint.
- Plafond vérifié AVANT création (pas après) — l'arrêt est immédiat.
- HTML vide → skip ($html === null) géré correctement dans downloadHtml().
- URL null/vide du LLM → skip dans la commande (empty($url) check).
- discoverSources() retourne [] en cas d'erreur (jamais d'exception non catchée).
- Isolation absolue respectée : aucun import de ScrapedResource dans DiscoverSourcesCommand.
- Migration avec getDescription() non vide et down() réversible.
- DEFAULT false sur est_agregateur dans la migration — cohérent avec la valeur PHP.
- Pas de |raw dans suggested_sources.html.twig — toutes les sorties BDD passent par |e.
- |e('js') dans onsubmit confirm — XSS JS correctement géré.
- 3 sections distinctes (À valider, Validées, Rejetées) toutes présentes.
- Dropdown type_pressenti dans le formulaire de validation — présent et fonctionnel.
- Lien sidebar dans base_admin.html.twig — présent et actif sur les routes correctes.

**Patterns à surveiller dans les prochaines features :**

- Index manquants sur colonnes filtrées fréquemment (url, statut) — signaler systématiquement.
- HTML brut vs texte nettoyé dans les appels LLM — analyser l'impact sur la qualité.
- Trailing slash dans les URLs — normaliser avant comparaison.

**Why:** La feature est globalement très propre. Les points majeurs sont des compromis pragmatiques documentés (logique controller) ou des oublis d'optimisation (index BDD) qui n'ont pas d'impact fonctionnel immédiat mais peuvent devenir des goulots d'étranglement à l'échelle.

**How to apply:** Pour toute nouvelle table avec des colonnes de filtrage fréquent (statut, url, user_id), toujours ajouter les index dans la migration. Pour les appels LLM avec du HTML, documenter clairement le choix HTML brut vs texte nettoyé et ses justifications.

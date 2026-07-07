# ADR-0034 — Auto-validation des sources RSS découvertes automatiquement

- **Date** : 2026-07-07
- **Statut** : arbitré
- **Décidé par** : Gaëlle
- **Révise** : ADR-0003 (sources suggérées — périmètre V2)

## Contexte

Gaëlle veut que l'ajout de nouvelles sources de scraping soit **automatique**, pas
manuel. L'état actuel :

- `app:discover-sources` tourne au cron (hebdo) et **découvre** de nouvelles sources en
  analysant les agrégateurs → il crée des `SuggestedSource` (statut `AValider`), jamais
  de `ScrapingSource` active directement.
- La promotion suggestion → source active est **100% manuelle** : un admin clique
  « Valider » sur `/admin/suggested-sources`
  (`AdminSuggestedSourceController::validate()`), ce qui instancie une `ScrapingSource`.
- L'ADR-0003 avait volontairement laissé cette file de suggestions en revue humaine
  (périmètre V2).

Donc le **déclenchement** est déjà automatique, mais la **promotion** reste manuelle :
c'est cette dernière étape que Gaëlle perçoit comme « manuelle ». Le risque d'un
auto-ajout aveugle : polluer la file de modération des opportunités avec des sources
non fonctionnelles ou hors-sujet, et gonfler le coût LLM (sources HTML+LLM).

## Options envisagées

1. **Auto-valider uniquement les suggestions RSS à flux confirmé (retenue).**
   N'auto-promouvoir qu'une suggestion de type **RSS** dont `FeedDetectorService`
   (déjà existant, utilisé par `app:detect-feeds` et le formulaire admin) confirme un
   **flux fonctionnel réel** à l'URL. Les suggestions **HTML/LLM** restent en validation
   manuelle (risque de bruit et coût LLM plus élevés).
   - ➕ Garde-fou qualité fort (flux réellement lisible), coût nul (pas de LLM récurrent).
   - ➕ Cohérent avec l'existant (RSS = cadence légère ; HTML_LLM = coûteux/prudent).
   - ➖ Ne couvre pas les sources HTML (mais c'est justement là qu'on veut garder l'humain).
2. **Digest admin** (email hebdo des suggestions en attente). Automatise le rappel, pas
   la décision. Ne répond pas au souhait de Gaëlle.
3. **Score de confiance LLM** + auto-validation au-dessus d'un seuil. Plus souple mais
   repose sur un signal non déterministe → risque de dérive qualité.

## Décision

**Option 1.** Après la découverte (`app:discover-sources`), les suggestions de type
**RSS** dont le flux est **confirmé fonctionnel** par `FeedDetectorService` sont
**auto-promues** en `ScrapingSource` active, sans intervention humaine. Les suggestions
non-RSS (HTML/HtmlLlm/HtmlCss) restent en file de validation manuelle comme aujourd'hui.

Garde-fous qualité obligatoires :
- Dédup par URL (ne pas recréer une source existante — `findByUrl`).
- Le flux doit être confirmé lisible (pas juste un HTTP 200) par `FeedDetectorService`.
- Traçabilité : logguer chaque auto-validation (source, url) et marquer la
  `SuggestedSource` comme auto-validée (statut distinct ou champ, pour audit).
- Auto-désactivation existante conservée : si une source auto-ajoutée échoue N fois,
  elle est désactivée automatiquement (rien à changer).

## Conséquences

- Révise l'ADR-0003 : la file de suggestions n'est plus **exclusivement** manuelle ; la
  branche RSS-à-flux-confirmé devient automatique. La revue humaine subsiste pour le HTML.
- De nouvelles sources RSS fiables entrent dans le pipeline sans action de Gaëlle.
- À surveiller : volume de la file de modération des opportunités (si une source
  auto-ajoutée est hors-sujet mais techniquement un vrai flux, elle produira du bruit —
  l'`AfrodiasporaRelevanceScorer` et la modération restent le filet). Prévoir un moyen
  simple de désactiver une source auto-ajoutée depuis l'admin (déjà possible).
- Cohérent avec la préférence durable de Gaëlle : découverte + ajout automatiques,
  pas de seed manuel récurrent.

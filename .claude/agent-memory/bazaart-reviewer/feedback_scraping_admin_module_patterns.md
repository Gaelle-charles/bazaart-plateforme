---
name: feedback_scraping_admin_module_patterns
description: Patterns et anti-patterns détectés lors de la relecture du module scraping admin (mai 2026)
metadata:
  type: feedback
---

**Contexte :** Relecture du module scraping admin (25 mai 2026) — ajout case `Rejected` dans `ScrapedResourceStatus`, nouvelles actions `rejectScrapedOpportunity()` / `runScraping()`, nouveaux onglets Twig.

**Pattern OK validés :**
- CSRF vérifié avant toute logique dans les deux actions POST (ordre correct).
- `#[IsGranted('ROLE_ADMIN')]` sur la classe — toutes les routes héritent sans redondance.
- Guard 404 (`createNotFoundException`) avant `getTitle()` dans `rejectScrapedOpportunity()`.
- `getSingleScalarResult()` pour `MAX(scrapedAt)` avec retour `?\DateTimeInterface` et null-check explicite.
- `Process::start()` (non-bloquant) + `setTimeout(null)` : correct pour usage admin ponctuel.
- Variables `rejected` et `latestScrapedAt` correctement passées par `scrapedOpportunities()`.
- Template gère `latestScrapedAt null` (affiche 'jamais lancé').
- Noms de routes concordants contrôleur/template (`app_admin_scraped_opportunity_reject`, `app_admin_scraping_run`).
- `getParameter('kernel.environment')` dans `AbstractController` est acceptable (méthode héritée).

**Anti-patterns détectés :**

1. `.adm-btn-reject` n'a pas `border-radius: 0` explicite — alors que le design Street exige des bords francs (corrigé depuis, le style est présent dans scraped_opportunities.html.twig). À re-vérifier sur tout nouveau template.

2. (Mai 2026 — formulaires édit admin) `DateTime::createFromFormat('Y-m-d', $str)` ne retourne pas `false` sur une date invalide comme `2026-02-30` : il renvoie un objet `DateTime` décalé (2 mars) avec un warning dans `getLastErrors()`. Toujours vérifier `DateTime::getLastErrors()['warning_count'] > 0` après `createFromFormat`.

3. (Mai 2026 — formulaires édit admin) Les longueurs `type` (max 100) et `url` (max 500) de `ScrapedResource` ne sont pas vérifiées côté PHP dans `editScrapedOpportunity()`. La contrainte BDD est la seule protection, ce qui produit une exception Doctrine non gérée si un admin colle une URL de 600 chars.

4. (Mai 2026 — formulaires édit admin) Le lien "Modifier" dans `scraped_opportunities.html.twig` réutilise la classe `adm-btn-reject` avec un override style inline pour changer couleur de fond. Pattern fragile : si `.adm-btn-reject` évolue, le rendu du bouton Modifier diverge. Préférer une classe dédiée `adm-btn-edit`.

5. (Mai 2026 — formulaires édit admin) `scraped_opportunity_edit.html.twig` n'a pas de `@media (max-width: ...)` pour le grid 2 colonnes — contrairement à `resource_edit.html.twig` qui le gère. Incohérence responsive entre les deux templates d'édition.

**Why:** Validation manuelle sans FormType = chaque champ doit être contraint explicitement côté PHP, y compris les longueurs. Les raccourcis CSS (override inline sur classe existante) créent de la dette de maintenabilité.

**How to apply:** À chaque validation manuelle : lister TOUS les champs, croiser avec les `length` Doctrine. Pour les dates : `createFromFormat` + `getLastErrors()`. Pour les classes CSS : créer une classe dédiée plutôt que surcharger inline.

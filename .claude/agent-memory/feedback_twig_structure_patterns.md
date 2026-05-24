---
name: feedback_twig_structure_patterns
description: Templates Twig du module Structure — variables manquantes dans register, double boucle flash info, POST custom sans FormType, valeur 'pending_validation' absente du filtre stats
metadata:
  type: feedback
---

Anti-patterns identifiés lors de la revue QA des templates structure/register.html.twig et structure/dashboard.html.twig (mai 2026).

**1. Variables manquantes dans register.html.twig**
Le template utilise `form`, `alreadyApplied` et `applicationStatus` mais le contrôleur ne passe que `orgProfile`. Ces trois variables sont indéfinies au runtime — erreur 500 garantie sur la route GET /structure/register.

**2. Double boucle flash 'info' dans register.html.twig**
Le template itère `app.flashes('info')` à la ligne 491 ; `base_app.html.twig` (le parent) itère également `app.flashes('info')` dans son bloc contenu. Les deux boucles consomment le même tableau de session : la première vide le tableau, la seconde ne trouve rien. Selon l'ordre d'exécution Twig, l'un des deux rendus sera toujours vide.

**3. POST custom sans FormType dans register**
Le contrôleur vérifie le token CSRF manuellement (`isCsrfTokenValid('structure_register', ...)`) et lit `$request->request->all()` en tableau brut. Mais le template utilise `{{ form_start(form) }}` (FormView Symfony), qui génère un token nommé `_token` sans namespace. La clé `'structure_register'` attendue par le contrôleur et le champ `_token` généré par form_start ne correspondent pas — la vérification CSRF échoue systématiquement.

**4. Valeur 'pending_validation' absente du filtre stats dans dashboard**
Le filtre `resources|filter(r => r.status.value == 'pending')` est correct (la valeur backed de PendingValidation est 'pending'). Le commentaire dans le template est donc exact. Ne pas confondre avec la dénomination 'pending_validation' qui n'existe pas comme valeur backed dans ResourceStatus.

**5. Stat 'archived' absente de la grille des compteurs dashboard**
La grille affiche 4 cases (total, publiées, en attente, refusées) mais pas les ressources archivées. Ce n'est pas un bug bloquant mais une incohérence : les ressources archivées gonflent le total sans être comptées dans aucune sous-case.

**Why:** Contrôleur register refactorisé en POST custom après une première version FormType, sans mise à jour du template correspondant.

**How to apply:** Lors de relecture de templates structure/, vérifier systématiquement la correspondance entre `render()` et les variables attendues par le template.

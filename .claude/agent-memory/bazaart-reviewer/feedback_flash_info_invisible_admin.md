---
name: feedback_flash_info_invisible_admin
description: Bug flash 'info' dans base_admin — CORRIGÉ au 23 mai 2026
metadata:
  type: feedback
---

Bug initial : `base_admin.html.twig` ne rendait que les flashs de type `success` et `error`. Le type `info` n'était pas traité.

Conséquence concrète : dans `AdminController::rejectStructureApplication()`, le flash `addFlash('info', ...)` n'était jamais affiché. L'admin voyait la page se recharger sans feedback visible.

**CORRIGÉ au 23 mai 2026** : la ligne `{% for message in app.flashes('info') %}` a été ajoutée dans `base_admin.html.twig` (ligne 371). Le CSS `.flash--info` est également présent.

**Why:** Détecté lors de la relecture du module Compte Structure (mai 2026), corrigé dans la livraison Ressourcerie tâche 3.

**How to apply:** Bug résolu. Continuer à vérifier la cohérence entre les types de flash utilisés dans les controllers et ceux rendus dans les templates de base. Cf. [[feedback_getRoles_hierarchy]] pour un autre anti-pattern de ce module.

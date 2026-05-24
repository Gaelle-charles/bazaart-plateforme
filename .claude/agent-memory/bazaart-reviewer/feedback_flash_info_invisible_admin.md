---
name: feedback_flash_info_invisible_admin
description: Anti-pattern récurrent — flash 'info' absent des templates de base (base_admin et base_app)
metadata:
  type: feedback
---

**Règle :** Vérifier systématiquement que TOUS les types de flash utilisés dans les controllers (`success`, `error`, `info`, `warning`) sont bien rendus dans chaque template de base (base_admin, base_app, etc.).

**Why:** Pattern détecté deux fois :
1. `base_admin.html.twig` — ne rendait que `success` et `error`. Corrigé au 23 mai 2026.
2. `base_app.html.twig` — même bug détecté lors de la relecture du design system (23 mai 2026). `OrganizationProfileController` et `ArtistProfileController` émettent tous deux `addFlash('info', ...)` mais `base_app` ne rendait que `success` et `error`. **Corrigé dans la PR design-tokens (23 mai 2026).** Le flash `info` est désormais rendu via `.flash--info` définie dans base.html.twig.

**How to apply:** À chaque nouvelle relecture d'un template de base (`base_*.html.twig`), vérifier les types de flash rendus ET rechercher dans les controllers enfants quels types sont émis. Un `grep` sur `addFlash('info'` et `addFlash('warning'` est le réflexe de base. Penser aussi à vérifier que la classe CSS correspondante est bien définie quelque part (base.html.twig inline style ou fichier CSS global).

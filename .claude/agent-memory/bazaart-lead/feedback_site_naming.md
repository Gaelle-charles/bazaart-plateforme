---
name: feedback-site-naming
description: NE JAMAIS confondre app.bazaart.fr (la plateforme qu'on développe) avec bazaart.fr (la vitrine) — règle non négociable de Gaëlle
metadata:
  type: feedback
---

**On développe `app.bazaart.fr` (la plateforme Symfony). JAMAIS `bazaart.fr`.**

`bazaart.fr` est **la vitrine** (site séparé). Elle ne doit JAMAIS être confondue avec la
plateforme, ni touchée, ni supprimée. Les deux cohabitent sur le même droplet (cf.
[[project_plateforme_infra]]).

**Why:** Gaëlle m'a repris explicitement après que j'ai parlé de « bazaart.fr » à propos du
déploiement de la plateforme. Confondre les deux est une erreur grave (risque de toucher/casser
la vitrine). Elle insiste : à garder en mémoire, vitrine jamais supprimée.

**How to apply:**
- Quand je parle de la prod / du déploiement / de l'URL en ligne de la plateforme : toujours dire
  **`app.bazaart.fr`**, jamais « bazaart.fr ».
- Toute opération de déploiement vise **uniquement** la plateforme (`bazaart_platform_app`), sans
  jamais impacter la vitrine.
- En cas de doute sur quel site, demander — ne jamais supposer que c'est la vitrine.

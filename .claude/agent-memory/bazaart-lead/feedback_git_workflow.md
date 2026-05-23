---
name: feedback-git-workflow
description: Organisation Git réelle du projet — main stable, demo travail, pas de feature/* ni dev
metadata:
  type: feedback
---

Ne jamais proposer de branche `dev`, `feature/*` ou toute autre nomenclature de branches.

**Why:** Gaëlle utilise un workflow intentionnellement simple à deux branches :
- `main` = branche stable de référence (déploiement prod)
- `demo` = branche de travail unique (développement en cours)

Elle a corrigé une suggestion de merge et clarifié que `demo` a été créée à partir de `main` — les deux étaient identiques à ce moment-là. Pas de merge à faire automatiquement.

**How to apply:**
- Ne jamais créer de branche `dev` ou `feature/<module>-<description>`
- Ne jamais proposer de merge sans confirmation explicite de Gaëlle
- Si on doit nommer une branche de travail dans une réponse, c'est toujours `demo`
- La règle CLAUDE.md section 12 dit : "❌ Merger `demo` dans `main` sans validation — toujours demander confirmation à Gaëlle"

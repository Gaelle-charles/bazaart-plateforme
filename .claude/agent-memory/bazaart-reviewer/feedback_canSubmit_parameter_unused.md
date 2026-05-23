---
name: feedback_canSubmit_parameter_unused
description: Paramètre $user inutilisé dans les méthodes canSubmit()/canCreate()/canRegister() des Voters — PHPStan peut signaler une violation selon la version
metadata:
  type: feedback
---

Dans les voters, certaines méthodes privées reçoivent `$user` en paramètre mais ne l'utilisent pas (ex: `canSubmit(User $user): bool { return true; }`). PHPStan niveau 6+ peut signaler "Parameter $user is never used" selon la configuration.

**Why:** Repéré lors de la relecture ResourceVoter, ForumVoter, LiveVoter (mai 2026). Le paramètre est conservé pour la cohérence de la signature et l'évolutivité, mais certains linters le signalent.

**How to apply:** Vérifier dans chaque nouveau voter si les paramètres inutilisés déclenchent des violations PHPStan. Si oui, ajouter un commentaire `// $user non utilisé : toute personne authentifiée peut effectuer cette action` ou préfixer par `_` si la version PHP le supporte.

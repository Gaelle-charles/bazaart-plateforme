---
name: feedback-security-antipatterns
description: Anti-patterns sécurité récurrents à vérifier systématiquement sur toute feature d'authentification Bazaart
metadata:
  type: feedback
---

Patterns à contrôler systématiquement lors de toute relecture d'une feature auth/reset :

1. **Normalisation email manquante** — Le contrôleur fait `trim()` mais pas `strtolower()`. Si l'inscription enregistre "Alice@example.com" et que l'utilisateur saisit "alice@example.com" au reset, `findOneBy(['email' => $email])` ne trouve rien. Toujours vérifier la cohérence de normalisation entre inscription et reset.

2. **Token en clair dans URL → logs Nginx** — Les URL complètes `/reinitialiser-mot-de-passe/{token}` apparaissent dans `access_log` Nginx par défaut. Si `access_log` n'est pas désactivé ou filtré, le token est loggué en clair côté serveur. Vérifier la présence d'une directive `access_log` ou d'un filtre dans nginx.conf.

3. **Comparaison de hash via BDD (pas de timing attack réelle ici)** — La comparaison du hash SHA-256 se fait via une requête SQL (`findOneBy`), pas via `===` PHP direct. Il n'y a donc pas de timing attack exploitable en PHP. C'est acceptable. Ne pas confondre avec une comparaison PHP directe qui nécessiterait `hash_equals()`.

4. **Validation asymétrique dans fromArray()** — Dans ResetPasswordDTO, `empty($data['password'])` rejette les chaînes vides ET `"0"`, mais `!isset($data['confirm_password'])` accepte `""` comme valeur valide pour confirm_password. Un utilisateur peut soumettre confirm_password="" et passer la garde du DTO, puis échouer sur `doPasswordsMatch()`. C'est fonctionnellement correct mais incohérent — idéalement utiliser `empty()` sur les deux.

5. **Absence de tests unitaires sur PasswordResetService** — Aucun test ne couvre le parcours reset. Cas critiques non testés : token expiré, token déjà utilisé, compte anonymisé. Signaler systématiquement l'absence de tests sur les services d'authentification.

6. **Index Doctrine déclaré hors entité** — L'index `idx_users_reset_token_hash` est créé dans la migration SQL brute, pas via `#[ORM\Table(indexes: [...])]`. C'est un choix délibéré (commenté dans le code) mais cela signifie que `doctrine:schema:validate` peut signaler une divergence. À noter dans les reviews.

**Why:** Ces patterns ont été identifiés lors de la relecture de la fonctionnalité mot de passe oublié (2026-06-11).
**How to apply:** Checker systématiquement ces 6 points lors de toute relecture d'une feature d'authentification.

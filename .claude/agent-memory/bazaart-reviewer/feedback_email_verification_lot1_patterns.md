---
name: feedback_email_verification_lot1_patterns
description: ADR-0015 Lot 1 — confirmation email via symfonycasts/verify-email-bundle (juin 2026). Patterns et anti-patterns identifiés.
metadata:
  type: feedback
---

Relecture complète du Lot 1 ADR-0015, 18 juin 2026. PHPStan niveau 6 : 0 erreur.

**1. CSRF register : CORRIGÉ (ne plus signaler)**
La mémoire [[feedback_twig_auth_and_community_patterns]] signalait l'absence de validation CSRF dans AuthController::register(). Ce bug est désormais corrigé : `isCsrfTokenValid('registration', ...)` est appelé avant `RegisterDTO::fromArray()`.

**2. getRoles() dans GoogleAuthenticator::onAuthenticationSuccess() (AVERTISSEMENT récurrent)**
`in_array('ROLE_ADMIN', $user->getRoles(), true)` à la ligne 122. Anti-pattern connu [[feedback_getRoles_hierarchy]] : ne prend pas en compte la role_hierarchy Symfony. En pratique, l'admin a `ROLE_ADMIN` explicitement en BDD donc ça fonctionne, mais c'est fragile si la hiérarchie évolue.
**Why:** Même anti-pattern que dans les voters — getRoles() retourne les rôles bruts, pas les rôles déduits.
**How to apply:** Signaler en Avertissement. Ne pas corriger en Critique car l'impact est limité ici (seulement la redirection post-login, pas un blocage de sécurité).

**3. UserChecker::checkPreAuth message avec URL en dur (MINEUR)**
Le message d'erreur "va sur /verifier-email/confirmation" contient une URL codée en dur (string). Si la route change, le message deviendrait incorrect. Pas de faille de sécurité.
**Why:** À mentionner comme Suggestion — générer l'URL via le Router serait mieux mais complexifie UserChecker (injection supplémentaire).

**4. Absence de configuration explicite symfonycasts_verify_email (INFORMATION)**
Le bundle SymfonyCastsVerifyEmailBundle est actif (bundles.php) mais sans fichier de config dédié dans config/packages/. La durée de validité du lien (1 heure) est celle par défaut du bundle. C'est documenté dans le service mais pas dans un fichier de config visible. Acceptable en V1 mais à documenter.

**5. Tests E2E : couverture partielle du nouveau flux (AVERTISSEMENT)**
RegistrationTest vérifie encore `assertResponseRedirects('/login')` à la ligne 102, alors que le nouveau flux redirige vers `/verifier-email/confirmation`. Le test passe silencieusement si l'assertion est fausse (bug de test lui-même). De plus, aucun scénario ne couvre :
- le blocage à la connexion d'un compte non vérifié (UserChecker)
- le clic sur le lien de vérification (verifyEmail route)
- le renvoi de l'email (resendVerificationEmail)
- le rate limiting du renvoi
**Why:** Ces cas sont critiques pour la sécurité du gating. Un test cassé silencieusement est un risque.

**6. resendVerificationEmail : rate limiting APRÈS validation CSRF mais AVANT lookup user (CORRECT)**
L'ordre est : CSRF → rate limit → lookup user. C'est le bon ordre : on consomme un token rate limit AVANT de chercher le user, ce qui évite de révéler si l'email existe en BDD (anti-énumération).

**7. Email texte brut (.txt.twig) : tiret cadratin présent ligne 1 et 15 (ÉDITORIAL)**
"BAZAART -- Confirme" = double tiret (--), pas un tiret cadratin (—). Conforme à la préférence de Gaëlle (pas de tiret cadratin). OK.
Ligne 15 et 22 : `---` = séparateur Markdown-style, pas un tiret cadratin. Aussi OK.

**8. Session pending_verification_email : cast mixed non typé (MINEUR confirmé)**
`$request->getSession()->get('pending_verification_email')` retourne mixed. Le contrôleur fait une vérification `=== null` mais ne cast pas en string avant utilisation. En pratique sûr (on y met toujours une string), mais PHPStan pourrait le signaler à niveau 7+. Confirmé comme mineur.

**9. verifyEmail : re-remplissage session sur erreur de signature (CORRECT)**
Quand la signature est invalide, le contrôleur remet `pending_verification_email` en session et redirige vers check_email. Comportement UX correct : l'utilisateur peut renvoyer l'email depuis la même page.

**10. Absence de log du renvoi refusé (rate limit dépassé) (SUGGESTION)**
Quand le rate limit du renvoi est dépassé, on redirige avec un flash d'erreur mais on ne logge pas. Un attaquant qui spamme le renvoi ne laisse aucune trace serveur.

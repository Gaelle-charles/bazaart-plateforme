---
name: feedback_email_verification_lot1_patterns
description: ADR-0015 Lot 1+Lot 1b — confirmation email + connexion auto (juin 2026). Patterns et anti-patterns identifiés.
metadata:
  type: feedback
---

Relecture complète du Lot 1 ADR-0015, 18 juin 2026. PHPStan niveau 6 : 0 erreur.

**1. CSRF register : CORRIGÉ (ne plus signaler)**
La mémoire [[feedback_twig_auth_and_community_patterns]] signalait l'absence de validation CSRF dans AuthController::register(). Ce bug est désormais corrigé : `isCsrfTokenValid('registration', ...)` est appelé avant `RegisterDTO::fromArray()`.

**2. getRoles() dans GoogleAuthenticator::onAuthenticationSuccess() : CORRIGÉ (ne plus signaler)**
Depuis la relecture Lot 1b, GoogleAuthenticator utilise AuthorizationCheckerInterface::isGranted() et non getRoles() brut.
Voir [[feedback_getRoles_hierarchy]]. Anti-pattern résolu dans ce fichier.

**3. UserChecker::checkPreAuth message avec URL en dur (MINEUR)**
Le message d'erreur "va sur /verifier-email/confirmation" contient une URL codée en dur (string). Si la route change, le message deviendrait incorrect. Pas de faille de sécurité.
**Why:** À mentionner comme Suggestion — générer l'URL via le Router serait mieux mais complexifie UserChecker (injection supplémentaire).

**4. Configuration bundle symfonycasts_verify_email : CORRIGÉ (ne plus signaler)**
Un fichier dédié config/packages/symfonycasts_verify_email.yaml existe avec lifetime: 3600 explicitement documenté.

**5. Tests E2E : couverture partielle du nouveau flux (AVERTISSEMENT)**
RegistrationTest vérifie maintenant `assertResponseRedirects('/verifier-email/confirmation')` (ligne 110, corrigé). Points encore non couverts :
- Le flux complet verifyEmail() (clic sur le lien, connexion automatique)
- resendVerificationEmail() (renvoi)
- Le rate limiting du renvoi
**Why:** Ces cas sont critiques pour la sécurité du gating. Acceptable en V1 (couverture cible 30%), à couvrir en V2.

**6. resendVerificationEmail : rate limiting AVANT lookup user (CORRECT)**
L'ordre est : CSRF → rate limit → lookup user. Bon ordre : anti-énumération.

**7. Connexion automatique Security::login() : ordre des opérations CORRECT**
Dans verifyEmail() : handleVerification() (flush isVerified=true) AVANT Security::login().
UserChecker::checkPreAuth() lit isVerified depuis la BDD — l'ordre est critique et correct.
Security::login() n'est jamais appelé si VerifyEmailExceptionInterface est levée.

**8. Paramètre id dans l'URL : INCLUS dans la signature (CORRECT, CONFIRMÉ)**
generateSignature() passe ['id' => (string) $user->getId()] comme $extraParams.
Dans VerifyEmailHelper::generateSignature() : les extraParams sont ajoutés à l'URI AVANT que uriSigner->sign() signe l'URL entière. La signature couvre donc le paramètre ?id=.
Un attaquant qui modifie ?id= dans l'URL casse la signature UriSigner → InvalidSignatureException levée. Protection confirmée.

**9. IDOR sur chargement par ?id= : ACCEPTABLE (protégé par HMAC)**
Le contrôleur charge le User par ?id= avant de valider la signature. Ce chargement préalable est nécessaire pour avoir l'email ($user->getEmail()) à passer à validateEmailConfirmation().
Pas d'IDOR exploitable : si l'id est modifié → signature invalide → pas de connexion. Pas de fuite d'info : même message d'erreur flash, même redirection, qu'un id existe ou non (le UserChecker et le flash d'erreur ne discriminent pas).

**10. Cas double-clic (user déjà vérifié) : CORRECT**
verifyEmail() vérifie d'abord if ($user->isVerified()) AVANT d'appeler handleVerification().
Si connecté → redirect dashboard. Si non connecté → redirect login avec flash 'success'. Pas d'erreur 500.

**11. Cas id absent ou user inexistant : CORRECT**
id null → flash 'error' + redirect /register. User null → flash 'error' + redirect /register. Comportement gracieux confirmé.

**12. Choix authenticator 'security.authenticator.form_login.main' : CORRECT**
Le firewall 'main' déclare deux authenticators (form_login + GoogleAuthenticator). Security::login() avec authenticatorName=null lèverait une LogicException. form_login est le bon choix sémantique. GoogleAuthenticator serait incorrect (pas de token OAuth ici).

**13. validateEmailConfirmation() est DÉPRÉCIÉE depuis v1.17.0 (AVERTISSEMENT)**
EmailVerificationService::handleVerification() appelle $this->verifyEmailHelper->validateEmailConfirmation($request->getUri(), ...) qui déclenche @trigger_deprecation() dans VerifyEmailHelper.php (ligne 75).
La méthode non-dépréciée est validateEmailConfirmationFromRequest(Request $request, string $userId, string $userEmail).
**Why:** PHPStan niveau 6 ne détecte pas les dépréciations trigger_deprecation() — il faudrait PHPStan niveau 8+ ou l'extension phpstan-deprecation-rules. Ce point peut passer inaperçu.
**How to apply:** Signaler en Avertissement. Correction simple : remplacer l'appel dans handleVerification().

**14. Rate limiting renvoi : log warning présent (CORRECT)**
Logger::warning() est appelé quand le rate limit est dépassé. Point résiduel de la relecture précédente résolu.

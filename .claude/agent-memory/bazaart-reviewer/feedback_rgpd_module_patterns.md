---
name: feedback_rgpd_module_patterns
description: Patterns et anti-patterns identifiés lors de la relecture du module RGPD V1 (2026-05-25) — anonymisation, export, bannière cookies, rate limiting
metadata:
  type: feedback
---

Relecture du module RGPD V1 (RgpdController, RgpdService, LegalController, User.anonymizedAt, AuthController rate-limit, base.html.twig bannière, templates legal/*, migration Version20260525223758) effectuée le 2026-05-25.

**Points propres à retenir (ne pas signaler en fausse alerte) :**
- CSRF correctement vérifié sur POST app_rgpd_delete avant tout appel de service.
- TokenStorage::setToken(null) + Session::invalidate() : séquence de déconnexion correcte après anonymisation.
- Format email anonymisé `anonymise_{id}@bazaart-deleted.fr` : unicité garantie par l'id (pas de conflit possible avec un vrai compte car le domaine est factice et non enregistré).
- login_throttling dans security.yaml n'a pas besoin de `limiter_name: login_limiter` : Symfony génère automatiquement un limiter interne si seuls max_attempts/interval sont fournis — les deux configs (security.yaml + framework.yaml login_limiter) sont indépendantes et non redondantes.
- `#[IsGranted('ROLE_USER')]` sur la classe RgpdController — couvre toutes les routes sans répétition.
- Pas de `|raw` sur données utilisateur dans les templates RGPD/légaux — pas de XSS.
- Routes légales en PUBLIC_ACCESS dans security.yaml — conforme obligation LCEN.
- cookieConsent() exposée sur `window` dans base.html.twig : nécessaire pour les onclick HTML — acceptable.
- download="" sur le lien app_rgpd_export : doublonne Content-Disposition mais sans effet négatif.

**Anti-patterns trouvés dans ce module (relecture initiale 2026-05-25) :**

1. CRITIQUE [CORRIGÉ] — getId() nullable sur setEmail() au moment de l'anonymisation :
   Corrigé par `$userId = $user->getId() ?? throw new \LogicException('...')` dans anonymizeUser().

2. CRITIQUE [CORRIGÉ] — Compte anonymisé reconnectable via Google OAuth :
   Corrigé par la création de UserChecker (checkPreAuth() bloque si isAnonymized()). UserChecker déclaré dans security.yaml `user_checker: App\Security\UserChecker`. GoogleAuthenticator ne vérifie toujours pas isAnonymized() directement, mais il n'est plus nécessaire car UserChecker s'en charge AVANT que le Passport soit validé.

3. AVERTISSEMENT [CORRIGÉ] — ArtistProfileRepository injecté mais non utilisé :
   Supprimé du constructeur de RgpdService. Commentaire explicatif ajouté.

4. AVERTISSEMENT [CORRIGÉ] — Inscriptions aux formations absentes de l'export RGPD :
   CourseEnrollmentRepository et LessonProgressRepository injectés dans RgpdService. Deux sections ajoutées : course_enrollments et lesson_progress. findByUserWithCourse() et findByEnrollmentWithLesson() utilisés (FETCH JOIN, pas de N+1).

5. AVERTISSEMENT [CORRIGÉ] — Cookie cookie_consent sans attribut Secure en production :
   `; Secure` ajouté inconditionnellement dans la fonction JS cookieConsent() de base.html.twig.

6. AVERTISSEMENT [CORRIGÉ] — login_limiter mort dans framework.yaml :
   Supprimé. Seul register_limiter subsiste. Commentaire explicite ajouté.

7. AVERTISSEMENT [CORRIGÉ] — IP serveur de production en clair dans privacy.html.twig :
   IP 206.189.3.112 supprimée. Remplacée par "région Frankfurt (Allemagne), conformément au RGPD".

**Nouveaux points détectés lors de la relecture des corrections (2026-05-25) :**

A. AVERTISSEMENT — Commentaire obsolète dans security.yaml (ligne 65) :
   `# login_throttling utilise le rate limiter "login_limiter" défini dans framework.yaml`
   Ce commentaire est désormais inexact : login_limiter a été supprimé. Symfony Security génère son propre limiter interne. Le commentaire doit être mis à jour.

B. AVERTISSEMENT — trusted_proxies: 'REMOTE_ADDR' trop permissif :
   REMOTE_ADDR fait confiance à l'IP de tout proxy direct, quelle qu'elle soit. En production sur un droplet DigitalOcean sans load balancer dédié, c'est acceptable. En cas d'ajout d'un load balancer, un attaquant sur le même réseau pourrait forger X-Forwarded-For. À remplacer par l'IP fixe du proxy si la topologie change.

C. AVERTISSEMENT — trusted_headers inclut x-forwarded-host :
   L'en-tête X-Forwarded-Host permet à un proxy de définir le nom d'hôte perçu par Symfony. En production derrière Nginx, cet en-tête n'est pas émis — le laisser dans trusted_headers est inoffensif mais peut créer une surface d'attaque (Host header injection) si un proxy intermédiaire mal configuré l'ajoute. Recommandation : retirer x-forwarded-host si Nginx pose déjà Host correctement.

D. AVERTISSEMENT — Double appel findByUserWithCourse() dans exportUserData() :
   La méthode est appelée deux fois de suite (lignes 189 et 207) avec le même argument. La seconde boucle (lessonProgressData) exécute une requête SQL identique à la première. Corriger en stockant le résultat dans une variable : `$enrollments = $this->courseEnrollmentRepository->findByUserWithCourse($user);` puis itérer dessus deux fois.

E. SUGGESTION — Secure cookie en dev rompt le consentement sur HTTP :
   `; Secure` est désormais posé inconditionnellement. Sur l'environnement de dev (HTTP, port 8080), les navigateurs ignorent ce cookie (attribut Secure sur HTTP = cookie non enregistré). La bannière réapparaîtra à chaque rechargement en dev. Envisager : conditionner '; Secure' à `location.protocol === 'https:'` ou utiliser une variable Twig `{{ app.debug ? '' : '; Secure' }}`.

8. SUGGESTION — flash message perdu après session::invalidate() :
   Le commentaire dans RgpdController l'admet : le flash posé après invalidate() ne survivrait pas. Actuellement aucun flash n'est posé — mais l'UX laisse l'utilisateur arriver sur la home sans aucun message de confirmation. Envisager : stocker un paramètre dans la session de la réponse suivante (flashBag sur une nouvelle session) ou rediriger vers une page statique `/compte-supprime`.

9. SUGGESTION — OrganizationProfile non anonymisé :
   `anonymizeUser()` ne touche pas OrganizationProfile lié au compte. Si l'utilisateur avait un profil organisation avec des données personnelles (nom de contact, etc.), celles-ci restent en BDD après anonymisation. Décision de scope V1 documentée dans le commentaire — acceptable si consciemment choisi, mais à signaler.

**Why:** Ces points sont à surveiller dans tout nouveau module traitant de données personnelles.
**How to apply:** Toujours vérifier que `isAnonymized()` est contrôlé dans tous les authenticators. Pour l'export RGPD, mettre à jour après chaque nouveau module ajoutant des données utilisateur. Ne jamais exposer l'IP de production dans des pages publiques.

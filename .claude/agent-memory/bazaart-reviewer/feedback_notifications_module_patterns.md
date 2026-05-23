---
name: feedback_notifications_module_patterns
description: Patterns et anti-patterns identifiés lors de la relecture du module Notifications (2026-05-23)
metadata:
  type: feedback
---

Relecture du module Notifications (NotificationType, Notification, NotificationRepository, NotificationService, NotificationExtension, NotificationController, ApiNotificationController, template index, migration, wiring MessagingService + ForumService, base_app.html.twig) effectuée le 2026-05-23.

**Points propres à retenir (ne pas signaler en fausse alerte) :**
- Ordre CSRF avant logique métier respecté dans les deux routes POST (`markAllRead`, `markRead`) — leçons Forum et Messagerie correctement appliquées.
- Anti-auto-notification via comparaison `$sender->getId() === $recipient->getId()` (pas identité d'objet) — robuste entre contextes Doctrine différents.
- `ApiNotificationController` séparé de `NotificationController` : justification correcte (préfixe de classe `/notifications` incompatible avec `/api/notifications/...`).
- `markAllAsReadForUser()` : UPDATE DQL groupé en une seule requête — pas de N+1.
- `#[IsGranted('ROLE_USER')]` au niveau de la classe sur `NotificationController` — toutes les routes protégées sans répétition.
- Index composite `(recipient_id, is_read)` en BDD — optimise les deux requêtes les plus fréquentes.
- `NotificationExtension` hérite d'`AbstractExtension` — enregistrement automatique via `autoconfigure: true`, pas besoin de déclaration manuelle.
- Badge SSR avec fallback si JS désactivé (valeur `unread_notifications_count()` rendue côté serveur).

**Anti-patterns trouvés dans ce module :**
1. IDOR masqué silencieusement : `NotificationService::markAsRead()` retourne `void` et fait `return;` si l'appartenance échoue — le controller répond `{"success": true}` dans tous les cas. Corriger : retourner `bool`, le controller répond 403 si `false`.
2. XSS préventif : `{% set notifText = ... ~ notification.data.threadTitle ~ ... %}` sans `|e` sur `threadTitle` et `senderEmail`. Twig échappe à l'affichage final mais un futur `|raw` serait dangereux. Ajouter `|e` dans les `{% set %}`.
3. PII en BDD : les champs `senderEmail` et `replyAuthorEmail` stockent l'email complet en JSON. Stocker `senderName` (partie locale de l'email) pour réduire l'exposition RGPD.
4. N+1 sur l'extension Twig : `unread_notifications_count()` fait un SELECT COUNT(*) à chaque appel sans mémoïsation. Ajouter `private ?int $cachedCount = null;` dans `NotificationExtension`.
5. `Notification::getLink()` contient des URLs en dur sans Router Symfony — méthode non utilisée (le template Twig surpasse avec `path()`). Marquer `@deprecated` ou supprimer.
6. `getDescription()` de la migration mentionne les tables messagerie qui ne sont pas créées dans ce fichier — description trompeuse.
7. Pas de poll JS immédiat : `setInterval(poll, 60000)` sans appel initial — le badge peut rester désynchronisé 60 secondes si une notif arrive juste après le chargement. Ajouter `poll()` avant `setInterval`.
8. Union type `ForumThread|string` et `Message|string` : anti-pattern déjà signalé (voir [[feedback_apply_union_type_antipattern]]) — présent dans ForumService et MessagingService wirés dans ce module.

**Why:** Ces points sont à surveiller dans tout nouveau module de la plateforme.
**How to apply:** Pour les routes POST AJAX qui retournent JSON, s'assurer que le service retourne un type permettant de distinguer succès et échec. Toujours mémoïser les appels SQL dans les extensions Twig appelées sur chaque page. Ne pas stocker d'emails complets dans des champs JSON de notifications (PII RGPD).

---
name: feedback_notifications_module_patterns
description: Patterns et anti-patterns identifiés lors des relectures du module Notifications (2026-05-23, 2026-05-25, 2026-05-26)
metadata:
  type: feedback
---

Relecture initiale le 2026-05-23. Relecture de suivi le 2026-05-25. Relecture câblage in-app + corrections le 2026-05-26.

**Points propres à retenir (ne pas signaler en fausse alerte) :**
- Ordre CSRF avant logique métier respecté dans les deux routes POST (`markAllRead`, `markRead`) — leçons Forum et Messagerie correctement appliquées.
- Anti-auto-notification via comparaison `$sender->getId() === $recipient->getId()` (pas identité d'objet) — robuste entre contextes Doctrine différents.
- `ApiNotificationController` séparé de `NotificationController` : justification correcte (préfixe de classe `/notifications` incompatible avec `/api/notifications/...`).
- `markAllAsReadForUser()` : UPDATE DQL groupé en une seule requête — pas de N+1.
- `#[IsGranted('ROLE_USER')]` au niveau de la classe sur `NotificationController` — toutes les routes protégées sans répétition.
- Index composite `(recipient_id, is_read)` en BDD — optimise les deux requêtes les plus fréquentes.
- `NotificationExtension` hérite d'`AbstractExtension` — enregistrement automatique via `autoconfigure: true`, pas besoin de déclaration manuelle.
- Badge SSR avec fallback si JS désactivé : `unread_notifications_count()` rendu côté serveur dans `sidebar_artiste.html.twig`.
- IDOR corrigé : `markAsRead()` retourne `bool`, le controller répond 403 si `false`.
- Double-escape XSS corrigé.
- PII partiellement corrigé : partie locale de l'email uniquement dans les notifications.
- `NotificationExtension` dispose d'un `private ?int $cachedCount = null` — mémoïsation présente.
- Polling JS dans `base_dashboard.html.twig` : `poll()` appelé immédiatement + `setInterval(poll, 60000)` + URL via `data-api` — conforme aux exigences.
- `read_at` colonne ajoutée : entité + migration `Version20260526004011.php` cohérents.
- `markAsRead()` sur l'entité : n'écrase pas `readAt` si déjà renseignée (protection première lecture).
- `markAllAsReadForUser()` filtre `n.isRead = false` → n'écrase pas `readAt` des notifs déjà lues.
- `NotificationService::markAllAsRead()` appelle `em->clear(Notification::class)` après le DQL UPDATE — corrige la désynchronisation Unit of Work signalée lors de la relecture précédente.
- `rejectResource()` envoie une notification `ResourceValidated` avec `data['status'] = 'refusée'` — cas rejet couvert.
- `getLink()` correctement marqué `@deprecated` avec note de suppression V2.
- `ResourceAlertRepository::findAllActive()` ne contient plus de CASE WHEN dans orderBy() — le tri est délégué au PHP (usort), conformément au retour de relecture précédente [[feedback_dql_case_orderby]].
- `#notif-count` présent dans le DOM même quand count = 0 (display: none) — JS peut le cibler sans créer l'élément.
- `data-api` sur `#notif-link` — URL non codée en dur dans le JS.
- Route `app_api_notification_unread_count` confirmée dans `ApiNotificationController.php` ligne 49.
- `getSubmittedBy()` sur `Resource` est non-nullable → pas de risque de null pointer dans `publishResource()` et `rejectResource()`.
- `base_dashboard.html.twig` rend les flash `success`, `error` ET `info` — corrige l'anti-pattern `base_admin` signalé dans [[feedback_flash_info_invisible_admin]].

**Anti-patterns résiduels après la relecture 2026-05-26 :**

1. **N+1 flush NewLive** : `NotificationService::create()` appelle `$this->em->flush()` à l'intérieur de chaque itération. La boucle `$allUsers` dans `LiveService::createLive()` déclenche donc N INSERT + N flush individuels (un par utilisateur). Même en V1 à faible volumétrie, c'est O(N) transactions. Une méthode `createBatch()` sans flush individuel, avec un seul flush après la boucle, serait préférable. Le commentaire dans `NotificationService::create()` l'évoque mais aucune méthode `createBatch()` n'existe.

2. **Pas d'exclusion hôte dans NewLive** : `NotificationService::create()` n'est pas appelé avec `$sender` pour la boucle NewLive (car le commentaire justifie "tout le monde doit être notifié"). L'hôte du live (`$host`) reçoit donc une notification "Nouveau live" pour le live qu'il anime lui-même. Comportement discutable : à documenter comme décision explicite ou exclure l'hôte.

3. **Pas d'exclusion du soumetteur dans ResourceMatch** : la boucle ResourceMatch (ligne 158 de `AdminController`) n'exclut pas `$resource->getSubmittedBy()`. Si la structure qui a soumis la ressource a elle-même configuré une alerte correspondante, elle reçoit une notification ResourceMatch en plus de sa notification ResourceValidated. Doublon notable.

4. **`set('n.isRead', 'true')` en DQL** : passer la string `'true'` (pas un paramètre `:value`) est une syntaxe DQL acceptable pour PostgreSQL, mais fragile selon la version Doctrine/driver. La forme canonique est `->set('n.isRead', ':isRead')->setParameter('isRead', true)`. Risque faible mais à corriger pour la robustesse.

5. **`relatedEntityType` toujours une string libre** : aucun enum PHP ne valide les valeurs `'conversation' | 'forum_thread' | 'resource' | 'live'`. Risque de faute de frappe silencieuse.

6. **`Mention`** déclaré dans l'enum mais aucune logique de détection de mention `@user` — toujours non câblé.

7. **Préférences de notification** (in-app / email / désactivé) absentes — spécifiées dans CDC V3 ligne 562, non implémentées.

8. **Emails transactionnels** absents pour notifications in-app — CDC V3 ligne 561, non implémentés.

**Why:** Ces points sont à surveiller dans tout nouveau module de la plateforme.
**How to apply:**
- Pour les boucles de création de notifications groupées, toujours vérifier si `create()` flushe en interne et si cela engendre N transactions.
- Pour les notifications ResourceMatch, toujours vérifier l'exclusion du soumetteur.
- Pour les SET DQL sur booléens, préférer les paramètres bindés `:value`.

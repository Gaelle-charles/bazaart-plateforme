---
name: feedback_live_module_patterns
description: Module Lives planifiés V1 (mai 2026) — patterns et anti-patterns identifiés lors de la relecture
metadata:
  type: feedback
---

# Module Lives planifiés — patterns et anti-patterns (relecture 25 mai 2026)

## Points bien faits (ne pas signaler en fausse alerte)
- `#[IsGranted('ROLE_ADMIN')]` sur la classe `AdminLiveController` : couvre toutes les routes.
- CSRF présent sur toutes les actions POST : attend, unattend, cancel, delete, new, edit.
- Token CSRF nommé avec l'ID du live (`live_attend_{id}`) : bonne pratique, évite les collisions.
- `LiveVoter` utilise `$this->security->isGranted()` (pas `getRoles()`), respecte la hiérarchie.
- Anti-doublon inscription : vérification PHP dans `registerAttendee()` + contrainte UNIQUE SQL.
- `reminderSent` flag correctement mis à `true` après envoi, flush par lot (1 flush par live).
- `unregisterAttendee()` idempotente (pas d'exception si non inscrit).
- `cancelLive()` charge les attendees AVANT le flush — correctement ordonné.
- Emails : tables HTML inline CSS, `url()` (absolu) et non `path()`.
- `rel="noopener noreferrer"` présent sur tous les liens `target="_blank"`.
- Migration propre : contrainte unique `uq_live_attendee(live_id, user_id)`, indexes FK, down() cohérent.
- Templates étendent bien `base_dashboard.html.twig` (public) et `base_admin.html.twig` (admin).
- Flash 'info', 'success', 'error' tous rendus dans `base_dashboard.html.twig` et `base_admin.html.twig`.

## Bugs / avertissements à retenir

### Critique
- **Commentaire trompeur dans `cancelLive()`** : le commentaire dit "on envoie les emails AVANT de flusher" mais le code fait `flush()` AVANT la boucle d'envoi. L'intention et le code sont inversés dans le commentaire — à corriger pour éviter confusion lors d'une future modification.

### Avertissement
- **N+1 sur `live.attendees|length`** dans 4 templates (index.html.twig, show.html.twig, admin/index, admin/form) : la relation `attendees` est lazy-loaded, chaque accès déclenche une requête. Les repositories chargent l'hôte en JOIN FETCH mais pas les attendees. Solution : ajouter un champ calculé `attendeesCount` ou JOIN FETCH attendees.
- **`replayUrl` et `coverImageUrl` non validées côté PHP** dans `AdminLiveController` : `filter_var(FILTER_VALIDATE_URL)` n'est appliqué qu'à `externalUrl`. Les deux autres URLs optionnelles passent directement dans `$data[]` sans validation. Les contraintes Assert\Url dans l'entité ne sont pas invoquées (pas de Validator injecté dans le controller).
- **`live.description|slice(0,200)` sans `|e`** dans `live_reminder.html.twig` ligne 118 : le chemin long (description > 200 chars) affiche le slice sans filtre d'échappement HTML. Le chemin court utilise `|e` correctement. Risque faible en contexte email admin-only mais incohérent.
- **Pas de lien de désinscription** dans l'email de rappel : l'utilisateur inscrit peut recevoir plusieurs rappels sans moyen de se désinscrire directement depuis l'email. Constitue un manque RGPD (droit de retrait facilité).
- **`findUpcoming()` filtre `scheduledAt > :now`** avec statut SCHEDULED uniquement : un live passé en statut LIVE manuellement par l'admin n'apparaîtra plus dans le calendrier public. Cohérence fonctionnelle à vérifier.
- **FK `host_user_id` sans `ON DELETE`** dans la migration : si un utilisateur-hôte est supprimé, la contrainte FK bloquera (no action par défaut PostgreSQL). Décision métier à documenter, ou ajouter `ON DELETE RESTRICT` explicite.
- **Email `From:` absent** dans `LiveService::sendReminderEmail()` et `sendCancellationEmail()` : l'objet `Email` n'a pas de `->from()`. Symfony Mailer utilisera l'enveloppe globale si configurée, mais `mailer.yaml` ne définit pas de `envelope`. En l'état, certains mailers peuvent rejeter ou utiliser un domaine par défaut non souhaité.

### Suggestion
- `LIVE_VIEW` appelé avec `null` comme sujet dans `LiveController::show()` alors que la route reçoit un objet `Live` via paramconverter — passer le `$live` en sujet permettrait au voter de conditionner la visibilité par statut (ex : masquer un live CANCELLED aux non-admins).
- `userRepository->findBy([], ['email' => 'ASC'])` charge **tous** les utilisateurs pour le sélecteur d'hôte. Sur une base de 1000+ users, c'est un problème de performance. Filtrer sur `ROLE_ADMIN` ou `ROLE_ARTIST` en V1.
- Le dry-run de `SendLiveRemindersCommand` n'affiche pas la liste des destinataires qui auraient reçu un rappel — mentionné comme "V2" mais facilement implementable en V1.
- Commentaire `/* border-radius: 0 — Street */` absent sur plusieurs blocs CSS (présent dans index, absent dans show).

**Why:** Pattern récurrent : les URLs optionnelles dans les formulaires admin ne sont pas validées côté PHP, seul `filter_var` est utilisé pour le champ obligatoire.
**How to apply:** Toujours signaler comme avertissement lorsqu'une URL optionnelle (replayUrl, coverImageUrl, videoUrl) passe sans `filter_var(FILTER_VALIDATE_URL)` dans un controller qui n'injecte pas le Validator Symfony.

---
name: feedback_messaging_module_patterns
description: Patterns et anti-patterns identifiés lors de la relecture du module Messagerie privée (2026-05-23)
metadata:
  type: feedback
---

Relecture du module Messagerie (Conversation, ConversationParticipant, Message, ConversationRepository, MessageRepository, MessagingService, MessagingController, MessagingVoter, templates) effectuée le 2026-05-23.

**Points propres à retenir (ne pas signaler en fausse alerte) :**
- `MessagingVoter` utilise correctement `$this->security->isGranted()` pour `canInitiate()`.
- Ordre CSRF avant `denyAccessUnlessGranted` respecté dans `new()` (POST) et `send()` — leçon Forum correctement appliquée.
- `countUnreadForParticipant()` exclut bien les messages de l'auteur lui-même.
- `markAsRead()` est appelé dans `show()` à chaque ouverture, pas seulement lors de l'envoi.
- La route `/messages/new/{userId}` est déclarée avant `/{id}` — conflit de routing évité.
- `findBetweenUsers()` est appelé dans `initiateConversation()` — unicité garantie.
- Le pipe `|escape|nl2br|raw` dans `show.html.twig` est sûr (escape s'exécute en premier dans le pipeline Twig).
- `countAllUnreadForUser()` est du dead code en V1 mais prévu pour notifications — acceptable.
- `getOtherParticipant()` : si la conv a >2 participants, retourne le PREMIER non-courant trouvé (pas d'erreur, mais potentiel comportement inattendu en cas de données corrompues — documenté).

**Anti-patterns trouvés dans ce module (état au 23/05/2026) :**

**CORRIGÉS entre les deux relectures (23/05 → 25/05/2026) :**
1. N+1 dans `index()` : résolu — `countUnreadGroupedByConversation()` remplace la boucle par conversation.
2. Redirect sans ancre `#last-message` dans `send()` : résolu — `$this->redirect(generateUrl() . '#last-message')`.
3. `removeParticipant()` bug copier-coller setConversation($this) : résolu — le code actuel ne touche plus setConversation.
4. `countAllUnreadForUser()` DQL brut andWhere() : résolu — utilisation de `$qb->expr()->orX()`.
5. NULLS LAST manquant dans `findByUser()` : résolu — COALESCE('1970-01-01') remplace le tri DESC nu.

**NOUVEAUX problèmes détectés en relecture du 25/05/2026 :**
1. `path('app_messaging_new')` sans `{userId}` dans `index.html.twig` ligne 294 : la route `/messages/new/{userId}` requiert `userId` — Symfony lève `MissingMandatoryParametersException` au rendu (page /messages inutilisable). Il faut pointer vers l'annuaire artistes.
2. `new.html.twig` utilise les anciens tokens CSS (`--color-*`, `--font-heading`, `--radius-*`, `--shadow-*`) non définis dans design-tokens.css — mise en page cassée en thème Street.
3. `is_archived` (ConversationParticipant) et route `POST /messages/{id}/archive` prévus dans le CDC section 5.4 sont absents de l'implémentation.
4. CDC section 5.4 prévoit `is_read` dans l'entité `Message` et `sent_at` (au lieu de `createdAt`) — l'implémentation utilise `lastReadAt` dans `ConversationParticipant` (approche différente, plus efficace, mais diverge du CDC).
5. `countUnreadForParticipant()` dans `MessageRepository` est du dead code depuis l'introduction de `countUnreadGroupedByConversation()` — à nettoyer.
6. Le bloc de documentation de `show.html.twig` ligne 10 mentionne `sendForm : FormView` qui n'est jamais passé par le controller — commentaire obsolète.

**Why:** Ces points sont à surveiller dans tout nouveau module de la plateforme.
**How to apply:** Vérifier systématiquement les boucles N+1 sur les compteurs, les redirections avec ancres, et les expressions DQL brutes.

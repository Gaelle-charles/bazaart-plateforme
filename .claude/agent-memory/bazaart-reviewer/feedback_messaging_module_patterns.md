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

**Anti-patterns trouvés dans ce module :**
1. N+1 dans `MessagingController::index()` : une requête SQL par conversation pour `countUnreadForParticipant()`. Avec 50 conversations, 50 requêtes COUNT. La solution propre : une seule requête dans `MessageRepository::countAllUnreadForUser()` (déjà existante mais non utilisée).
2. `send()` dans le controller ne redirige pas avec ancre `#last-message` : `redirectToRoute('app_messaging_show', ['id' => $id], Response::HTTP_SEE_OTHER)` sans `#last-message` dans l'URL — le scroll JS compense mais le comportement n'est pas garanti après redirect.
3. `removeParticipant()` dans `Conversation` : le `setConversation($this)` après `removeElement()` est une erreur de copier-coller — devrait être `setConversation(null)` ou être supprimé (orphanRemoval s'en charge).
4. `countAllUnreadForUser()` dans `MessageRepository` : la clause `'m.createdAt > cp.lastReadAt OR cp.lastReadAt IS NULL'` est une expression DQL brute dans `andWhere()` — syntaxe fragile (même famille que le CASE WHEN signalé dans ResourceAlertRepository).
5. `findByUser()` dans `ConversationRepository` : NULLS LAST pour `lastMessageAt DESC` n'est pas dans le DQL (PostgreSQL l'implémente différemment de MySQL). Le tri DESC sans NULLS LAST place les NULL en tête sur PostgreSQL — conversations sans messages apparaissent avant les actives. Le commentaire dit "NULLS LAST" mais le code ne l'implémente pas.

**Why:** Ces points sont à surveiller dans tout nouveau module de la plateforme.
**How to apply:** Vérifier systématiquement les boucles N+1 sur les compteurs, les redirections avec ancres, et les expressions DQL brutes.

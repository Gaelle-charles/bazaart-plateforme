---
name: project-context
description: Contexte projet Bazaart — deadline V1 15 juin 2026, stack technique, état d'avancement
metadata:
  type: project
---

Plateforme bazaart.fr pour artistes de la diaspora afro-atlantique.

**Why:** Lancement officiel 15 juin 2026 (clôture incubation Mansa). Planning serré.

**Stack:** Symfony 7.4 / PHP 8.3 / PostgreSQL 16 / Redis / Docker / Twig + Stimulus + Tailwind.

**Code Symfony dans:** `/Users/belamour/bazaart/app/`

**État V1 (au 23 mai 2026) :**
- Ressourcerie : 75% fait (Resource, OrganizationProfile avec champs Structure, enums ResourceStatus/SubmitterRole)
- Communauté / Forum : TERMINÉ le 23 mai — entités ForumCategory/ForumThread/ForumReply, ForumService, ForumController (9 routes), ForumVoter mis à jour, 4 templates, commande seed, migration Version20260523131534
- Communauté / Messagerie : TERMINÉ le 23 mai — entités Conversation/ConversationParticipant/Message, ConversationRepository/MessageRepository, MessagingVoter, MessagingService, MessagingController (4 routes), 3 templates, migration Version20260523133318 (à appliquer)
- Communauté : reste notifications, lives
- Formation : 0%

**Entités existantes importantes :**
- `User` — rôles JSON, pas de `updatedAt`, bcrypt
- `OrganizationProfile` — a déjà `isStructurePartner`, `structureActivatedAt`, `structureActivationValidatedBy`, `updatedAt`
- `Resource` — a déjà `submitterRole`, `autoPublished`, `publishedAt`, `validatedAt`, `validatedBy`
- Enums : `ResourceStatus`, `SubmitterRole`, `ArticleStatus`, `ScrapedResourceStatus`

**Voters créés le 22 mai 2026 :**
- `ResourceVoter`, `ForumVoter`, `StructureVoter`, `LiveVoter` dans `src/Security/Voter/`

**How to apply:** Consulter `docs/cahier-des-charges-v3.md` avant toute tâche d'architecture. Priorité au fonctionnel V1.

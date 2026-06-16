# ADR-0012 — Proposition de formation par les membres

- **Date** : 2026-06-15
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Dans le CDC V3, le module Formation est **piloté par l'admin** : seule l'équipe Bazaart
crée les formations (catalogue, modules, leçons). Gaëlle a demandé que les **membres
puissent proposer une formation** depuis leur tableau de bord. C'est un **ajout au
périmètre V1**, arbitré par Gaëlle.

## Options envisagées

1. **Formulaire → email à l'équipe (léger, V0)** — pas de stockage, juste un email.
   - ✅ Rapide, sûr. ❌ Pas de suivi/traçabilité dans le back-office.
2. **Entité `CourseProposal` + revue admin (Option retenue)** — la proposition est
   stockée (statut en attente), l'admin l'accepte/refuse avec un motif, l'auteur est
   notifié.
   - ✅ Traçable, propre, suivi côté utilisateur et admin. ❌ Plus de code.
3. **Création complète d'une Course par l'utilisateur** — écartée : trop lourde et
   risquée (Course = modules + leçons + Stripe).

## Décision

**Option 2.** Nouvelle entité `CourseProposal` (auteur, titre, description, public visé,
expérience, niveau optionnel, lien portfolio, statut, note admin, traçabilité de revue).

- **Côté membre** (`ROLE_USER`) : bouton « Proposer une formation » dans le dashboard +
  formulaire `/formations/propositions/nouvelle` + widget « Mes formations » (suivi des
  statuts).
- **Côté admin** (`ROLE_ADMIN`) : écran `/admin/formations/propositions` (en attente +
  historique) avec accepter/refuser et un message optionnel.
- **Notifications par email** : l'équipe à la soumission, l'auteur à la décision.
  (Notification in-app possible en V2 — non faite pour ne pas modifier le sous-système
  de notifications juste avant le lancement.)

Une proposition acceptée n'est PAS une formation : l'admin crée ensuite la vraie `Course`
à partir des informations de la proposition.

## Conséquences

- **Code** : `CourseProposalStatus` (enum), `CourseProposal` (entité), `CourseProposalRepository`,
  `CourseProposalService` (création + emails + décision), `CourseProposalController` (membre),
  `AdminCourseProposalController` (revue), templates `course/proposal_new.html.twig` +
  `admin/course_proposals.html.twig`, intégration dashboard, lien nav admin, migration
  `Version20260616020224`.
- **Validation** : PHPStan niveau 6 OK, `doctrine:schema:validate` OK, `lint:twig` +
  `lint:container` OK, routage vérifié (pas de collision avec `/formations/{slug}` ni
  `/admin/formations/{id}`).
- **Écart au CDC V3** (module Formation admin-only) : à refléter dans le cahier des charges.
- **À surveiller** :
  - Adresse `admin@bazaart.fr` codée en dur dans `CourseProposalService` (comme
    `ForumService`) — à externaliser en paramètre.
  - Pas de notification in-app (email uniquement) en V1.
  - Évolution V2 : workflow « accepter → pré-remplir une nouvelle Course » + notif in-app.

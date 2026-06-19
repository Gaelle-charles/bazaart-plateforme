# ADR-0013 — Retrait de la proposition de formation par les membres (revient à l'admin-only)

- **Date** : 2026-06-18
- **Statut** : arbitré
- **Décidé par** : Gaëlle
- **Remplace** : ADR-0012

## Contexte

L'ADR-0012 (15 juin 2026) avait ouvert aux membres (`ROLE_USER`) la possibilité de
**proposer** une formation depuis leur tableau de bord (entité `CourseProposal` + revue
admin). C'était un **ajout au périmètre V1**, écart assumé au CDC V3 qui prévoit un module
Formation **piloté uniquement par l'admin**.

Gaëlle a décidé de **revenir sur cet ajout** : les membres ne doivent plus pouvoir proposer
de formation, ni voir aucune gestion de formation depuis leur dashboard. La gestion et la
publication des formations redeviennent **100 % admin** — ce qui **réaligne le projet sur le
CDC V3** (l'écart introduit par l'ADR-0012 disparaît).

Contrainte forte : la plateforme est **en production depuis le 15 juin 2026**. Toute
modification doit être **non destructive** et réversible.

## Options envisagées

Deux arbitrages sensibles, tranchés par Gaëlle :

1. **Écran admin de revue des propositions** (`/admin/formations/propositions`)
   - **Le garder (retenu)** — permet de traiter les propositions déjà reçues en prod ; lien
     encore présent dans la nav admin.
   - Le retirer — nav plus propre, mais on perd l'accès aux propositions existantes.

2. **Table `course_proposals` en base**
   - **Ne pas dropper (retenu)** — aucune migration destructive en prod juste après le
     lancement ; zéro perte de données ; 100 % réversible.
   - Dropper (migration `down`) — nettoyage complet, mais risqué et destructif.

## Décision

Retrait **uniquement des points d'entrée membre** de la fonctionnalité de proposition.
On **conserve** tout le back-office et les données :

**Retiré (côté membre)** :
- `CourseProposalController` (route `app_course_proposal_new`, `/formations/propositions/nouvelle`)
- Widget « Mes formations » + bouton « Proposer une formation » du dashboard
  (`templates/dashboard/index.html.twig` + logique `my_proposals` dans `DashboardController`)
- Les 2 boutons « Proposer un atelier » du catalogue (`templates/course/index.html.twig`)

**Conservé (intact)** :
- Écran admin de revue (`AdminCourseProposalController`, `admin/course_proposals.html.twig`,
  lien nav `base_admin.html.twig`)
- Entité `CourseProposal`, `CourseProposalRepository`, `CourseProposalStatus`,
  `CourseProposalService`
- Table `course_proposals` (aucune migration)

## Conséquences

- **Code** : suppression d'un controller + nettoyage `DashboardController` + retrait de blocs
  Twig (dashboard, catalogue). Aucune migration. Plus aucune référence à la route
  `app_course_proposal_new` ne doit subsister (sinon rendu Twig cassé).
- **Cahier des charges** : l'écart au CDC V3 introduit par l'ADR-0012 est **annulé** — le
  module Formation redevient admin-only, conforme au CDC. (Pas de modif CLAUDE.md de notre
  initiative ; signaler si une mise à jour du CDC est souhaitée.)
- **Réversibilité** : entité/table/service conservés → la fonctionnalité membre peut être
  réactivée plus tard sans migration.
- **À surveiller** :
  - Nettoyage profond possible à froid après lancement (suppression entité + table) si
    Gaëlle le décide un jour → futur ADR.
  - `CourseProposalService` conserve sa logique de création (emails) désormais non appelée
    côté membre ; seule la partie « décision » reste utilisée par l'écran admin.

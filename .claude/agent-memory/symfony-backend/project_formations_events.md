---
name: project_formations_events
description: Architecture et état d'avancement du sous-module formations-événements (ADR-0014 Option A) dans le module Formation de Bazaart
metadata:
  type: project
---

Phase 1 livrée (18 juin 2026) : data model + back-office admin + fixtures.
Phase 2 livrée (18 juin 2026) : flux d'inscription membre (gratuit + Stripe), email confirmation, dashboard « événements à venir ».
Phase 3 (future) : annulation + remboursement.

**Pourquoi:** Gaëlle voulait un 2e type de formation "événement" (visio/présentiel) réutilisant l'entité Course existante sans casser les formations contenu existantes.

**Architecture retenue (Option A ADR-0014) :**
- Enum `CourseType` : CONTENU ('content') / EVENEMENT ('event') — default 'content' en BDD
- Enum `CourseEventMode` : VISIO ('online') / PRESENTIEL ('in_person')
- 7 colonnes ajoutées à `courses` : type (NOT NULL), event_mode, event_start_at, event_end_at, event_location, event_external_url, capacity (tous nullable sauf type)
- Méthodes utilitaires sur Course : `isEvent(): bool`, `getSeatsRemaining(): ?int`
- `CourseEventValidationService` : service dédié à la validation des champs événement (hors controller)
- Méthode privée `hydrateEventFields()` dans AdminCourseController pour éviter la duplication new()/update()

**Migration :** `Version20260618154123` — type VARCHAR DEFAULT 'content' NOT NULL, migrations propres appliquées.

**Templates admin :** toggle JS (vanilla, pas Stimulus) pour afficher/masquer section événement selon le type sélectionné. Section modules/leçons conditionnée au type CONTENU.

**Fixtures :** slugs des événements créés :
- `masterclass-composition-afrobeats-en-ligne` (VISIO, 39€, 30 places, +30j)
- `atelier-scenique-presence-scene-paris` (PRESENTIEL, gratuit, 20 places, +45j)

**Corrections post-relecture (18 juin 2026) :**
- C1 : `base_admin.html.twig` corrigé — types flash `warning` et `danger` maintenant rendus (étaient silencieusement perdus)
- C2 : guard PHP dans `publish()` pour formations CONTENU — empêche publication sans module/leçon via requête HTTP directe
- A1 : règle 6 dans `CourseEventValidationService` — avertissement non bloquant si date de début passée
- A2 : `parseDatetimeLocal()` dans le controller — gère le format datetime-local avec secondes (fallback navigateur)

**Phase 2 — Architecture inscriptions événements :**
- `EventRegistrationService` : service central (anti-survente COUNT SQL, anti-doublon, email confirmation, formatage date FR)
- `CourseEnrollmentRepository::countActiveByCourse()` : COUNT SQL pour remplacer collection lazy-load (N+1 corrigé)
- `CourseEnrollmentRepository::findUpcomingEventsByUser()` : query pour dashboard événements à venir
- `CourseController::enroll()` : dispatch CONTENU vs EVENEMENT (gratuit → EventRegistrationService, payant → redirect /payer)
- `CoursePaymentController::pay()` : contrôle capacité AVANT checkout Stripe + redirect dashboard si déjà inscrit à un événement
- `StripeWebhookController::handleCoursePaymentCheckoutCompleted()` : re-contrôle capacité au webhook + email confirmation si événement
- `DashboardController` : ajoute `upcoming_events` via repository
- Templates :
  - `course/show.html.twig` : CTA adaptatif par type/tarif/capacité, pas de lien visio/adresse révélé avant inscription
  - `dashboard/index.html.twig` : widget « Mes événements à venir » avec lien visio direct (révélé UNIQUEMENT ici)
- **Règle clé ADR-0014 §4 :** lien visio/adresse révélés UNIQUEMENT dans le dashboard, jamais sur /formations/{slug}
- Enum backing values : VISIO='online', PRESENTIEL='in_person' (utiliser .value dans Twig, case name en PHP match)
- Pas de migration nécessaire (aucun champ ajouté à la BDD)
- Fixtures : artiste@bazaart.fr inscrit à l'événement présentiel pour prévisualiser le dashboard

**Corrections post-relecture Phase 2 (18 juin 2026) :**
- `EventRegistrationService::sendConfirmationEmail()` : `->from()` corrigé en `new Address('noreply@bazaart.fr', 'Bazaart')` pour afficher le nom de l'expéditeur dans les clients mail
- `EventRegistrationService::registerForEvent()` : catch silencieux remplacé par `$this->logger->warning()` avec contexte (course_id, user_email, error)
- Précédentes corrections : enum backing strings ('online'/'in_person'), route `app_course_show` (sans 's'), `|split(',')|last` pour n'exposer que ville/code postal sur la page publique

**Comment to apply:** Lors de toute évolution du module Formation, vérifier la compatibilité avec les deux types. Les sections modules/leçons ne concernent que le type CONTENU. En Phase 3 (annulation/remboursement), ajouter un champ `status` à CourseEnrollment et adapter `countActiveByCourse()` pour filtrer sur status='active' uniquement.

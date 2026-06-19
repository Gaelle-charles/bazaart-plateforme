# Agent Memory — symfony-backend

- [Profil utilisateur](user_profile.md) — Gaëlle, co-fondatrice Bazaart, dev full-stack en apprentissage, préfère commentaires abondants en français
- [Contexte projet](project_context.md) — Bazaart V1, deadline 15 juin 2026, stack Symfony 7.4/PHP 8.3/PG16, code dans /app/, état d'avancement modules
- [Conventions de code](feedback_conventions.md) — PSR-12, PHPStan niveau 6, commentaires français, voters Symfony, pas de logique dans controllers
- [Module Formation — architecture événements](project_formations_events.md) — Phase 1 livrée : CourseType/CourseEventMode enums, 7 champs Course, service validation, back-office adapté
- [Onboarding artiste Lot 2](project_onboarding_artiste.md) — ADR-0015 Lot 2 livré : gating listener, parcours 4 étapes, migration non-destructive, email bienvenue
- [Ressourcerie ADR-0016 Lot 1](project_ressourcerie_adr0016_lot1.md) — ExperienceLevel enum, city/country sur Resource+ScrapedResource, LLM prompt enrichi, DisciplineMapperService
- [ADR-0017 ListingUrlDiscoverer](project_listing_url_discoverer.md) — Découverte URL-liste par heuristique (30 chemins FR/EN) + fallback LLM Mistral, commande app:discover-listing-urls
- [ADR-0018 Candidature & Financement](project_adr0018_candidature_financement.md) — howToApply/fundingAmount/fundingType sur Resource+ScrapedResource, prompts enrichis, bloc "En un coup d'oeil" page détail
- [ADR-0019 Lien candidature & logo](project_adr0019_lien_candidature_logo.md) — applicationUrl (LLM+anti-hallucination) + logoUrl (HTML parsing SSRF-safe), LogoFetcherService, badge "B" fallback, CTA split show.html.twig
- [Interdiction cadratins](feedback_no_em_dashes.md) — RÈGLE FERME : jamais — ni – dans titres/contenus ; prompts + filet stripEmDashes() dans LlmExtractorService et OpportunityEnrichmentService

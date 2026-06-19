# Mémoire — Bazaart Reviewer

- [Profil utilisatrice](user_gaelle.md) — Gaëlle, co-fondatrice Pôle Lab, développeuse full-stack en apprentissage, commente en français
- [Anti-patterns sécurité auth](feedback_security_antipatterns.md) — Patterns récurrents à vérifier sur toute feature d'authentification
- [Contexte projet V1](project_v1_context.md) — Deadline 15 juin 2026, stack bcrypt, Symfony 7, PHPStan niveau 6
- [Anti-patterns pagination Doctrine](feedback_pagination_antipatterns.md) — setFirstResult + fetch-join collection = doublons ; absence de Doctrine Paginator
- [Anti-patterns restrictions permissions](feedback_permissions_antipatterns.md) — service contourne contrôleur, in_array getRoles() vs hiérarchie Symfony, cohérence brouillon show()
- [Anti-patterns enrichissement IA](feedback_enrichment_ia_patterns.md) — asymétrie disciplines ScrapedResource vs Resource, titre sans description ignoré, placeholder "Description non disponible." à 4 endroits
- [Anti-patterns paiement Stripe](feedback_payment_stripe_antipatterns.md) — nom de route divergent dans les catch, email silencieux sans logger, survente non notifiée, idempotence payment_intent null, countActive sans filtre statut
- [Patterns ADR-0016 Lot 2](feedback_adr0016_lot2_patterns.md) — timezone implicite dans DateTimeImmutable('today'), normalizeTitle x3, parseDtoDeadline sans format long, contentDedup absent des logs commandes

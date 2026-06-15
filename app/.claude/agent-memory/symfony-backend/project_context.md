---
name: project-context
description: Contexte du projet Bazaart — stack, deadline V1, architecture existante
metadata:
  type: project
---

Bazaart est une plateforme pour les artistes de la diaspora afro-atlantique. Deadline V1 : 15 juin 2026.

**Stack :** Symfony 7.x / PHP 8.3 / PostgreSQL 16 / Docker / Redis / Twig + Stimulus + Tailwind.

**Why :** Lancement officiel lors de la clôture de l'incubation Mansa le 15 juin 2026. Planning serré.

**How to apply :** Priorité au fonctionnel sur la perfection. Pas de fonctionnalités V2/V3 pendant V1.

**Architecture :** Controllers minces → Services → Repositories. DTOs pour toutes les entrées. PHPStan niveau 6 obligatoire sur les nouveaux modules. Attributs PHP 8 partout (jamais d'annotations). Migrations via `doctrine:migrations:diff`, jamais `schema:update`.

**Environnement :** L'app tourne dans Docker (container `bazaart_app`). Les vendors ne sont PAS installés sur la machine hôte — toutes les commandes bin/console et phpstan doivent tourner via `docker compose exec app ...`.

**Thème front :** "Street" — split-screen 50/50, fond `--ink` / crème `--bg`, vert lime `--accent` (#C6F24E), bords francs (radius 0), Archivo Black pour les titres.

**Email :** TemplatedEmail + Twig templates dans `templates/emails/`. Expéditeur fixe `noreply@bazaart.fr`. URLs absolues via `url()` Twig ou `UrlGeneratorInterface::ABSOLUTE_URL`.

**Auth existante :** bcrypt, firewall `main`, Google OAuth via KnpUOAuth2ClientBundle, UserChecker pour les comptes anonymisés RGPD.

# ADR-0001 — Module Formation reporté hors périmètre V1 (15 juin 2026)

- **Date** : 2026-05-22
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le module Formation représente environ 6 jours/homme d'effort estimé (entités, pages catalogue,
espace apprenant, lecteur Bunny Stream, suivi de progression, interface admin). Il était prévu en
semaine 3-4 du planning CDC V3 (26 mai – 8 juin).

Au 22 mai, la Ressourcerie accuse ~1 semaine de glissement et la Communauté n'a pas encore
démarré. Le module Formation n'a aucune ligne de code. Il reste 24 jours calendaires avant le
15 juin. La marge de sécurité du CDC (2-3 jours) est quasi consommée.

Conserver la Formation dans le périmètre V1 aurait signifié soit livrer les autres modules
dégradés, soit risquer l'ensemble du lancement.

## Options envisagées

1. **Maintenir la Formation en V1** — Avantages : périmètre complet, 3 modules au lancement.
   Inconvénients : 6 j/h impossibles à absorber sans sacrifier Communauté ou Ressourcerie ;
   l'intégration Bunny Stream est le risque technique le plus élevé du projet (première
   intégration, API externe, upload vidéo) ; un module incomplet le soir du lancement est pire
   qu'un module absent.

2. **Reporter la Formation en post-lancement (V1.5 / V2)** — Avantages : focus sur Ressourcerie
   et Communauté (les deux modules les plus différenciants pour la soirée du 15 juin) ; libère
   6 j/h pour polish, QA, RGPD, et marge de sécurité ; aucun risque Bunny Stream en V1 ;
   l'intégration Bunny Stream développée en V2 profitera aussi aux replays Lives (ADR-0002).
   Inconvénients : le module Formation n'est pas disponible au lancement officiel Mansa.

## Décision

**Option 2 retenue** : le module Formation est retiré du périmètre V1 et reporté en post-lancement
(objectif V1.5, juillet 2026, avant la V2 Studio IA).

La communication du 15 juin annoncera la Formation comme "prochainement disponible", avec une
date cible de juillet 2026.

## Conséquences

- **Périmètre V1 révisé** : Ressourcerie + Communauté + Dashboard admin. La Formation n'est plus
  incluse.
- **Entités à ne pas créer en V1** : `Course`, `CourseModule`, `Lesson`, `LessonResource`,
  `CourseEnrollment`, `LessonProgress`. Leurs migrations sont reportées.
- **Bunny Stream en V1** : l'intégration API Bunny n'est plus requise pour la V1. Elle sera
  développée conjointement pour Formation + Replays Lives en V2.
- **6 j/h récupérés** : redistribués vers polish, QA, RGPD (CGU, confidentialité, bannière
  cookies), et marge de sécurité.
- **CLAUDE.md** : divergence à signaler — la section 6 (Roadmap) mentionne la Formation en V1.
  Mise à jour à arbitrer par Gaëlle.
- **CDC V3** : le périmètre fonctionnel du module Formation (section 4, Module 3) reste la
  spécification de référence pour l'implémentation post-lancement. Ne pas modifier le CDC.

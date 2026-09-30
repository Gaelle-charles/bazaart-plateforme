# ADR-0038 — Espace projets : opportunités pour nos associations

- **Date** : 2026-09-30
- **Statut** : proposé
- **Décidé par** : Gaëlle

## Contexte

La plateforme recense des opportunités (aides, bourses, appels à projets, résidences…),
surtout pensées pour les artistes. L'équipe veut aussi repérer celles auxquelles ses
**deux associations** peuvent candidater : **BazaArt Guadeloupe** et **BazaArt Paris**,
dans un espace réservé aux 3 membres de l'Espace projets (ROLE_PROJECT), et n'y voir
**que** les opportunités qui correspondent.

## Décision

1. Nouvel onglet **« Opportunités »** dans l'Espace projets
   (`/admin/projets/opportunites`, protégé par `ProjectVoter::ACCESS`).
2. Tri **automatique** par `AssociationOpportunityMatcher`, pour chaque association,
   sur les opportunités publiées et non expirées du catalogue (même source que le matching
   artistes : `ResourceRepository::findPublishedForMatching()`) :
   - exclusion si l'opportunité est réservée aux personnes physiques, si elle est
     rattachée à une ville hors du territoire, ou si elle ne cite que le territoire de
     l'autre association sans être nationale ;
   - score : ouverte aux structures (40), territoire cité (35) ou appel national (10),
     type financement / appel (10), lien afro-diasporique (15) ;
   - affichée si non exclue, ouverte aux structures OU sur notre territoire, et score ≥ 40.
   Les **raisons** sont affichées pour que l'équipe juge elle-même.
3. Décisions d'équipe dans `project_opportunity_reviews` : **Retenir**, **Écarter**,
   **Candidater**. « Candidater » crée un projet avec le modèle « Candidature / appel à
   projets » (étapes planifiées à rebours depuis la date limite), relié à l'opportunité.
4. Les deux associations sont un enum (`BazaartAssociation`) : leurs mots-clés de
   territoire sont modifiables dans le code.

## Conséquences

- Tri par mots-clés : des faux positifs sont possibles (on les écarte en un clic) ;
  des faux négatifs aussi si une opportunité ne mentionne ni « association »,
  « structure », « collectif »… ni notre territoire.
- Évolution possible : enrichir l'extraction IA (`LlmExtractorService`, champ
  `publicEligible`) pour stocker explicitement « ouvert aux associations ».

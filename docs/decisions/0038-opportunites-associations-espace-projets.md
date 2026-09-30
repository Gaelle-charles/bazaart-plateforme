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
2. **Fiche de chaque association** (« Nos associations », table `project_association_profiles`),
   remplie par l'équipe :
   - identité : objet, publics, activités, SIRET, année de création, budget, moyens
     humains, site web — recopiée dans chaque projet de candidature ;
   - critères du tri : territoire, thèmes / publics / champs d'action, mots à exclure,
     types d'opportunités recherchés, disciplines.
   Les deux fiches sont créées pré-remplies à la première ouverture.
3. Tri **automatique** par `AssociationOpportunityMatcher`, pour chaque association,
   sur les opportunités publiées et non expirées du catalogue (même source que le matching
   artistes : `ResourceRepository::findPublishedForMatching()`) :
   - exclusions : réservée aux personnes physiques, mot exclu de la fiche, type non
     recherché, aucune discipline en commun, ville hors territoire, territoire de l'autre
     association seulement (sans appel national) ;
   - score : ouverte aux structures (30), territoire (30) ou appel national (10),
     thèmes de la fiche (10 par mot, 20 max), discipline commune (10, ou 5 si ouverte à
     toutes), type recherché (10) ;
   - affichée si non exclue, ouverte aux structures OU sur notre territoire, et score ≥ 40.
   Les **raisons** sont affichées : si le tri se trompe, on ajuste la fiche.
4. Décisions d'équipe dans `project_opportunity_reviews` : **Retenir**, **Écarter**,
   **Candidater**. « Candidater » crée un projet avec le modèle « Candidature / appel à
   projets » (étapes planifiées à rebours depuis la date limite), relié à l'opportunité,
   avec la fiche de l'association dans sa description.

## Conséquences

- Tri par mots-clés : des faux positifs sont possibles (on les écarte en un clic) ;
  des faux négatifs aussi si une opportunité ne mentionne ni « association »,
  « structure », « collectif »… ni notre territoire.
- Évolution possible : enrichir l'extraction IA (`LlmExtractorService`, champ
  `publicEligible`) pour stocker explicitement « ouvert aux associations ».

# ADR-0008 — Soumission de ressources réservée aux structures (les artistes ne soumettent plus)

- **Date** : 2026-06-13
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Le cahier des charges V3 prévoyait que **les artistes puissent soumettre des ressources**
(opportunités) à la Ressourcerie, avec validation admin avant publication. Dans les faits,
le parcours réel d'un artiste est différent : il **consulte** les opportunités, puis
**candidate directement sur le site de la structure** via le lien externe de l'opportunité.

La soumission d'opportunités par les artistes :
- crée une confusion (l'onglet s'appelait « Mes candidatures » alors qu'il listait des
  ressources soumises — deux notions différentes) ;
- fait double emploi avec le scraping automatique + les soumissions des structures
  partenaires, qui alimentent déjà la Ressourcerie ;
- n'a pas d'usage réel côté artiste (on ne « candidate » pas sur la plateforme).

Il fallait clarifier qui alimente la Ressourcerie et simplifier le dashboard artiste.

## Options envisagées

1. **Conserver la soumission ouverte à tous les `ROLE_USER`** (cible CDC V3) — Avantage :
   conforme au cahier des charges initial, aucun changement. Inconvénients : onglet « Mes
   candidatures » trompeur, fonctionnalité sans usage réel pour les artistes, file de
   validation polluée par des soumissions artistes hétérogènes.

2. **Réserver la soumission aux structures (+ admins)** — Avantage : périmètre clair
   (les structures proposent, les artistes consultent / favorisent / candidatent en externe),
   dashboard artiste épuré, qualité des soumissions homogène. Inconvénient : écart assumé au
   CDC V3 ; un artiste ne peut plus proposer d'opportunité (jugé non nécessaire en V1).

## Décision

**Option 2.** La soumission de ressources (`/resources/submit`) et la page « mes ressources
soumises » (`/resources/my`) sont **réservées à `ROLE_STRUCTURE`** (les admins y accèdent par
héritage de rôles). Les artistes conservent : consultation des opportunités, **favoris**, et
**candidature via le lien externe** de la structure. L'onglet « Mes candidatures » est retiré
du menu artiste.

## Conséquences

- **Code** (commit `1dce6a9`) : `#[IsGranted('ROLE_STRUCTURE')]` sur `ResourceController::submit`
  et `::my` ; boutons « Soumettre » masqués côté artiste (gardés dans le dashboard structure) ;
  onglet retiré de `sidebar_artiste.html.twig`. Validé E2E en prod (artiste → 403 sur submit/my ;
  consultation + favoris → 200).
- **Écart au CDC V3** : la « soumission artiste avec validation » devient « soumission structure ».
  À reporter dans une future mise à jour du cahier des charges.
- **À surveiller** : si un besoin de contribution artiste réapparaît (V2), prévoir un parcours
  dédié distinct de la candidature externe.
- Les favoris et le lien de candidature externe restent le cœur de l'expérience artiste sur la
  Ressourcerie.

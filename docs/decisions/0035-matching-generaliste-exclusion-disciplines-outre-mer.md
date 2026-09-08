# ADR-0035 — Matching : ressources généralistes, exclusion dure par discipline et outre-mer

- **Date** : 2026-09-07
- **Statut** : proposé
- **Décidé par** : (à valider par Gaëlle)

## Contexte

Des artistes remontent que les ressources proposées par le matching (ADR-0021, `MatchingService`)
ne correspondent pas à leur profil. Diagnostic sur `MatchingService::scoreResource()` :

1. **Ressources généralistes = 0 pt.** La majorité des ressources scrapées arrivent avec des
   libellés de disciplines comme « Toutes disciplines », « Mobilité internationale »,
   « Résidences »… Ces libellés ne sont volontairement mappés vers **aucune** entité `Discipline`
   par `DisciplineMapperService` (ce ne sont pas des disciplines). Résultat : la `Resource`
   publiée n'a aucune discipline associée → `scoreDisciplines()` retournait 0, alors qu'une
   ressource ouverte à toutes les disciplines devrait matcher tout artiste.
2. **Aucune exclusion quand les disciplines sont incompatibles.** Une ressource « Arts visuels »
   pouvait cumuler 30 pts (lookingFor) + jusqu'à 20 pts (territoire) pour un musicien, et
   apparaître en tête de liste. C'est la cause principale des retours « ça ne correspond pas à
   mon profil ».
3. **Ratio pénalisant les ressources multi-disciplines.** L'ancien calcul
   `communes / disciplines_ressource` faisait qu'une ressource listant 8 disciplines dont
   « Musique » ne donnait que 5 pts à un musicien (1/8 × 40), alors qu'elle lui est pleinement
   ouverte.
4. **Territoire aveugle aux outre-mer et aux accents.** Un artiste en Guadeloupe, Martinique,
   Guyane, Réunion ou Mayotte ne matchait jamais une ressource dont le pays est `"France"` (son
   texte de localisation ne contient pas le mot « France »). La comparaison ne normalisait pas
   non plus les accents (« Réunion » vs « Reunion »).

## Options envisagées

1. **Corriger localement chaque symptôme** (ex. juste ajouter le bonus outre-mer) sans revoir le
   modèle de scoring des disciplines — rapide, mais laisse l'exclusion dure de côté et ne résout
   pas le problème n°1 (majorité du catalogue).
2. **Revoir le modèle de scoring des disciplines** (ressource généraliste = score forfaitaire,
   coefficient de recouvrement au lieu d'un ratio simple, exclusion dure en cas de conflit) +
   normaliser le territoire (accents + outre-mer). C'est l'option qui traite la cause racine des
   retours artistes plutôt que ses symptômes.

## Décision

Option 2, retenue. Détail des changements dans `MatchingService` :

- **Disciplines communes (max 40 pts, inchangé)** :
  - Artiste sans discipline → 0 pt (inchangé).
  - Ressource sans discipline (généraliste) → nouveau score forfaitaire
    `SCORE_DISCIPLINES_GENERALIST = 20` (la moitié du max : ouverte à tous, mais moins ciblée
    qu'un match explicite).
  - Sinon, coefficient de recouvrement de Szymkiewicz–Simpson :
    `coverage = communes / min(nb_disciplines_ressource, nb_disciplines_artiste)`,
    score = `round(coverage × 40)`. Corrige la pénalisation des ressources multi-disciplines.
  - **Exclusion dure** : si ressource ET artiste ont des disciplines renseignées mais **aucune**
    en commun, le score total de `scoreResource()` est forcé à **0**, et le breakdown entier
    (`disciplines`, `looking_for`, `territory`, `experience`) est mis à 0 — les autres composantes
    ne sont même pas calculées. C'est un critère éliminatoire dans ce cas précis (voir Conséquences).
- **Territoire (max 20 pts, inchangé)** :
  - Comparaison désormais insensible aux accents (normalisation NFD + suppression des
    diacritiques, repli `iconv` si `\Normalizer` indisponible).
  - Nouveau bonus pays de repli : si le pays normalisé de la ressource est `"france"` et que la
    localisation de l'artiste mentionne un territoire d'outre-mer (Guadeloupe, Martinique,
    Guyane, Réunion, Mayotte, Saint-Martin, Saint-Barthélemy, Nouvelle-Calédonie, Polynésie), le
    bonus pays (+10 pts) est accordé. Pas de réciproque nécessaire (les ressources scrapées
    indiquent presque toujours « France », jamais le DROM-COM spécifique).

## Conséquences

- **Score global potentiellement plus bas pour les ressources en conflit de discipline** :
  c'est voulu — c'est le correctif direct du problème remonté par les artistes. À surveiller :
  si un artiste a mal renseigné ses disciplines (ex. une seule discipline très restrictive), il
  pourrait se voir exclure des ressources pertinentes. Pas de garde-fou supplémentaire prévu en
  V1 ; à réévaluer si les retours vont dans ce sens après mise en prod.
- **Ressources généralistes remontent davantage** (score forfaitaire 20/40 au lieu de 0), ce qui
  devrait sensiblement augmenter le nombre de matchs perçus par les artistes vu leur poids dans
  le catalogue scrapé.
- **Artistes d'outre-mer** : meilleure couverture géographique, alignée avec le coeur de cible de
  Bazaart (diaspora afro-atlantique, fortement présente dans les DROM-COM).
- **Pas d'impact sur** : `LOOKING_FOR_TO_TYPE_KEYWORDS`, les controllers, le DTO `MatchResult`, le
  repository `ResourceRepository`. Les poids globaux (40/30/20/10, total 100) sont inchangés.
- **Tests** : `tests/Unit/Service/MatchingServiceTest.php` mis à jour (ratio → coefficient de
  recouvrement) et complété (ressource généraliste, recouvrement partiel, conflit de disciplines
  avec breakdown entièrement nul, conflit qui l'emporte même si lookingFor/territoire matchent,
  bonus outre-mer, accents).
- **Révision d'ADR-0021** : le point « Score la concordance entre disciplines... » de la section
  scoring y est complété par un renvoi vers le présent ADR pour ne pas dupliquer la documentation
  du modèle de scoring (qui reste dans le grand commentaire de classe de `MatchingService`).

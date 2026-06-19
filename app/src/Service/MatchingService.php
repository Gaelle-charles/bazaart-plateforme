<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Matching\MatchResult;
use App\Entity\ArtistProfile;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\ArtistLookingFor;
use App\Enum\ExperienceLevel;
use App\Repository\ResourceRepository;

/**
 * MatchingService — Moteur de matching artiste <-> ressource (ADR-0021, Lot B).
 *
 * Ce service constitue le coeur du système de matching : il calcule un score
 * numérique (0 à 100) pour chaque ressource publiée vis-à-vis du profil d'un artiste,
 * puis retourne la liste triée du meilleur au moins bon.
 *
 * ─── MODÈLE DE SCORING ──────────────────────────────────────────────────────
 *
 * Le score est la somme de 4 composantes indépendantes (max = 100 points) :
 *
 *  1. DISCIPLINES COMMUNES       max 40 pts  (critère fort)
 *     Ratio de disciplines communes / disciplines de la ressource.
 *     Exemple : ressource a 3 disciplines, l'artiste en partage 2 → 2/3 × 40 = ~27 pts.
 *     Pourquoi le ratio plutôt qu'un comptage brut ?
 *       Une ressource "Musique + Danse + Arts visuels" qui n'a 0 discipline commune
 *       avec un artiste spécialisé Danse aurait un score injustement élevé avec
 *       un comptage brut si l'artiste avait beaucoup de disciplines.
 *       Le ratio "disciplines communes / disciplines de la ressource" mesure plutôt
 *       à quel point l'artiste COUVRE la ressource, ce qui est plus pertinent.
 *     Pas de disciplines sur la ressource → 0 pts (pas de pénalité, pas de bonus).
 *     Pas de disciplines sur l'artiste → 0 pts.
 *
 *  2. CE QUE CHERCHE L'ARTISTE   max 30 pts  (critère fort)
 *     Mapping ArtistLookingFor → catégories de ResourceType.
 *     Si le type de la ressource correspond à ce que l'artiste cherche → 30 pts.
 *     Le mapping est défini dans LOOKING_FOR_TO_TYPE_KEYWORDS (voir constante).
 *     Artiste sans lookingFor → 0 pts.
 *
 *  3. TERRITOIRE / LOCALISATION  max 20 pts  (critère modéré)
 *     Concordance géographique entre l'artiste et la ressource.
 *       - Ressource sans lieu (city ET country null) → 0 pts  (neutre, pas pénalisant)
 *       - Même pays → +10 pts
 *       - Même ville (en plus du pays) → +10 pts supplémentaires (soit 20 au total)
 *     Un artiste sans localisation → 0 pts sur ce critère.
 *     Comparaison insensible à la casse, avec trim des espaces.
 *
 *  4. NIVEAU D'EXPÉRIENCE        max 10 pts  (critère faible)
 *     Si la ressource précise un niveau requis ET l'artiste a renseigné son niveau :
 *       - Correspondance exacte → +10 pts
 *       - Pas de correspondance → 0 pts (pas de pénalité : l'artiste peut quand même tenter)
 *     Ressource sans niveau (null = "tous niveaux") → 0 pts (critère non applicable,
 *     donc pas de désavantage pour les débutants face à une ressource "tous niveaux").
 *     Note : l'artiste n'a pas encore de champ experienceLevel sur ArtistProfile
 *     (non livré dans le Lot A). Ce critère retournera donc toujours 0 pts en V1.
 *     Il est conservé dans l'architecture pour l'évolution future (V2).
 *
 * SCORE MAX POSSIBLE : 40 + 30 + 20 + 10 = 100 pts
 *
 * ─── FILTRAGE DUR (avant le scoring) ────────────────────────────────────────
 *
 * Le repository ResourceRepository::findPublishedForMatching() filtre déjà :
 *   - status = Published
 *   - deadline IS NULL OR deadline >= aujourd'hui
 * Ces ressources ne passent donc JAMAIS dans le moteur de scoring.
 *
 * ─── DÉTAIL (breakdown) ─────────────────────────────────────────────────────
 *
 * Chaque MatchResult porte un $breakdown = ['disciplines' => 30, 'looking_for' => 20, ...].
 * Ce détail est exposé dans la réponse JSON (endpoint /api/matching/my-matches).
 * Il permettra à l'UI Lot C d'afficher "Pourquoi ce match ?" et aux dev de déboguer
 * le scoring sans modifier le service.
 *
 * ─── DÉTERMINISME ────────────────────────────────────────────────────────────
 *
 * Le score est 100% déterministe : à profil et catalogue identiques, la liste
 * retournée est toujours la même (tri secondaire par ID décroissant en cas d'égalité).
 * Pas de hasard, pas de date de "freshness" dans le score — uniquement les données
 * de profil et de la ressource.
 */
final class MatchingService
{
    // ─── Poids de chaque composante du score (total = 100) ───────────────────
    //
    // Ces constantes centralisent les poids pour faciliter les ajustements futurs
    // sans chercher dans le code. Si Gaëlle veut baisser l'importance du territoire
    // et monter celle des disciplines, il suffit de changer deux constantes ici.

    /** Poids max pour les disciplines communes (critère le plus fort) */
    private const int SCORE_DISCIPLINES = 40;

    /** Poids max pour la concordance lookingFor <-> type de ressource */
    private const int SCORE_LOOKING_FOR = 30;

    /** Poids max pour la concordance géographique artiste <-> ressource */
    private const int SCORE_TERRITORY = 20;

    /** Poids max pour la concordance de niveau d'expérience */
    private const int SCORE_EXPERIENCE = 10;

    // ─── Mapping ArtistLookingFor → mots-clés dans le nom du ResourceType ────
    //
    // L'artiste exprime ce qu'il cherche via l'enum ArtistLookingFor.
    // Les ressources ont un ResourceType dont le nom est en texte libre (ex: "Résidence artistique",
    // "Bourse & Financement", "Appel à projets", "Formation").
    //
    // On fait un matching "mot-clé contenu dans le nom du type" (insensible à la casse).
    // C'est plus robuste qu'une comparaison exacte car les noms de types peuvent varier.
    //
    // MAPPING VALIDÉ (révisé après relecture sémantique) :
    //
    //   FORMATIONS        → types contenant : "formation", "atelier", "workshop", "master"
    //   RESSOURCES_AIDES  → types contenant : "bourse", "financement", "aide", "subvention", "fonds"
    //   RESSOURCES_APPELS → types contenant : "appel", "résidence", "residence", "concours", "commission", "prix"
    //   AUTRE             → pas de mapping (ne génère pas de score lookingFor)
    //
    // POURQUOI "prix" est dans RESSOURCES_APPELS et non RESSOURCES_AIDES ?
    //   "Prix & concours" est sémantiquement un appel à candidature / compétition,
    //   pas une aide financière directe. L'artiste postule et un jury sélectionne :
    //   c'est le même mécanisme qu'un "Appel à projets" ou une "Résidence artistique".
    //   À l'inverse, une bourse ou une subvention est une aide financière accordée
    //   sans compétition ouverte (ou avec des critères d'éligibilité, pas un jury).
    //   Placer "prix" dans RESSOURCES_AIDES créait un faux positif : un artiste qui
    //   cherche "des aides financières" aurait matché avec des types "Prix & concours",
    //   alors que l'intention est différente.
    //
    // Si un type ne contient aucun mot-clé mappé = 0 pts pour ce critère (pas de pénalité).

    /**
     * Mapping ArtistLookingFor → mots-clés à chercher dans ResourceType::getName().
     *
     * @var array<string, string[]>
     */
    private const array LOOKING_FOR_TO_TYPE_KEYWORDS = [
        ArtistLookingFor::FORMATIONS->value => [
            'formation', 'atelier', 'workshop', 'master', 'stage', 'cours',
        ],
        ArtistLookingFor::RESSOURCES_AIDES->value => [
            // "prix" retiré : sémantiquement un appel/concours, pas une aide financière.
            // Voir commentaire bloc ci-dessus pour la justification complète.
            'bourse', 'financement', 'aide', 'subvention', 'fonds', 'grant',
        ],
        ArtistLookingFor::RESSOURCES_APPELS->value => [
            // "prix" ajouté ici : "Prix & concours" est un appel à candidature, pas une aide.
            'appel', 'résidence', 'residence', 'concours', 'commission', 'projet', 'prix',
        ],
        // ArtistLookingFor::AUTRE n'est pas listé : pas de mapping possible
        // (texte libre sans structure → on ne peut pas en déduire un type de ressource)
    ];

    public function __construct(
        private readonly ResourceRepository $resourceRepository,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // API PUBLIQUE
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Retourne la liste des matchs pour un artiste, triée du meilleur au moins bon.
     *
     * C'est la méthode principale appelée par MatchingController.
     * Elle orchestre :
     *   1. La récupération du catalogue éligible (via repository)
     *   2. Le scoring de chaque ressource vis-à-vis du profil
     *   3. Le tri par score décroissant
     *
     * GESTION DU PROFIL INCOMPLET :
     *   Si l'artiste n'a pas de profil (ArtistProfile null), on retourne un tableau vide.
     *   Si certains champs du profil sont null (pas de disciplines, pas de localisation),
     *   les critères correspondants contribuent 0 pts — le matching reste possible
     *   mais moins précis. Aucune exception n'est levée.
     *
     * PERFORMANCES :
     *   Le catalogue est chargé en une requête SQL (disciplines incluses via JOIN).
     *   Le scoring est fait en PHP en mémoire — O(N) sur le nombre de ressources.
     *   Pour N < 5 000 ressources, c'est largement acceptable (<100 ms).
     *
     * @param User $user L'utilisateur connecté (doit avoir ROLE_ARTIST, vérifié par le Voter)
     * @return MatchResult[] Liste triée par score décroissant, scores identiques : tri par ID desc
     */
    public function getMatchesForUser(User $user): array
    {
        // Récupère le profil artiste — peut être null si le profil n'a pas été créé
        $artistProfile = $user->getArtistProfile();

        // Si pas de profil artiste, on ne peut pas calculer de score : retour vide.
        // Le controller affichera un message invitant l'artiste à compléter son profil.
        if ($artistProfile === null) {
            return [];
        }

        // Charge toutes les ressources publiées non expirées avec disciplines préchargées.
        // ResourceRepository::findPublishedForMatching() gère le filtrage dur.
        $resources = $this->resourceRepository->findPublishedForMatching();

        // Score chaque ressource et construit les MatchResult
        $results = array_map(
            fn(Resource $resource) => $this->scoreResource($resource, $artistProfile),
            $resources
        );

        // Tri par score décroissant (meilleur match en premier).
        // En cas d'égalité de score, on trie par ID décroissant pour un résultat
        // déterministe (les ressources les plus récentes passent devant).
        usort($results, function (MatchResult $a, MatchResult $b): int {
            // Ordre décroissant sur le score : b - a (si b > a → b vient en premier)
            if ($b->score !== $a->score) {
                return $b->score - $a->score;
            }
            // Égalité : tri par ID décroissant (plus récent en premier)
            return ($b->resource->getId() ?? 0) - ($a->resource->getId() ?? 0);
        });

        return $results;
    }

    /**
     * Compte le nombre de ressources qui matchent le profil d'un artiste.
     *
     * Définition d'un "match" en V1 : score > 0 (au moins un critère contribue).
     * On ne filtre pas sur un seuil minimum plus élevé pour maximiser le nombre
     * de résultats présentés (l'artiste juge lui-même la pertinence via l'UI swipe).
     *
     * Utilisé par le MatchingController pour retourner le compteur affiché
     * dans la section hero de la home ("X opportunités correspondent à votre profil").
     *
     * Note : cette méthode appelle getMatchesForUser() en interne.
     * Si le volume devient grand, on pourrait optimiser avec un COUNT SQL dédié,
     * mais pour la V1 c'est largement suffisant.
     *
     * @param User $user L'utilisateur connecté
     * @return int Nombre de ressources avec score > 0
     */
    public function countMatchesForUser(User $user): int
    {
        $matches = $this->getMatchesForUser($user);

        // On compte seulement les ressources avec un score positif
        return count(array_filter($matches, fn(MatchResult $r) => $r->score > 0));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // LOGIQUE DE SCORING (interne)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Score UNE ressource vis-à-vis du profil d'un artiste.
     *
     * Méthode publique pour faciliter les tests unitaires directs du scoring.
     * Le controller appelle getMatchesForUser() qui l'invoque en interne.
     *
     * @param Resource      $resource      La ressource à évaluer
     * @param ArtistProfile $artistProfile Le profil de l'artiste connecté
     * @return MatchResult  Score + détail (breakdown) + référence à la ressource
     */
    public function scoreResource(Resource $resource, ArtistProfile $artistProfile): MatchResult
    {
        // Calcule chaque composante indépendamment
        $scoreDisciplines = $this->scoreDisciplines($resource, $artistProfile);
        $scoreLookingFor  = $this->scoreLookingFor($resource, $artistProfile);
        $scoreTerritory   = $this->scoreTerritory($resource, $artistProfile);
        $scoreExperience  = $this->scoreExperience($resource, $artistProfile);

        // Score total = somme des composantes (plafonné à 100 par construction)
        $total = $scoreDisciplines + $scoreLookingFor + $scoreTerritory + $scoreExperience;

        // Breakdown : un tableau associatif nommé pour la lisibilité
        $breakdown = [
            'disciplines' => $scoreDisciplines,
            'looking_for' => $scoreLookingFor,
            'territory'   => $scoreTerritory,
            'experience'  => $scoreExperience,
        ];

        return new MatchResult(
            resource:  $resource,
            score:     $total,
            breakdown: $breakdown,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Composante 1 : DISCIPLINES COMMUNES (max 40 pts)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Score la concordance entre les disciplines de la ressource et celles de l'artiste.
     *
     * Formule : ratio × SCORE_DISCIPLINES (arrondi au point entier le plus proche).
     *   ratio = nb_disciplines_communes / nb_disciplines_ressource
     *
     * Exemples :
     *   - Ressource : {Musique, Danse}   Artiste : {Musique, Danse}  → 2/2 × 40 = 40 pts (max)
     *   - Ressource : {Musique, Danse}   Artiste : {Musique}         → 1/2 × 40 = 20 pts
     *   - Ressource : {Musique, Danse}   Artiste : {Théâtre}         → 0/2 × 40 =  0 pts
     *   - Ressource sans discipline       Artiste : {Musique}         → 0/0 → 0 pts
     *   - Ressource : {Musique}           Artiste sans discipline      → 0/1 → 0 pts
     *
     * @return int Score entre 0 et SCORE_DISCIPLINES (= 40)
     */
    private function scoreDisciplines(Resource $resource, ArtistProfile $artistProfile): int
    {
        $resourceDisciplines = $resource->getDisciplines();
        $artistDisciplines   = $artistProfile->getDisciplines();

        // Cas trivial : si la ressource n'a aucune discipline, pas de scoring possible
        if ($resourceDisciplines->isEmpty()) {
            return 0;
        }

        // Cas trivial : si l'artiste n'a aucune discipline renseignée, score = 0
        if ($artistDisciplines->isEmpty()) {
            return 0;
        }

        // Extrait les IDs des disciplines de l'artiste dans un ensemble pour une
        // recherche en O(1) (plutôt qu'un double boucle O(N×M)).
        // array_keys() retourne les indices, mais on veut les IDs des entités Discipline.
        $artistDisciplineIds = [];
        foreach ($artistDisciplines as $discipline) {
            // getId() peut être null si l'entité n'est pas encore persistée (cas de test),
            // mais en production les disciplines existent toujours en BDD.
            $id = $discipline->getId();
            if ($id !== null) {
                $artistDisciplineIds[$id] = true; // tableau associatif pour lookup O(1)
            }
        }

        // Compte les disciplines communes
        $commonCount = 0;
        foreach ($resourceDisciplines as $discipline) {
            $id = $discipline->getId();
            if ($id !== null && isset($artistDisciplineIds[$id])) {
                $commonCount++;
            }
        }

        // Calcule le ratio et applique le poids
        // intdiv() arrondi vers le bas — pour les fractions on utilise round() pour
        // une meilleure répartition des points (ex: 1/3 × 40 = 13 plutôt que 13.33)
        $ratio = $commonCount / $resourceDisciplines->count();

        return (int) round($ratio * self::SCORE_DISCIPLINES);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Composante 2 : CE QUE CHERCHE L'ARTISTE vs TYPE DE RESSOURCE (max 30 pts)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Score la concordance entre ce que cherche l'artiste (lookingFor) et le type de la ressource.
     *
     * Logique :
     *   1. On récupère les préférences de l'artiste (User::$lookingFor = tableau de strings)
     *   2. On convertit chaque string en case ArtistLookingFor (enum backed)
     *   3. Pour chaque case, on vérifie si le nom du ResourceType de la ressource
     *      contient un des mots-clés de LOOKING_FOR_TO_TYPE_KEYWORDS
     *   4. Si au moins une case matche → score plein (30 pts)
     *      Pourquoi "au moins une" et pas une pondération ?
     *        Si l'artiste coche plusieurs cases (ex: formations + aides), il cherche
     *        TOUTES ces choses. Une ressource qui matche n'importe laquelle de ses
     *        demandes est pertinente → score plein. Pas de demi-point ici.
     *
     * Score retourné : 30 pts (match) ou 0 pts (pas de match ou lookingFor null).
     *
     * @return int 0 ou SCORE_LOOKING_FOR (= 30)
     */
    private function scoreLookingFor(Resource $resource, ArtistProfile $artistProfile): int
    {
        // Les préférences sont stockées sur l'entité User, pas ArtistProfile.
        // On accède à l'User via le profil artiste.
        $lookingForValues = $artistProfile->getUser()->getLookingFor();

        // Si l'artiste n'a pas renseigné ses objectifs → critère non applicable
        if (empty($lookingForValues)) {
            return 0;
        }

        // Nom du type de ressource (en minuscules pour la comparaison insensible à la casse)
        $resourceTypeName = mb_strtolower($resource->getResourceType()->getName());

        // Vérifie si un des objectifs de l'artiste correspond au type de cette ressource
        foreach ($lookingForValues as $lookingForValue) {
            // On vérifie que la valeur est connue dans notre mapping
            if (!isset(self::LOOKING_FOR_TO_TYPE_KEYWORDS[$lookingForValue])) {
                // AUTRE ou valeur inconnue → pas de mapping défini → on passe
                continue;
            }

            // Récupère les mots-clés correspondant à cet objectif
            $keywords = self::LOOKING_FOR_TO_TYPE_KEYWORDS[$lookingForValue];

            // Vérifie si le nom du type contient au moins un des mots-clés
            foreach ($keywords as $keyword) {
                if (str_contains($resourceTypeName, mb_strtolower($keyword))) {
                    // Match trouvé → score plein pour ce critère
                    return self::SCORE_LOOKING_FOR;
                }
            }
        }

        // Aucun objectif de l'artiste ne correspond au type de cette ressource
        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Composante 3 : TERRITOIRE / LOCALISATION (max 20 pts)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Score la concordance géographique entre l'artiste et la ressource.
     *
     * RÈGLES :
     *   - Ressource sans lieu (city AND country sont null) → 0 pts (neutre, pas pénalisant)
     *     Justification : une bourse nationale ou une formation en ligne n'a pas de lieu
     *     spécifique → ce n'est pas une mauvaise ressource pour l'artiste, juste non-locale.
     *   - Même pays → +10 pts
     *   - Même ville EN PLUS du même pays → +10 pts supplémentaires (total 20 pts max)
     *   - Pays différent (et ressource a un pays) → 0 pts
     *
     * SOURCE DES DONNÉES :
     *   - Artiste : ArtistProfile::$location (champ texte libre, ex: "Paris, France")
     *     Ce champ n'est PAS structuré séparément en ville/pays sur ArtistProfile.
     *     On l'interprète comme un champ libre qu'on compare avec ville et pays de la ressource.
     *   - Ressource : Resource::$city + Resource::$country (champs séparés, ajoutés ADR-0016)
     *
     * STRATÉGIE DE COMPARAISON :
     *   On compare le champ $location de l'artiste (texte libre) avec $city et $country
     *   de la ressource via str_contains (insensible à la casse).
     *   C'est une approche "best effort" acceptable pour la V1.
     *   Exemple : location = "Paris, France" → contains "france" → +10, contains "paris" → +10.
     *
     * LIMITE CONNUE :
     *   Si l'artiste écrit "Paris" sans le pays, on ne peut pas inférer "France".
     *   Si la ressource a city="paris" (minuscules) et l'artiste a location="PARIS" → match.
     *   Si l'artiste a location="75001 Paris" → "paris" sera trouvé → match.
     *   Les faux négatifs (artiste à Paris mais location = "Île-de-France") sont acceptables
     *   pour la V1 — une normalisation plus fine serait une amélioration V2.
     *
     * @return int Score entre 0 et SCORE_TERRITORY (= 20)
     */
    private function scoreTerritory(Resource $resource, ArtistProfile $artistProfile): int
    {
        $resourceCity    = $resource->getCity();
        $resourceCountry = $resource->getCountry();

        // Règle explicite ADR-0021 : ressource sans lieu = neutre (0 pts, pas de pénalité)
        // On teste les deux champs : si les DEUX sont null/vides, on retourne 0 points.
        if (empty($resourceCity) && empty($resourceCountry)) {
            return 0;
        }

        // Récupère la localisation de l'artiste
        $artistLocation = $artistProfile->getLocation();

        // Si l'artiste n'a pas renseigné sa localisation → critère non applicable
        if (empty($artistLocation)) {
            return 0;
        }

        // Normalisation : minuscules + trim pour la comparaison
        $artistLocationNorm = mb_strtolower(trim($artistLocation));

        $score = 0;

        // ── Bonus pays (+10 pts) ─────────────────────────────────────────────
        if (!empty($resourceCountry)) {
            $countryNorm = mb_strtolower(trim($resourceCountry));
            // str_contains vérifie si le pays de la ressource apparaît dans la localisation
            // de l'artiste. Ex: "France" dans "Paris, France" → true.
            if (str_contains($artistLocationNorm, $countryNorm)) {
                $score += self::SCORE_TERRITORY / 2; // = 10 pts
            }
        }

        // ── Bonus ville (+10 pts supplémentaires) ────────────────────────────
        if (!empty($resourceCity)) {
            $cityNorm = mb_strtolower(trim($resourceCity));
            if (str_contains($artistLocationNorm, $cityNorm)) {
                $score += self::SCORE_TERRITORY / 2; // = 10 pts de plus
            }
        }

        return $score;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Composante 4 : NIVEAU D'EXPÉRIENCE (max 10 pts)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Score la concordance entre le niveau d'expérience requis par la ressource
     * et le niveau déclaré par l'artiste.
     *
     * NOTE V1 : ArtistProfile ne porte PAS encore de champ experienceLevel.
     * Ce champ n'a pas été livré dans le Lot A. Cette méthode retourne donc
     * toujours 0 en V1 — elle est conservée dans l'architecture pour :
     *   1. Ne pas avoir à refactorer MatchingService quand le champ sera ajouté.
     *   2. Tester la logique unitairement dès maintenant (en injectant un mock).
     *
     * QUAND LE CHAMP SERA AJOUTÉ :
     *   Ajouter $experienceLevel sur ArtistProfile, générer la migration,
     *   décommenter la méthode getExperienceLevel() appelée ici.
     *
     * RÈGLES (pour quand le champ existera) :
     *   - Ressource sans niveau (null = "tous niveaux") → 0 pts
     *     (ce n'est pas une pénalité : la ressource est accessible à tous)
     *   - Correspondance exacte niveau artiste = niveau ressource → +10 pts
     *   - Pas de correspondance → 0 pts (pas de pénalité : l'artiste peut quand même tenter)
     *
     * @return int 0 en V1 (champ manquant sur ArtistProfile) ; 0 ou 10 en V2
     */
    private function scoreExperience(Resource $resource, ArtistProfile $artistProfile): int
    {
        // ── V1 : champ experienceLevel absent de ArtistProfile ───────────────
        // Retour immédiat à 0. Le reste du code ci-dessous sera activé en V2
        // quand getExperienceLevel() sera disponible sur ArtistProfile.
        // On garde le code en commentaire pour documenter la logique prévue.

        $resourceLevel = $resource->getExperienceLevel();

        // Cas 1 : la ressource ne précise pas de niveau requis → pas de scoring possible
        // (0 pts, pas de pénalité — la ressource s'adresse à tous)
        if ($resourceLevel === null) {
            return 0;
        }

        // Cas 2 : la ressource a un niveau, mais l'artiste n'a pas encore renseigné le sien.
        // ArtistProfile n'a pas encore de champ experienceLevel en V1.
        // On retourne 0 pts sans pénalité (l'artiste n'est pas exclu, juste non boosté).

        // TODO (V2) : décommenter quand ArtistProfile::getExperienceLevel() sera disponible
        // $artistLevel = $artistProfile->getExperienceLevel();
        // if ($artistLevel === null) {
        //     return 0; // niveau artiste non renseigné → pas de scoring
        // }
        // return $artistLevel === $resourceLevel ? self::SCORE_EXPERIENCE : 0;

        // En V1 : retour systématique à 0 pour ce critère
        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Accesseurs des poids (utiles pour les tests et la future UI d'explication)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne la carte des poids maximaux par critère.
     * Utilisé par les tests pour vérifier que scoreResource() ne dépasse jamais
     * le poids max d'une composante, sans hardcoder les valeurs dans les tests.
     *
     * @return array<string, int>
     */
    public function getScoreWeights(): array
    {
        return [
            'disciplines' => self::SCORE_DISCIPLINES,
            'looking_for' => self::SCORE_LOOKING_FOR,
            'territory'   => self::SCORE_TERRITORY,
            'experience'  => self::SCORE_EXPERIENCE,
        ];
    }
}

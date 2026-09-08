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
 *
 *     ⚠️ RÉVISÉ (ADR-0035, 2026-09) suite aux retours d'artistes : les ressources
 *     proposées ne correspondaient pas à leur profil (ex. un musicien voyait des
 *     résidences "Arts visuels" en tête de liste). Diagnostic : l'ancien ratio
 *     "communes / disciplines de la ressource" traitait à tort les ressources
 *     généralistes ("Toutes disciplines", "Mobilité internationale"...) comme
 *     des ressources à 0 discipline (donc 0 pt, alors qu'elles devraient plutôt
 *     matcher tout le monde), ET ne bloquait jamais une ressource dont les
 *     disciplines sont clairement incompatibles avec celles de l'artiste.
 *
 *     Nouvelles règles :
 *       - Artiste sans discipline renseignée → 0 pts (inchangé : impossible de juger).
 *       - Ressource sans discipline (généraliste, cf. DisciplineMapperService qui
 *         ne mappe volontairement pas ces libellés vers une Discipline) → score
 *         forfaitaire SCORE_DISCIPLINES_GENERALIST (= 20, la moitié du max) :
 *         ouverte à tous les artistes, mais moins ciblée qu'un match explicite.
 *       - Ressource ET artiste ont chacun au moins une discipline, mais AUCUNE en
 *         commun → EXCLUSION DURE (voir plus bas, hasDisciplineConflict()) :
 *         le score total de la ressource tombe à 0, tous critères confondus.
 *         C'est la cause n°1 des retours artistes ("ça ne correspond pas à mon
 *         profil") : une ressource "Arts visuels" ne doit JAMAIS remonter en
 *         tête de liste d'un musicien, même si le territoire ou le lookingFor
 *         matchent par ailleurs.
 *       - Sinon, on calcule un coefficient de RECOUVREMENT (Szymkiewicz–Simpson) :
 *           coverage = disciplines_communes / min(nb_disciplines_ressource, nb_disciplines_artiste)
 *         Pourquoi ce dénominateur plutôt que "disciplines de la ressource" (ancien
 *         calcul) ? L'ancien ratio pénalisait injustement les ressources multi-
 *         disciplines : une ressource ouverte à 8 disciplines dont "Musique" ne
 *         donnait que 5 pts à un musicien (1/8 × 40), alors qu'elle lui est
 *         PLEINEMENT ouverte. Le coefficient de recouvrement mesure plutôt à quel
 *         point le plus petit des deux ensembles est couvert par l'autre :
 *           - Ressource {Musique, Danse} / Artiste {Musique}        → 1/min(2,1)=1   → 40 pts
 *           - Ressource {8 disciplines dont Musique} / Artiste {Musique} → 1/min(8,1)=1 → 40 pts
 *           - Ressource {Musique, Arts visuels} / Artiste {Musique, Danse, Théâtre}
 *             → 1/min(2,3)=0.5 → 20 pts
 *         Score = round(coverage × 40).
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
 *     Comparaison insensible à la casse et aux accents, avec trim des espaces
 *     (normalizeText(), même principe que DisciplineMapperService::normalizeText).
 *
 *     ⚠️ RÉVISÉ (ADR-0035) : deux angles morts corrigés suite aux retours artistes.
 *       - ACCENTS : "Réunion" (ressource) ne matchait pas "La Reunion" (artiste,
 *         accent absent d'une saisie clavier) → on compare désormais des chaînes
 *         normalisées (minuscules, sans diacritiques).
 *       - OUTRE-MER : un artiste en Guadeloupe/Martinique/Guyane/Réunion/Mayotte/
 *         Saint-Martin/Saint-Barthélemy/Nouvelle-Calédonie/Polynésie ne matchait
 *         JAMAIS une ressource dont le pays est "France", car son texte de
 *         localisation ne contient pas le mot "france". Or ces territoires SONT
 *         la France. On ajoute donc un repli explicite : si le pays normalisé de
 *         la ressource est "france" et que la localisation de l'artiste contient
 *         un des territoires listés dans FRANCE_OVERSEAS_TERRITORIES, le bonus
 *         pays (+10 pts) est accordé. La réciproque (ressource au libellé "Guadeloupe"
 *         matchant un artiste en "France") n'est pas nécessaire : les ressources
 *         scrapées utilisent presque toujours "France" comme pays, jamais le nom
 *         du DROM spécifique.
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
 * ─── EXCLUSION DURE PAR CONFLIT DE DISCIPLINES (ADR-0035) ───────────────────
 *
 * Avant même de calculer les 4 composantes, scoreResource() vérifie s'il y a un
 * CONFLIT DE DISCIPLINES : la ressource ET l'artiste ont chacun des disciplines
 * renseignées, mais ne partagent RIEN. Dans ce cas, le score total est forcé à 0
 * et le breakdown entier (les 4 clés) est mis à 0 — on ne calcule même pas le
 * lookingFor, le territoire ou l'expérience.
 *
 * Pourquoi une exclusion aussi radicale, plutôt que de laisser les autres critères
 * s'exprimer ? Parce que c'est exactement ce que les artistes remontaient : une
 * ressource "Arts visuels" à Paris matchant un musicien parisien cumulait 30 pts
 * (lookingFor) + 20 pts (territoire) = 50 pts, et apparaissait en tête de liste
 * alors qu'elle ne le concerne pas du tout. La discipline est un critère
 * ÉLIMINATOIRE dès qu'elle est renseignée des deux côtés et incompatible — ce
 * n'est plus juste "un critère parmi d'autres" dans ce cas précis.
 *
 * Ce comportement ne s'applique PAS si l'un des deux (ressource ou artiste) n'a
 * pas de discipline renseignée : une ressource généraliste ou un artiste qui n'a
 * pas encore rempli ce champ ne sont jamais exclus par ce mécanisme.
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

    /**
     * Score forfaitaire attribué à une ressource "généraliste" (sans discipline
     * mappée) vis-à-vis d'un artiste qui, lui, a bien renseigné ses disciplines.
     *
     * ADR-0035 : la moitié du poids max des disciplines (40 / 2 = 20). Une
     * ressource généraliste ("Toutes disciplines", "Mobilité internationale"...)
     * est ouverte à tous les artistes, mais c'est un signal moins fort/ciblé
     * qu'une discipline explicitement en commun (qui, elle, vaut jusqu'à 40 pts).
     */
    private const int SCORE_DISCIPLINES_GENERALIST = 20;

    /**
     * Territoires français d'outre-mer (DROM-COM) reconnus pour le bonus
     * "territoire" quand la ressource a pour pays "France" (ADR-0035).
     *
     * CONTEXTE : les ressources scrapées indiquent presque toujours "France"
     * comme pays, jamais le nom du DROM-COM spécifique. Sans ce repli, un
     * artiste basé en Guadeloupe/Martinique/Guyane/Réunion/Mayotte... ne
     * matchait JAMAIS le bonus pays d'une ressource nationale, alors que la
     * cible principale de Bazaart est justement la diaspora afro-atlantique
     * (très présente dans ces territoires).
     *
     * Valeurs déjà normalisées (minuscules, sans accents, tirets conservés)
     * pour être comparées directement au résultat de normalizeText().
     *
     * @var string[]
     */
    private const array FRANCE_OVERSEAS_TERRITORIES = [
        'guadeloupe',
        'martinique',
        'guyane',
        'reunion',
        'mayotte',
        'saint-martin',
        'saint-barthelemy',
        'nouvelle-caledonie',
        'polynesie',
    ];

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
        // ── Exclusion dure (ADR-0035) ────────────────────────────────────────
        // Si la ressource et l'artiste ont chacun des disciplines renseignées
        // mais ne partagent RIEN, on ne calcule PAS les autres composantes :
        // le score et le breakdown entier tombent à 0. Voir le grand commentaire
        // de classe ("EXCLUSION DURE PAR CONFLIT DE DISCIPLINES") pour le pourquoi.
        if ($this->hasDisciplineConflict($resource, $artistProfile)) {
            return new MatchResult(
                resource: $resource,
                score: 0,
                breakdown: [
                    'disciplines' => 0,
                    'looking_for' => 0,
                    'territory'   => 0,
                    'experience'  => 0,
                ],
            );
        }

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
     * RÉVISÉ ADR-0035 (voir le grand commentaire de classe pour le contexte complet).
     *
     * Règles, dans l'ordre :
     *   1. Artiste sans discipline renseignée → 0 pts (on ne peut rien évaluer).
     *   2. Ressource sans discipline (généraliste) → SCORE_DISCIPLINES_GENERALIST (20 pts).
     *      Rappel : DisciplineMapperService ne mappe volontairement PAS des libellés
     *      comme "Toutes disciplines" vers une Discipline — ces ressources arrivent
     *      donc ici avec une collection de disciplines vide alors qu'elles sont en
     *      réalité ouvertes à tout le monde.
     *   3. Sinon, coefficient de recouvrement de Szymkiewicz–Simpson :
     *        coverage = disciplines_communes / min(nb_disciplines_ressource, nb_disciplines_artiste)
     *      Score = round(coverage × 40).
     *      NOTE : le cas "0 discipline commune alors que les deux collections sont
     *      non vides" est un CONFLIT DE DISCIPLINES, déjà intercepté en amont par
     *      scoreResource() via hasDisciplineConflict() (le score total tombe à 0
     *      avant même d'arriver ici). Cette méthode reste néanmoins cohérente
     *      seule (coverage = 0 → 0 pt) si jamais elle est appelée directement.
     *
     * Exemples :
     *   - Ressource : {Musique, Danse}      Artiste : {Musique}              → 1/min(2,1)=1   → 40 pts
     *   - Ressource : {8 disciplines dont Musique} Artiste : {Musique}       → 1/min(8,1)=1   → 40 pts
     *   - Ressource : {Musique, Arts visuels} Artiste : {Musique, Danse, Théâtre} → 1/min(2,3)=0.5 → 20 pts
     *   - Ressource sans discipline           Artiste : {Musique}           → généraliste     → 20 pts
     *   - Ressource : {Musique}               Artiste sans discipline        → 0 pts
     *
     * @return int Score entre 0 et SCORE_DISCIPLINES (= 40)
     */
    private function scoreDisciplines(Resource $resource, ArtistProfile $artistProfile): int
    {
        $resourceDisciplines = $resource->getDisciplines();
        $artistDisciplines   = $artistProfile->getDisciplines();

        // Cas trivial : si l'artiste n'a aucune discipline renseignée, impossible
        // de juger une correspondance → 0 pts (inchangé par rapport à l'ancien comportement).
        if ($artistDisciplines->isEmpty()) {
            return 0;
        }

        // Ressource généraliste (aucune discipline mappée) : score forfaitaire.
        // Voir ADR-0035 — c'est le coeur du correctif "ressources généralistes = 0 pt".
        if ($resourceDisciplines->isEmpty()) {
            return self::SCORE_DISCIPLINES_GENERALIST;
        }

        $commonCount = $this->countCommonDisciplines($resource, $artistProfile);

        // Coefficient de recouvrement (Szymkiewicz–Simpson) : on divise par le plus
        // PETIT des deux ensembles plutôt que par celui de la ressource (ancien calcul).
        // Cela évite de pénaliser une ressource multi-disciplines qui, pour un
        // artiste donné, lui est en réalité pleinement ouverte (cf. exemples ci-dessus).
        $smallestSetSize = min($resourceDisciplines->count(), $artistDisciplines->count());

        // Garde-fou défensif (ne devrait jamais arriver ici : les deux collections
        // sont non vides à ce stade) mais évite toute division par zéro si jamais
        // count() renvoyait 0 dans un contexte inattendu.
        if ($smallestSetSize === 0) {
            return 0;
        }

        $coverage = $commonCount / $smallestSetSize;

        return (int) round($coverage * self::SCORE_DISCIPLINES);
    }

    /**
     * Détecte un CONFLIT DE DISCIPLINES entre une ressource et un artiste (ADR-0035).
     *
     * Il y a conflit quand les DEUX ont des disciplines renseignées, mais ne
     * partagent RIEN. C'est le signal le plus fort qu'une ressource ne concerne
     * PAS l'artiste (ex : ressource "Arts visuels" pour un musicien), quel que
     * soit le reste du score (lookingFor, territoire...).
     *
     * Volontairement PAS de conflit si l'un des deux est vide :
     *   - Ressource généraliste (sans discipline) → jamais exclue, cf. scoreDisciplines().
     *   - Artiste sans discipline renseignée → profil incomplet, pas de jugement possible.
     *
     * @return bool true si la ressource doit être exclue (score total forcé à 0)
     */
    private function hasDisciplineConflict(Resource $resource, ArtistProfile $artistProfile): bool
    {
        $resourceDisciplines = $resource->getDisciplines();
        $artistDisciplines   = $artistProfile->getDisciplines();

        if ($resourceDisciplines->isEmpty() || $artistDisciplines->isEmpty()) {
            return false;
        }

        return $this->countCommonDisciplines($resource, $artistProfile) === 0;
    }

    /**
     * Compte le nombre de disciplines partagées entre une ressource et un artiste.
     *
     * Factorisé car utilisé à la fois par scoreDisciplines() (calcul du coefficient
     * de recouvrement) et hasDisciplineConflict() (détection d'exclusion dure).
     */
    private function countCommonDisciplines(Resource $resource, ArtistProfile $artistProfile): int
    {
        // Extrait les IDs des disciplines de l'artiste dans un ensemble pour une
        // recherche en O(1) (plutôt qu'un double boucle O(N×M)).
        $artistDisciplineIds = [];
        foreach ($artistProfile->getDisciplines() as $discipline) {
            // getId() peut être null si l'entité n'est pas encore persistée (cas de test),
            // mais en production les disciplines existent toujours en BDD.
            $id = $discipline->getId();
            if ($id !== null) {
                $artistDisciplineIds[$id] = true; // tableau associatif pour lookup O(1)
            }
        }

        // Compte les disciplines communes
        $commonCount = 0;
        foreach ($resource->getDisciplines() as $discipline) {
            $id = $discipline->getId();
            if ($id !== null && isset($artistDisciplineIds[$id])) {
                $commonCount++;
            }
        }

        return $commonCount;
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
     *   Les faux négatifs (artiste à Paris mais location = "Île-de-France") restent
     *   acceptables pour la V1 — une normalisation plus fine serait une amélioration V2.
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

        // Normalisation ADR-0035 : minuscules + trim + suppression des accents.
        // Corrige le cas "Réunion" (ressource) vs "La Reunion" (artiste, sans accent).
        $artistLocationNorm = $this->normalizeText($artistLocation);

        $score = 0;

        // ── Bonus pays (+10 pts) ─────────────────────────────────────────────
        if (!empty($resourceCountry)) {
            $countryNorm = $this->normalizeText($resourceCountry);

            // str_contains vérifie si le pays de la ressource apparaît dans la localisation
            // de l'artiste. Ex: "France" dans "Paris, France" → true.
            if (str_contains($artistLocationNorm, $countryNorm)) {
                $score += self::SCORE_TERRITORY / 2; // = 10 pts
            } elseif ($countryNorm === 'france' && $this->artistLocationMatchesOverseasTerritory($artistLocationNorm)) {
                // ADR-0035 : repli DROM-COM. La ressource est en "France" mais l'artiste
                // a écrit un territoire d'outre-mer (ex: "Pointe-à-Pitre, Guadeloupe") sans
                // le mot "France" — cela reste géographiquement la France. Voir le grand
                // commentaire de classe et FRANCE_OVERSEAS_TERRITORIES pour le détail.
                $score += self::SCORE_TERRITORY / 2; // = 10 pts
            }
        }

        // ── Bonus ville (+10 pts supplémentaires) ────────────────────────────
        if (!empty($resourceCity)) {
            $cityNorm = $this->normalizeText($resourceCity);
            if (str_contains($artistLocationNorm, $cityNorm)) {
                $score += self::SCORE_TERRITORY / 2; // = 10 pts de plus
            }
        }

        return (int) $score;
    }

    /**
     * Vérifie si la localisation (déjà normalisée) de l'artiste mentionne un
     * territoire français d'outre-mer (DROM-COM) — ADR-0035.
     *
     * @param string $normalizedLocation Localisation de l'artiste, déjà passée par normalizeText()
     */
    private function artistLocationMatchesOverseasTerritory(string $normalizedLocation): bool
    {
        foreach (self::FRANCE_OVERSEAS_TERRITORIES as $territory) {
            if (str_contains($normalizedLocation, $territory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise un texte pour une comparaison insensible à la casse ET aux accents.
     *
     * Même principe que DisciplineMapperService::normalizeText() (non réutilisable
     * ici car privée à ce service) : minuscules, décomposition Unicode NFD puis
     * suppression des diacritiques (accents, trémas...). Les tirets sont conservés
     * volontairement (utile pour "saint-martin", "nouvelle-caledonie"...).
     *
     * Repli en cascade si l'extension intl (\Normalizer) est indisponible :
     *   1. \Normalizer::normalize() (résultat le plus fiable, cohérent avec le
     *      reste du projet).
     *   2. iconv() en mode translittération ASCII (approximatif mais suffisant
     *      pour la comparaison de villes/pays/territoires).
     *   3. Simple mise en minuscules, sans suppression d'accents (dernier repli,
     *      ne casse rien mais peut rater un match si les accents diffèrent).
     */
    private function normalizeText(string $text): string
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($lower, \Normalizer::FORM_D);

            if ($decomposed !== false) {
                // \p{Mn} = diacritiques Unicode (Mark, non-spacing) : accents, trémas...
                $withoutDiacritics = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;

                return preg_replace('/\s+/', ' ', trim($withoutDiacritics)) ?? trim($withoutDiacritics);
            }
        }

        // Repli : extension intl absente → translittération approximative via iconv.
        // "//TRANSLIT//IGNORE" convertit au mieux les caractères accentués vers leur
        // équivalent ASCII et ignore silencieusement ce qui ne peut pas être converti.
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);

        if ($transliterated !== false) {
            return preg_replace('/\s+/', ' ', trim($transliterated)) ?? trim($transliterated);
        }

        // Dernier repli : ni intl, ni iconv disponibles → minuscules sans normalisation.
        return preg_replace('/\s+/', ' ', $lower) ?? $lower;
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

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\Matching\MatchResult;
use App\Entity\ArtistProfile;
use App\Entity\Discipline;
use App\Entity\Resource;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Enum\ExperienceLevel;
use App\Enum\ResourceStatus;
use App\Repository\ResourceRepository;
use App\Service\MatchingService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * MatchingServiceTest — Tests unitaires du moteur de scoring (ADR-0021, Lot B).
 *
 * Stratégie de test :
 *   Ces tests sont UNITAIRES : on n'accède PAS à la base de données.
 *   On utilise des mocks PHPUnit pour isoler MatchingService de ses dépendances.
 *
 * Dépendances mockées :
 *   - ResourceRepository : on lui fait retourner des tableaux de Resource construits en mémoire.
 *
 * Ce qu'on teste :
 *   1. Scoring "disciplines communes" : ratio correct, cas limites (0 discipline, pas de commun)
 *   2. Scoring "lookingFor" : concordance type ressource → objectif artiste
 *   3. Scoring "territoire" : bonus pays, bonus ville, ressource sans lieu (neutre)
 *   4. Filtrage dur : deadline passée exclue du repository (testée via stub du repository)
 *   5. Scoring "experience" : toujours 0 en V1 (champ manquant sur ArtistProfile)
 *   6. Tri : résultats classés du meilleur au moins bon
 *   7. Cas profil incomplet : liste vide retournée sans exception
 *
 * CONVENTION :
 *   Les méthodes de test suivent le format :
 *     test<Ce_qu_on_teste>_<Condition>_<Résultat_attendu>()
 *
 * ─── HELPERS PRIVÉS ──────────────────────────────────────────────────────────
 *
 * makeResource(), makeDiscipline(), makeArtistProfile(), makeUser() sont des helpers
 * qui construisent des entités en mémoire (sans BDD) avec les propriétés nécessaires.
 * On utilise la réflexion PHP (ReflectionClass) pour injecter des valeurs dans les
 * propriétés privées sans setter (car les entités Doctrine ne sont pas designed pour
 * être instanciées hors contexte ORM).
 */
class MatchingServiceTest extends TestCase
{
    // Le service à tester — instancié dans chaque test via makeService()
    private MatchingService $service;

    /**
     * Stub du ResourceRepository — utilisé pour la plupart des tests.
     *
     * POURQUOI createStub() et non createMock() ?
     *   PHPUnit 12 émet une Notice si un mock n'a aucune expectation (expects()).
     *   createStub() crée un "bouchon" sans notion d'expectation → pas de Notice.
     *   On n'utilise createMock() que dans les tests qui vérifient explicitement
     *   le nombre d'appels (ex: expects($this->never())).
     *
     * @var Stub&ResourceRepository
     */
    private Stub $repositoryStub;

    protected function setUp(): void
    {
        // createStub() : double sans attentes — parfait pour "retourner une valeur fixe"
        // sans vérifier le nombre ou l'ordre des appels.
        $this->repositoryStub = $this->createStub(ResourceRepository::class);

        // Instancie le service avec le stub
        $this->service = new MatchingService($this->repositoryStub);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU SCORING "DISCIPLINES COMMUNES" (max 40 pts)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas : disciplines communes = 100% → score disciplines = 40 pts (max).
     * L'artiste et la ressource partagent exactement les mêmes disciplines.
     */
    public function testScoreResource_ToutesDisciplinesCommunes_ScoreDisciplinesMaximal(): void
    {
        // Prépare 2 disciplines identiques côté artiste et côté ressource
        $musique = $this->makeDiscipline(1, 'Musique');
        $danse   = $this->makeDiscipline(2, 'Danse');

        $type    = $this->makeResourceType('Formation');
        $resource = $this->makeResource($type, [$musique, $danse]);

        $user    = $this->makeUser(['formations'], null); // lookingFor = formations
        $profile = $this->makeArtistProfile($user, [$musique, $danse], 'Paris, France');

        // Le repository retournera cette unique ressource
        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertCount(1, $results);
        $result = $results[0];

        // Disciplines : 2/2 × 40 = 40 pts
        $this->assertSame(40, $result->breakdown['disciplines'],
            "Toutes les disciplines communes → score disciplines = 40 pts"
        );
    }

    /**
     * Cas : disciplines communes = 50% → score disciplines = 20 pts.
     * La ressource a 2 disciplines, l'artiste en partage 1.
     */
    public function testScoreResource_MoitieDisciplinesCommunes_ScoreDisciplinesPartiel(): void
    {
        $musique = $this->makeDiscipline(1, 'Musique');
        $danse   = $this->makeDiscipline(2, 'Danse');

        $type     = $this->makeResourceType('Appel à projets');
        // Ressource : Musique + Danse
        $resource = $this->makeResource($type, [$musique, $danse]);

        // Artiste : seulement Musique (partage 1/2 avec la ressource)
        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [$musique], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);
        $result  = $results[0];

        // 1/2 × 40 = 20 pts
        $this->assertSame(20, $result->breakdown['disciplines'],
            "1 discipline commune sur 2 → score disciplines = 20 pts"
        );
    }

    /**
     * Cas : aucune discipline commune → score disciplines = 0 pts.
     */
    public function testScoreResource_AucuneDisciplineCommune_ScoreDisciplinesNul(): void
    {
        $musique = $this->makeDiscipline(1, 'Musique');
        $theatre = $this->makeDiscipline(3, 'Théâtre');

        $type     = $this->makeResourceType('Résidence artistique');
        $resource = $this->makeResource($type, [$musique]);

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [$theatre], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['disciplines'],
            "Aucune discipline commune → score disciplines = 0"
        );
    }

    /**
     * Cas : ressource sans discipline → score disciplines = 0 pts (cas limite).
     */
    public function testScoreResource_RessourceSansDiscipline_ScoreDisciplinesNul(): void
    {
        $musique  = $this->makeDiscipline(1, 'Musique');
        $type     = $this->makeResourceType('Formation');
        $resource = $this->makeResource($type, []); // aucune discipline sur la ressource

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [$musique], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['disciplines'],
            "Ressource sans discipline → score disciplines = 0 (pas de pénalité)"
        );
    }

    /**
     * Cas : artiste sans discipline → score disciplines = 0 pts.
     */
    public function testScoreResource_ArtisteSansDiscipline_ScoreDisciplinesNul(): void
    {
        $musique  = $this->makeDiscipline(1, 'Musique');
        $type     = $this->makeResourceType('Formation');
        $resource = $this->makeResource($type, [$musique]);

        // Artiste sans aucune discipline
        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['disciplines'],
            "Artiste sans discipline → score disciplines = 0"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU SCORING "LOOKING_FOR" (max 30 pts)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas : l'artiste cherche des formations + type de ressource = "Formation" → 30 pts.
     */
    public function testScoreResource_LookingForFormations_TypeFormation_ScoreMax(): void
    {
        $type     = $this->makeResourceType('Formation'); // contient "formation"
        $resource = $this->makeResource($type, []);

        // L'artiste cherche des formations
        $user    = $this->makeUser(['formations'], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(30, $results[0]->breakdown['looking_for'],
            "Artiste cherche formations + type Formation → score looking_for = 30"
        );
    }

    /**
     * Cas : l'artiste cherche des aides financières + type = "Bourse & Financement" → 30 pts.
     */
    public function testScoreResource_LookingForAides_TypeBourse_ScoreMax(): void
    {
        $type     = $this->makeResourceType('Bourse & Financement'); // contient "bourse"
        $resource = $this->makeResource($type, []);

        $user    = $this->makeUser(['ressources_aides'], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(30, $results[0]->breakdown['looking_for'],
            "Artiste cherche aides financières + type Bourse → score looking_for = 30"
        );
    }

    /**
     * Cas : l'artiste cherche des formations mais le type de ressource est une résidence → 0 pts.
     * Les objectifs ne concordent pas.
     */
    public function testScoreResource_LookingForFormations_TypeResidence_ScoreNul(): void
    {
        $type     = $this->makeResourceType('Résidence artistique'); // ne contient pas "formation"
        $resource = $this->makeResource($type, []);

        $user    = $this->makeUser(['formations'], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['looking_for'],
            "Artiste cherche formations mais type Résidence → score looking_for = 0"
        );
    }

    /**
     * Cas : artiste sans lookingFor → score looking_for = 0 (critère non applicable).
     */
    public function testScoreResource_ArtisteSansLookingFor_ScoreLookingForNul(): void
    {
        $type     = $this->makeResourceType('Formation');
        $resource = $this->makeResource($type, []);

        $user    = $this->makeUser(null, null); // pas de lookingFor
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['looking_for'],
            "Artiste sans lookingFor → score looking_for = 0 (critère non applicable)"
        );
    }

    /**
     * Cas : l'artiste cherche des appels/concours + type = "Prix & concours" → 30 pts.
     *
     * Vérifie le mapping corrigé : "prix" appartient à RESSOURCES_APPELS.
     * Un "Prix & concours" est sémantiquement un appel à candidature/compétition,
     * pas une aide financière directe (bourse, subvention...).
     */
    public function testScoreResource_LookingForAppels_TypePrixConcours_ScoreMax(): void
    {
        // Type "Prix & concours" contient "prix" → doit matcher RESSOURCES_APPELS
        $type     = $this->makeResourceType('Prix & concours');
        $resource = $this->makeResource($type, []);

        $user    = $this->makeUser(['ressources_appels'], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(30, $results[0]->breakdown['looking_for'],
            "Artiste cherche appels/concours + type 'Prix & concours' → score looking_for = 30"
        );
    }

    /**
     * Cas : l'artiste cherche des AIDES FINANCIÈRES + type = "Prix & concours" → 0 pts.
     *
     * Vérifie que "prix" n'est PLUS dans RESSOURCES_AIDES depuis la correction sémantique.
     * Avant la correction, ce test aurait retourné 30 pts (faux positif).
     */
    public function testScoreResource_LookingForAides_TypePrixConcours_ScoreNul(): void
    {
        // Type "Prix & concours" ne doit PAS matcher RESSOURCES_AIDES
        $type     = $this->makeResourceType('Prix & concours');
        $resource = $this->makeResource($type, []);

        $user    = $this->makeUser(['ressources_aides'], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['looking_for'],
            "Artiste cherche aides financières + type 'Prix & concours' → score looking_for = 0 "
            . "(un prix est un concours/appel, pas une aide financière directe)"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU SCORING "TERRITOIRE" (max 20 pts)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas : ressource sans lieu (city ET country null) → 0 pts (neutre, pas pénalisant).
     * Cette règle est explicite dans l'ADR-0021.
     */
    public function testScoreResource_RessourceSansLieu_TerritoireNeutre(): void
    {
        $type     = $this->makeResourceType('Résidence artistique');
        $resource = $this->makeResource($type, [], city: null, country: null);

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], 'Paris, France');

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['territory'],
            "Ressource sans lieu géographique → territoire neutre = 0 pts (pas pénalisant)"
        );
    }

    /**
     * Cas : ressource avec pays France, artiste à Paris France → bonus pays + bonus ville = 20 pts.
     */
    public function testScoreResource_MemeVilleMemesPays_TerritoireMaximal(): void
    {
        $type     = $this->makeResourceType('Formation');
        $resource = $this->makeResource($type, [], city: 'Paris', country: 'France');

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], 'Paris, France');

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(20, $results[0]->breakdown['territory'],
            "Même ville et même pays → score territoire = 20 pts (max)"
        );
    }

    /**
     * Cas : ressource en France, artiste à Paris France, mais ville = Lyon → +10 pays, +0 ville.
     */
    public function testScoreResource_MemePaysVilleDifferente_TerritoirePartiel(): void
    {
        $type     = $this->makeResourceType('Formation');
        $resource = $this->makeResource($type, [], city: 'Lyon', country: 'France');

        // L'artiste est à Paris, France → contient "france" mais pas "lyon"
        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], 'Paris, France');

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(10, $results[0]->breakdown['territory'],
            "Même pays mais ville différente → score territoire = 10 pts"
        );
    }

    /**
     * Cas : ressource au Sénégal, artiste à Paris France → 0 pts (pays différents).
     */
    public function testScoreResource_PaysDifferent_TerritoireNul(): void
    {
        $type     = $this->makeResourceType('Résidence artistique');
        $resource = $this->makeResource($type, [], city: 'Dakar', country: 'Sénégal');

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], 'Paris, France');

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['territory'],
            "Pays différents → score territoire = 0"
        );
    }

    /**
     * Cas : artiste sans localisation → score territoire = 0 (critère non applicable).
     */
    public function testScoreResource_ArtisteSansLocalisation_TerritoireNul(): void
    {
        $type     = $this->makeResourceType('Formation');
        $resource = $this->makeResource($type, [], city: 'Paris', country: 'France');

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], null); // pas de localisation

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['territory'],
            "Artiste sans localisation → score territoire = 0 (critère non applicable)"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU SCORING "EXPERIENCE" (max 10 pts — toujours 0 en V1)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas V1 : même si la ressource précise un niveau, score expérience = 0
     * (ArtistProfile n'a pas encore de champ experienceLevel).
     */
    public function testScoreResource_RessourceAvecNiveauV1_ExperienceNul(): void
    {
        $type     = $this->makeResourceType('Formation');
        // Ressource avec niveau "Débutant" requis
        $resource = $this->makeResource($type, [], experienceLevel: ExperienceLevel::DEBUTANT);

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['experience'],
            "En V1, score expérience toujours 0 (champ absent sur ArtistProfile)"
        );
    }

    /**
     * Cas : ressource sans niveau → score expérience = 0 (tous niveaux acceptés).
     */
    public function testScoreResource_RessourceSansNiveau_ExperienceNul(): void
    {
        $type     = $this->makeResourceType('Bourse & Financement');
        $resource = $this->makeResource($type, [], experienceLevel: null); // tous niveaux

        $user    = $this->makeUser([], null);
        $profile = $this->makeArtistProfile($user, [], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame(0, $results[0]->breakdown['experience'],
            "Ressource sans niveau requis → score expérience = 0 (tous niveaux acceptés)"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU FILTRAGE DUR (deadline passée exclue)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas : le repository exclut déjà les ressources à deadline passée.
     * Ce test vérifie que MatchingService retourne [] quand le repository retourne [].
     *
     * NOTE : La logique de filtrage deadline est testée dans ResourceRepositoryTest
     * (tests d'intégration avec BDD). Ici on teste uniquement que MatchingService
     * délègue bien au repository et ne réintroduit pas de ressources expirées.
     */
    public function testGetMatchesForUser_RepositoireVideRetourneListeVide(): void
    {
        // Simule : toutes les ressources sont expirées ou aucune n'est publiée
        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([]); // repository vide

        $user    = $this->makeUser(['formations'], null);
        $profile = $this->makeArtistProfile($user, [], 'Paris');

        $results = $this->service->getMatchesForUser($user);

        $this->assertSame([], $results,
            "Si le repository retourne [], MatchingService retourne aussi []"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU PROFIL INCOMPLET
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas : l'utilisateur n'a pas de profil artiste (ArtistProfile null) → liste vide.
     * MatchingService ne doit PAS lever d'exception dans ce cas.
     */
    public function testGetMatchesForUser_SansProfilArtiste_ListeVide(): void
    {
        // User sans profil artiste
        $user = $this->makeUserWithoutProfile();

        // On crée un MOCK (pas un stub) pour ce test afin de vérifier
        // que findPublishedForMatching n'est PAS appelé quand le profil est absent.
        // createMock() permet d'utiliser expects() (attentes sur le nombre d'appels).
        $mockRepo = $this->createMock(ResourceRepository::class);
        $mockRepo
            ->expects($this->never()) // vérifie qu'on n'appelle pas findPublishedForMatching
            ->method('findPublishedForMatching');

        // On crée un service local avec ce mock dédié (pas le stub du setUp)
        $serviceLocal = new MatchingService($mockRepo);
        $results = $serviceLocal->getMatchesForUser($user);

        $this->assertSame([], $results,
            "Sans profil artiste, getMatchesForUser() retourne [] sans exception"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU TRI (meilleur match en premier)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas : deux ressources avec des scores différents → triées du meilleur au moins bon.
     */
    public function testGetMatchesForUser_PlusieursRessources_TriDecroissant(): void
    {
        $musique   = $this->makeDiscipline(1, 'Musique');
        $formation = $this->makeResourceType('Formation');
        $residence = $this->makeResourceType('Résidence artistique');

        // Ressource A : discipline Musique + type Formation
        $resourceA = $this->makeResource($formation, [$musique], id: 1);
        // Ressource B : pas de discipline commune + type Résidence (pas de match lookingFor)
        $resourceB = $this->makeResource($residence, [], id: 2);

        // Artiste cherche des formations et a Musique comme discipline
        $user    = $this->makeUser(['formations'], null);
        $profile = $this->makeArtistProfile($user, [$musique], null);

        $this->repositoryStub
            ->method('findPublishedForMatching')
            // Le repository retourne B avant A, mais le tri inverse l'ordre
            ->willReturn([$resourceB, $resourceA]);

        $results = $this->service->getMatchesForUser($user);

        $this->assertCount(2, $results);
        // La ressource A (score plus élevé) doit être en premier
        $this->assertSame($resourceA, $results[0]->resource,
            "La ressource avec le score le plus élevé doit être en tête"
        );
        $this->assertSame($resourceB, $results[1]->resource,
            "La ressource avec le score le plus faible doit être en fin"
        );
        // Vérifie que le score de A > score de B
        $this->assertGreaterThan($results[1]->score, $results[0]->score,
            "Le premier résultat doit avoir un score strictement supérieur au second"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DU SCORE TOTAL ET DES POIDS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cas : score total ne dépasse jamais la somme des poids max (= 100).
     */
    public function testScoreResource_ScoreTotalMaximum_NeverExceedsHundred(): void
    {
        $musique   = $this->makeDiscipline(1, 'Musique');
        $formation = $this->makeResourceType('Formation');
        // Ressource parfaitement correspondante : même discipline, même type, même lieu
        $resource  = $this->makeResource($formation, [$musique], city: 'Paris', country: 'France');

        $user    = $this->makeUser(['formations'], null);
        $profile = $this->makeArtistProfile($user, [$musique], 'Paris, France');

        $this->repositoryStub
            ->method('findPublishedForMatching')
            ->willReturn([$resource]);

        $results = $this->service->getMatchesForUser($user);
        $result  = $results[0];

        // Le score total = somme du breakdown
        $sumBreakdown = array_sum($result->breakdown);
        $this->assertSame($result->score, $sumBreakdown,
            "Le score total doit être égal à la somme du breakdown"
        );

        // Le score total ne doit jamais dépasser 100
        $this->assertLessThanOrEqual(100, $result->score,
            "Le score total ne doit jamais dépasser 100"
        );
    }

    /**
     * Cas : getScoreWeights() retourne des poids cohérents avec les constantes privées.
     */
    public function testGetScoreWeights_RetourneLesQuatreComposantes(): void
    {
        $weights = $this->service->getScoreWeights();

        $this->assertArrayHasKey('disciplines', $weights);
        $this->assertArrayHasKey('looking_for', $weights);
        $this->assertArrayHasKey('territory',   $weights);
        $this->assertArrayHasKey('experience',  $weights);

        // La somme des poids max doit être 100
        $this->assertSame(100, array_sum($weights),
            "La somme des poids max doit être exactement 100"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS — Constructeurs d'entités en mémoire
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Construit une Discipline en mémoire avec un ID et un nom donnés.
     *
     * On utilise ReflectionClass pour injecter l'ID privé sans setter
     * (les entités Doctrine n'ont pas de setId() par convention).
     */
    private function makeDiscipline(int $id, string $name): Discipline
    {
        $discipline = new Discipline();
        $discipline->setName($name);
        // Injection de l'ID via réflexion (simuler un objet persisté en BDD)
        $ref = new \ReflectionClass($discipline);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($discipline, $id);
        return $discipline;
    }

    /**
     * Construit un ResourceType en mémoire avec un nom donné.
     */
    private function makeResourceType(string $name): ResourceType
    {
        $type = new ResourceType();
        $type->setName($name);
        return $type;
    }

    /**
     * Construit une Resource en mémoire avec le type, les disciplines,
     * et des paramètres de localisation et d'expérience optionnels.
     *
     * @param Discipline[]          $disciplines       Disciplines à associer
     * @param string|null           $city              Ville de l'opportunité (null = pas de lieu)
     * @param string|null           $country           Pays de l'opportunité (null = pas de lieu)
     * @param ExperienceLevel|null  $experienceLevel   Niveau requis (null = tous niveaux)
     * @param int                   $id                ID simulé (pour les tests de tri)
     */
    private function makeResource(
        ResourceType $type,
        array $disciplines,
        ?string $city = null,
        ?string $country = null,
        ?ExperienceLevel $experienceLevel = null,
        int $id = 1,
    ): Resource {
        // On crée un user minimal pour satisfaire le NOT NULL sur submittedBy
        $submittedBy = new User();
        $this->setPrivateProperty($submittedBy, 'email', 'test@bazaart.fr');
        $this->setPrivateProperty($submittedBy, 'password', 'hash');
        $this->setPrivateProperty($submittedBy, 'roles', []);

        $resource = new Resource();
        $resource->setTitle('Ressource de test');
        $resource->setDescription('Description test');
        $resource->setResourceType($type);
        $resource->setSubmittedBy($submittedBy);
        $resource->setStatus(ResourceStatus::Published);
        $resource->setCity($city);
        $resource->setCountry($country);
        $resource->setExperienceLevel($experienceLevel);

        // Injection de l'ID via réflexion (simuler objet persisté)
        $this->setPrivateProperty($resource, 'id', $id);
        // Injection des timestamps obligatoires (NOT NULL en BDD) via réflexion
        // (sans eux, getCreatedAt()/getUpdatedAt() lanceraient une notice PHP "uninitialized")
        $this->setPrivateProperty($resource, 'createdAt', new \DateTime());
        $this->setPrivateProperty($resource, 'updatedAt', new \DateTime());

        // Ajout des disciplines (via le setter public qui initialise la collection)
        foreach ($disciplines as $discipline) {
            $resource->addDiscipline($discipline);
        }

        return $resource;
    }

    /**
     * Construit un User en mémoire avec lookingFor et lookingForOther optionnels.
     * Le profil artiste sera attaché à ce user par makeArtistProfile().
     *
     * @param string[]|null $lookingFor       Tableau de valeurs ArtistLookingFor::value, ou null
     * @param string|null   $lookingForOther  Texte libre "autre" si AUTRE coché, ou null
     */
    private function makeUser(?array $lookingFor, ?string $lookingForOther): User
    {
        $user = new User();
        $this->setPrivateProperty($user, 'email', 'artiste@test.fr');
        $this->setPrivateProperty($user, 'password', 'hashed_password');
        $this->setPrivateProperty($user, 'roles', ['ROLE_ARTIST']);
        $user->setLookingFor($lookingFor);
        $user->setLookingForOther($lookingForOther);
        return $user;
    }

    /**
     * Construit un User sans profil artiste (ArtistProfile = null).
     * Utilisé pour tester le cas "profil incomplet → liste vide".
     */
    private function makeUserWithoutProfile(): User
    {
        $user = new User();
        $this->setPrivateProperty($user, 'email', 'noartist@test.fr');
        $this->setPrivateProperty($user, 'password', 'hash');
        $this->setPrivateProperty($user, 'roles', ['ROLE_USER']);
        // artistProfile reste null (valeur par défaut de l'entité User)
        return $user;
    }

    /**
     * Construit un ArtistProfile en mémoire et l'attache à l'utilisateur donné.
     *
     * @param User          $user        L'utilisateur propriétaire du profil
     * @param Discipline[]  $disciplines Les disciplines artistiques de l'artiste
     * @param string|null   $location    Localisation libre (ex: "Paris, France") ou null
     */
    private function makeArtistProfile(User $user, array $disciplines, ?string $location): ArtistProfile
    {
        $profile = new ArtistProfile();
        $profile->setUser($user);
        $profile->setDisplayName('Artiste Test');
        $profile->setLocation($location);

        // Injection des timestamps via réflexion (colonnes NOT NULL)
        $this->setPrivateProperty($profile, 'createdAt', new \DateTime());
        $this->setPrivateProperty($profile, 'updatedAt', new \DateTime());

        foreach ($disciplines as $discipline) {
            $profile->addDiscipline($discipline);
        }

        // Attache le profil à l'utilisateur pour que getArtistProfile() retourne ce profil
        $this->setPrivateProperty($user, 'artistProfile', $profile);

        return $profile;
    }

    /**
     * Helper générique pour injecter une valeur dans une propriété privée via ReflectionClass.
     *
     * Pourquoi cela est-il nécessaire ?
     *   Les entités Doctrine ont des propriétés privées sans setter (ex: $id, $createdAt,
     *   $artistProfile sur User). Ces propriétés sont normalement remplies par Doctrine
     *   lors du chargement depuis la BDD. Dans les tests unitaires (sans BDD), on doit
     *   les remplir manuellement via la réflexion PHP.
     *
     * setAccessible(true) : lève la restriction d'accès private/protected.
     * Cette API est stable en PHP 8.x.
     */
    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $ref = new \ReflectionClass($object);

        // On cherche la propriété dans la classe ET ses parents (pour les héritages)
        // car certaines propriétés peuvent être définies dans une classe parente.
        while (!$ref->hasProperty($propertyName) && $ref->getParentClass() !== false) {
            $ref = $ref->getParentClass();
        }

        if (!$ref->hasProperty($propertyName)) {
            throw new \InvalidArgumentException(sprintf(
                "La propriété '%s' n'existe pas dans la classe %s ni ses parents.",
                $propertyName,
                get_class($object)
            ));
        }

        $prop = $ref->getProperty($propertyName);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}

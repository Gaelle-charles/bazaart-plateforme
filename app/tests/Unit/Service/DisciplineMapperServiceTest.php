<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Discipline;
use App\Repository\DisciplineRepository;
use App\Service\DisciplineMapperService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * DisciplineMapperServiceTest — Tests unitaires de DisciplineMapperService.
 *
 * ── POURQUOI CES TESTS ? ─────────────────────────────────────────────────────
 * DisciplineMapperService est critique : il traduit les libellés libres renvoyés
 * par le LLM en entités Discipline stockées en BDD. Une régression dans le
 * matching silencerait des disciplines entières (aucune erreur levée, juste
 * des champs vides). Ces tests servent de garde-fous sur les 4 chemins de
 * matching (exact → synonyme → sous-chaîne → non mappé) et sur les invariants
 * (pas de doublon, pas d'exception sur libellé inconnu, liste vide → []).
 *
 * ── STRATÉGIE DE TEST ────────────────────────────────────────────────────────
 * Tests unitaires purs : on ne touche pas à la BDD.
 * DisciplineRepository est mocké via PHPUnit\MockObject pour retourner un jeu
 * de disciplines fixe, indépendamment de l'état réel de la BDD.
 * On utilise NullLogger (implémentation PSR-3 qui absorbe tout) pour éviter
 * de mocker le logger — les logs ne font pas partie du comportement testé.
 *
 * ── CAS COUVERTS ─────────────────────────────────────────────────────────────
 *   1. Libellé exact (normalisation casse/accents)         → Discipline trouvée
 *   2. Synonyme déclaré dans SYNONYMS                      → Discipline trouvée
 *   3. Sous-chaîne bidirectionnelle (libellé long)         → Discipline trouvée
 *   4. Libellé invalide / inconnu                          → ignoré, pas d'exception
 *   5. Doublon : deux libellés mappés sur la même Discipline → un seul résultat
 *   6. Liste vide en entrée                                → []
 *   7. Libellé court (< 4 chars) ne déclenche pas la sous-chaîne → pas de faux positif
 *
 * Classe testée : App\Service\DisciplineMapperService::mapLabelsToEntities()
 * Type de test  : Unitaire (aucun Kernel Symfony, aucune BDD)
 */
class DisciplineMapperServiceTest extends TestCase
{
    /**
     * Stub du repository — remplace la requête BDD par un retour fixe en mémoire.
     * Déclaré en propriété de classe pour être accessible dans tous les tests.
     *
     * On utilise un stub (createStub) plutôt qu'un mock (createMock) car on n'a
     * pas besoin de vérifier le nombre d'appels — on veut juste contrôler la valeur
     * de retour de findAllOrdered(). createStub() émet moins de notices PHPUnit 12.
     *
     * @var DisciplineRepository&\PHPUnit\Framework\MockObject\Stub
     */
    private DisciplineRepository $repoMock;

    /**
     * Instance du service testée, alimentée par le mock.
     */
    private DisciplineMapperService $service;

    /**
     * Jeu de disciplines simulant la BDD (8 disciplines V1 du projet).
     * Ce tableau est construit dans setUp() et réutilisé dans chaque test.
     *
     * @var Discipline[]
     */
    private array $fixtureDisciplines;

    /**
     * setUp() — Initialisation avant chaque test.
     *
     * On construit ici un jeu de 8 disciplines correspondant aux fixtures V1
     * du projet (cf. DisciplineMapperService::class PHPDoc).
     * Chaque Discipline est instanciée avec setName() — on ne peut pas passer
     * l'id directement car la propriété est privée (gérée par Doctrine),
     * mais on n'en a pas besoin pour tester mapLabelsToEntities() sauf
     * pour la déduplication (qui utilise getId()). Pour la déduplication,
     * on utilise la réflexion PHP pour forcer un id.
     */
    protected function setUp(): void
    {
        // ── Construction des entités Discipline fixes ─────────────────────────
        // On crée 8 Discipline, une par domaine artistique V1.
        // setId() n'existe pas → on utilise la réflexion PHP pour forcer l'id,
        // ce qui est standard dans les tests unitaires Doctrine.
        $this->fixtureDisciplines = $this->buildFixtureDisciplines([
            1 => 'Musique',
            2 => 'Cinéma & Audiovisuel',
            3 => 'Arts visuels',
            4 => 'Danse',
            5 => 'Théâtre & Performance',
            6 => 'Littérature',
            7 => 'Arts numériques',
            8 => 'Mode & Design',
        ]);

        // ── Stub du repository ────────────────────────────────────────────────
        // findAllOrdered() est la seule méthode appelée par le service.
        // On utilise createStub() (PHPUnit 12) plutôt que createMock() :
        //   createStub  → pas de vérification du nombre d'appels → moins de notices
        //   createMock  → vérifie les expectations → utile pour "cette méthode doit
        //                 être appelée exactement N fois" (pas notre cas ici)
        $stub = $this->createStub(DisciplineRepository::class);
        $stub->method('findAllOrdered')->willReturn($this->fixtureDisciplines);
        $this->repoMock = $stub;

        // ── Instanciation du service ──────────────────────────────────────────
        // NullLogger absorbe tous les appels logger->debug() / warning() sans effet.
        // C'est la pratique standard pour les tests unitaires où les logs
        // ne font pas partie du comportement observable.
        $this->service = new DisciplineMapperService($this->repoMock, new NullLogger());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — Libellé exact (normalisation casse + accents)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 1 — Libellé exact "Musique" → Musique trouvée.
     *
     * Vérifie la normalisation casse et la correspondance directe (étape 2).
     * Cas le plus simple : le LLM retourne exactement le nom canonique BDD.
     */
    public function testMapLabelsToEntities_LibelleExact_MusiqueTrouvee(): void
    {
        // Le LLM retourne "Musique" — même casse que la BDD
        $result = $this->service->mapLabelsToEntities(['Musique']);

        $this->assertCount(1, $result, 'Doit trouver exactement 1 discipline');
        $this->assertSame('Musique', $result[0]->getName(), 'La discipline trouvée doit être Musique');
    }

    /**
     * Test 1b — Libellé exact en minuscules → Musique trouvée (normalisation).
     *
     * Vérifie que la normalisation en minuscules fonctionne :
     * "musique" (tout en minuscules) doit matcher la BDD "Musique".
     */
    public function testMapLabelsToEntities_LibelleExactMinuscules_MusiqueTrouvee(): void
    {
        // Le LLM peut retourner "musique" au lieu de "Musique"
        $result = $this->service->mapLabelsToEntities(['musique']);

        $this->assertCount(1, $result);
        $this->assertSame('Musique', $result[0]->getName());
    }

    /**
     * Test 1c — Libellé avec accents retirés par le LLM → Théâtre & Performance trouvée.
     *
     * Vérifie la normalisation des accents : "theatre" → "Théâtre & Performance".
     * Le LLM omet parfois les accents.
     */
    public function testMapLabelsToEntities_LibelleSansAccent_TheatreTrouve(): void
    {
        // "theatre" sans accent doit matcher via SYNONYMS → "Théâtre & Performance"
        $result = $this->service->mapLabelsToEntities(['theatre']);

        $this->assertCount(1, $result);
        $this->assertSame('Théâtre & Performance', $result[0]->getName());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — Correspondance par synonyme
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 2 — Synonyme "Arts plastiques" → Arts visuels (étape 3).
     *
     * "Arts plastiques" est un synonyme déclaré dans SYNONYMS qui mappe
     * vers "Arts visuels". Vérifie que la table de synonymes fonctionne.
     */
    public function testMapLabelsToEntities_Synonyme_ArtsPlastiquesVersArtsVisuels(): void
    {
        // "Arts plastiques" est un alias courant que le LLM utilise souvent
        // au lieu du nom canonique "Arts visuels"
        $result = $this->service->mapLabelsToEntities(['Arts plastiques']);

        $this->assertCount(1, $result, 'Le synonyme "Arts plastiques" doit mapper vers Arts visuels');
        $this->assertSame('Arts visuels', $result[0]->getName());
    }

    /**
     * Test 2b — Synonyme "documentaire" → Cinéma & Audiovisuel.
     *
     * Vérifie qu'un synonyme d'un seul mot fonctionne correctement.
     */
    public function testMapLabelsToEntities_Synonyme_DocumentaireVersCinema(): void
    {
        $result = $this->service->mapLabelsToEntities(['documentaire']);

        $this->assertCount(1, $result);
        $this->assertSame('Cinéma & Audiovisuel', $result[0]->getName());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — Correspondance par sous-chaîne
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 3 — Sous-chaîne "Arts visuels contemporains" → Arts visuels (étape 4).
     *
     * Comportement réel de l'étape 4 (sous-chaîne bidirectionnelle) :
     *   On compare le libellé normalisé LLM avec CHAQUE clé BDD normalisée.
     *   Si le libellé LLM CONTIENT la clé BDD → match trouvé.
     *   Exemple :
     *     libellé LLM normalisé = "arts visuels contemporains"
     *     clé BDD normalisée    = "arts visuels"
     *     str_contains("arts visuels contemporains", "arts visuels") = true → match !
     *
     * Note : le libellé doit avoir >= 4 chars pour activer l'étape 4 (guard).
     * "arts visuels contemporains" = 26 chars → guard non bloquant.
     *
     * Distinction avec SYNONYMS (étape 3) :
     *   SYNONYMS cherche le libellé ENTIER normalisé comme clé de dictionnaire.
     *   La sous-chaîne couvre les cas où le LLM ajoute un qualificatif ("contemporains")
     *   après le nom canonique, que SYNONYMS ne peut pas anticiper.
     */
    public function testMapLabelsToEntities_SousChaineLibelleLong_ArtsVisuelsTrouve(): void
    {
        // Libellé composé : le LLM ajoute "contemporains" au nom canonique BDD.
        // Ni exact (pas "Arts visuels" seul), ni dans SYNONYMS ("arts visuels contemporains"
        // n'y est pas), mais la sous-chaîne "arts visuels" est bien contenue dans le libellé.
        $result = $this->service->mapLabelsToEntities(['Arts visuels contemporains']);

        $this->assertCount(1, $result, '"Arts visuels contemporains" doit matcher via sous-chaîne → Arts visuels');
        $this->assertSame('Arts visuels', $result[0]->getName());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — Libellé invalide ignoré sans exception
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 4 — Libellé invalide → ignoré, retourne [], pas d'exception.
     *
     * Le LLM peut retourner n'importe quoi. Le service doit rester silencieux
     * (pas d'exception) et retourner [] quand rien ne matche.
     * Un log debug est émis mais on ne le vérifie pas ici (NullLogger).
     */
    public function testMapLabelsToEntities_LibelleInvalide_RetourneVide(): void
    {
        // Libellé complètement inventé par le LLM
        $result = $this->service->mapLabelsToEntities(['gastronomie', 'sport', 'marketing']);

        // Aucune discipline ne doit être retournée, aucune exception levée
        $this->assertSame([], $result, 'Les libellés inconnus doivent être ignorés sans exception');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — Déduplication : deux synonymes → une seule Discipline
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 5 — "Arts plastiques" + "Art visuel" → un seul résultat Arts visuels.
     *
     * Les deux libellés sont des synonymes de "Arts visuels" (via SYNONYMS).
     * Le service détecte le doublon par l'id de la Discipline et n'en retourne qu'une.
     * Vérifie que $seenIds fonctionne correctement.
     */
    public function testMapLabelsToEntities_Doublon_UnSeulResultat(): void
    {
        // "Arts plastiques" et "Art visuel" mappent tous les deux vers Arts visuels
        $result = $this->service->mapLabelsToEntities(['Arts plastiques', 'Art visuel']);

        $this->assertCount(
            1,
            $result,
            '"Arts plastiques" et "Art visuel" mappent sur la même Discipline → 1 seul résultat attendu'
        );
        $this->assertSame('Arts visuels', $result[0]->getName());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6 — Liste vide en entrée → []
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 6 — Liste vide → retourne [] immédiatement.
     *
     * Guard en début de mapLabelsToEntities() : si le tableau d'entrée est vide,
     * on retourne [] sans même appeler le cache BDD.
     * Vérifie que le service gère ce cas trivial sans exception.
     */
    public function testMapLabelsToEntities_ListeVide_RetourneVide(): void
    {
        $result = $this->service->mapLabelsToEntities([]);

        $this->assertSame([], $result, 'Liste vide en entrée → [] attendu');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 7 — Guard longueur : libellé court ne déclenche pas la sous-chaîne
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 7a — Libellé 3 chars "art" ne provoque pas de faux positif (guard >= 4).
     *
     * Correctif ADR-0016 Lot 1 (correctif 2) :
     *   Sans le guard, "art" (3 chars) matcherait "arts visuels" ou "arts numeriques"
     *   via str_contains($dbKey, "art") → faux positif arbitraire.
     *   Le guard mb_strlen($normalized) >= 4 doit bloquer ce cas.
     *
     * "art" ne figure pas dans SYNONYMS et ne matche pas en exact → doit retourner [].
     */
    public function testMapLabelsToEntities_LibelleCourtTroisChars_PasDeFauxPositif(): void
    {
        // "art" est trop court pour la sous-chaîne (guard < 4 chars),
        // et n'est pas dans SYNONYMS → doit retourner [] (pas de match arbitraire)
        $result = $this->service->mapLabelsToEntities(['art']);

        $this->assertSame(
            [],
            $result,
            '"art" (3 chars) ne doit PAS matcher via sous-chaîne (guard longueur >= 4). '
            . 'Sans ce guard, il matcherait arbitrairement Arts visuels ou Arts numériques.'
        );
    }

    /**
     * Test 7b — Libellé 4 chars "danse" matche bien (guard >= 4 non bloquant).
     *
     * Vérifie que le guard n'est pas trop restrictif : "dans" (4 chars)
     * activera la sous-chaîne bidirectionnelle.
     * "danse" est dans SYNONYMS, mais ce test utilise un libellé exact aussi.
     * On teste ici que 4 chars exactement activent bien l'étape 4 si nécessaire.
     */
    public function testMapLabelsToEntities_LibelleQuatreChars_GuardNonBloquant(): void
    {
        // "dans" (4 chars normalisés) — la BDD contient "danse" → "dans" ⊂ "danse"
        // (str_contains("danse", "dans") = true). Guard = 4 chars → actif.
        $result = $this->service->mapLabelsToEntities(['dans']);

        // "dans" (4 chars) peut matcher "danse" via sous-chaîne (danse contient dans)
        // Ce comportement est voulu : 4 chars est la limite minimale d'ambiguïté acceptable.
        $this->assertCount(1, $result, '"dans" (4 chars) doit matcher via sous-chaîne → Danse');
        $this->assertSame('Danse', $result[0]->getName());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 8 — Plusieurs libellés valides en même temps
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 8 — ["Musique", "Danse", "Littérature"] → 3 disciplines retournées.
     *
     * Vérifie le comportement normal multi-disciplines : 3 libellés valides
     * doivent produire 3 entités distinctes dans le même appel.
     */
    public function testMapLabelsToEntities_PlusieursLibellesValides_RetourneTroisDisciplines(): void
    {
        $result = $this->service->mapLabelsToEntities(['Musique', 'Danse', 'Littérature']);

        $this->assertCount(3, $result, 'Trois libellés valides → trois disciplines retournées');

        // Vérification des noms retournés (l'ordre dépend de l'itération du cache,
        // on vérifie donc par contenu plutôt que par position)
        $names = array_map(fn (Discipline $d) => $d->getName(), $result);
        $this->assertContains('Musique', $names);
        $this->assertContains('Danse', $names);
        $this->assertContains('Littérature', $names);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privés
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construit un tableau d'entités Discipline avec les ids forcés par réflexion.
     *
     * ── Pourquoi la réflexion ici ? ──────────────────────────────────────────
     * L'entité Discipline::$id est privée et gérée par Doctrine (GeneratedValue).
     * Il n'y a pas de setId() dans l'entité (bonne pratique Doctrine).
     * Pour les tests unitaires qui ont besoin d'ids stables (test déduplication),
     * on utilise la réflexion PHP pour accéder directement à la propriété privée.
     * C'est une technique standard dans les tests unitaires Doctrine sans BDD.
     *
     * @param array<int, string> $idToName Tableau [id => nom]
     * @return Discipline[]
     */
    private function buildFixtureDisciplines(array $idToName): array
    {
        $disciplines = [];

        foreach ($idToName as $id => $name) {
            $discipline = new Discipline();
            $discipline->setName($name);

            // Force l'id via réflexion (propriété privée, pas de setId() en Doctrine).
            // PHP 8.1+ : setAccessible() est devenu no-op (les propriétés privées sont
            // accessibles via ReflectionProperty sans appel préalable à setAccessible).
            // On l'omet donc ici pour éviter une notice PHPUnit 12 de dépréciation.
            $reflectionClass    = new \ReflectionClass(Discipline::class);
            $reflectionProperty = $reflectionClass->getProperty('id');
            $reflectionProperty->setValue($discipline, $id);

            $disciplines[] = $discipline;
        }

        return $disciplines;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\AssociationProfile;
use App\Entity\Discipline;
use App\Entity\Resource;
use App\Entity\ResourceType;
use App\Enum\BazaartAssociation;
use App\Service\Project\AssociationOpportunityMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tri des opportunités selon les fiches de BazaArt Guadeloupe et BazaArt Paris (ADR-0038).
 */
class AssociationOpportunityMatcherTest extends TestCase
{
    private AssociationOpportunityMatcher $matcher;

    /** @var array<string, AssociationProfile> */
    private array $profiles;

    protected function setUp(): void
    {
        $this->matcher  = new AssociationOpportunityMatcher();
        // Fiches neuves : pré-remplies avec le territoire et les thèmes par défaut.
        $this->profiles = [
            'guadeloupe' => new AssociationProfile(BazaartAssociation::Guadeloupe),
            'paris'      => new AssociationProfile(BazaartAssociation::Paris),
        ];
    }

    public function testGuadeloupeAidForAssociationsMatchesGuadeloupeOnly(): void
    {
        $resource = $this->resource('Aide aux projets culturels 2027', 'La Région Guadeloupe soutient les associations culturelles du territoire.', 'Subvention', city: 'Basse-Terre', country: 'France');

        $guadeloupe = $this->evaluate($resource, 'guadeloupe');
        $paris      = $this->evaluate($resource, 'paris');

        self::assertTrue($guadeloupe->matches);
        self::assertContains('Notre territoire', $guadeloupe->reasons);
        self::assertContains('Ouverte aux associations / structures', $guadeloupe->reasons);
        self::assertFalse($paris->matches);
        self::assertNotNull($paris->excludedBecause);
    }

    public function testNationalCallForStructuresMatchesBothAssociations(): void
    {
        $resource = $this->resource('Appel à projets « Cultures du monde »', 'Ouvert aux associations loi 1901 et collectifs portant un projet artistique.', 'Appel à projets', country: 'France');

        foreach ($this->matcher->evaluate($resource, $this->profiles) as $match) {
            self::assertTrue($match->matches, $match->association->label());
            self::assertContains('Appel national (France)', $match->reasons);
        }
    }

    public function testIndividualOnlyOpportunityIsExcluded(): void
    {
        $resource = $this->resource('Bourse de création à Paris', 'Bourse réservée aux artistes individuels : les associations ne sont pas éligibles.', 'Bourse', city: 'Paris');

        self::assertSame('Réservée aux personnes physiques', $this->evaluate($resource, 'paris')->excludedBecause);
    }

    public function testThemesFromTheProfileRaiseTheScore(): void
    {
        $resource = $this->resource('Fonds pour les structures', 'Projets d\'éducation artistique pour la jeunesse.', 'Fonds');
        $before   = $this->evaluate($resource, 'paris')->score;

        $this->profiles['paris']->setThemeKeywords('');
        self::assertLessThan($before, $this->evaluate($resource, 'paris')->score);
    }

    public function testExcludedKeywordAndUnsoughtTypeHideTheOpportunity(): void
    {
        $resource = $this->resource('Aide aux associations', 'Réservé aux doctorants.', 'Subvention');
        $this->profiles['paris']->setExcludedKeywords('Doctorants');
        self::assertStringContainsString('doctorants', (string) $this->evaluate($resource, 'paris')->excludedBecause);

        $training = $this->resource('Formation pour les associations', 'Gestion associative.', 'Formation');
        self::assertSame('Type non recherché (Formation)', $this->evaluate($training, 'paris')->excludedBecause);
        $this->profiles['paris']->setSoughtTypes(['aides', 'formations']);
        self::assertNull($this->evaluate($training, 'paris')->excludedBecause);
    }

    public function testDisciplineConflictExcludesOnlyWhenBothSidesHaveDisciplines(): void
    {
        $music = $this->discipline(1, 'Musique');
        $dance = $this->discipline(2, 'Danse');
        $resource = $this->resource('Aide aux compagnies', 'Pour les structures.', 'Aide')->addDiscipline($dance);

        // Fiche sans discipline = pluridisciplinaire : pas d'exclusion.
        self::assertNull($this->evaluate($resource, 'paris')->excludedBecause);

        $this->profiles['paris']->replaceDisciplines([$music]);
        self::assertSame('Aucune discipline en commun', $this->evaluate($resource, 'paris')->excludedBecause);

        $this->profiles['paris']->replaceDisciplines([$music, $dance]);
        self::assertContains('Discipline : Danse', $this->evaluate($resource, 'paris')->reasons);
    }

    public function testTerritoryKeywordsAreWholeWordsWithoutAccents(): void
    {
        $resource = $this->resource('Fonds pour les structures', 'Une comparaison des dispositifs.', 'Fonds');
        self::assertNotContains('Notre territoire', $this->evaluate($resource, 'paris')->reasons);

        $resource = $this->resource('Fonds pour les structures', 'Réservé aux acteurs d\'ÎLE-DE-FRANCE.', 'Fonds');
        self::assertContains('Notre territoire', $this->evaluate($resource, 'paris')->reasons);
        // …et donc pas pour la Guadeloupe (appel non national).
        self::assertFalse($this->evaluate($resource, 'guadeloupe')->matches);
    }

    private function evaluate(Resource $resource, string $key): \App\DTO\Project\AssociationMatch
    {
        return $this->matcher->evaluateFor($resource, $this->profiles[$key], $this->profiles);
    }

    private function resource(string $title, string $description, string $type, ?string $city = null, ?string $country = null): Resource
    {
        return (new Resource())
            ->setTitle($title)
            ->setDescription($description)
            ->setResourceType((new ResourceType())->setName($type))
            ->setCity($city)
            ->setCountry($country);
    }

    private function discipline(int $id, string $name): Discipline
    {
        $discipline = (new Discipline())->setName($name);
        // L'ID est normalement attribué par la base : on le fixe par réflexion pour le test.
        (new \ReflectionProperty(Discipline::class, 'id'))->setValue($discipline, $id);

        return $discipline;
    }
}

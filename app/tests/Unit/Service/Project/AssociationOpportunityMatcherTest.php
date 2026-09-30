<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Resource;
use App\Entity\ResourceType;
use App\Enum\BazaartAssociation;
use App\Service\Project\AssociationOpportunityMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tri automatique des opportunités pour BazaArt Guadeloupe et BazaArt Paris (ADR-0038).
 */
class AssociationOpportunityMatcherTest extends TestCase
{
    private AssociationOpportunityMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new AssociationOpportunityMatcher();
    }

    public function testGuadeloupeAidForAssociationsMatchesGuadeloupeOnly(): void
    {
        $resource = $this->resource(
            'Aide aux projets culturels 2027',
            'La Région Guadeloupe soutient les associations culturelles du territoire.',
            'Subvention',
            city: 'Basse-Terre',
            country: 'France',
        );

        $guadeloupe = $this->matcher->evaluateFor($resource, BazaartAssociation::Guadeloupe);
        $paris      = $this->matcher->evaluateFor($resource, BazaartAssociation::Paris);

        self::assertTrue($guadeloupe->matches);
        self::assertContains('Territoire : Guadeloupe', $guadeloupe->reasons);
        self::assertContains('Ouverte aux associations / structures', $guadeloupe->reasons);
        // Ville hors Île-de-France et Paris jamais citée : pas pour BazaArt Paris.
        self::assertFalse($paris->matches);
        self::assertNotNull($paris->excludedBecause);
    }

    public function testNationalCallForStructuresMatchesBothAssociations(): void
    {
        $resource = $this->resource(
            'Appel à projets « Cultures du monde »',
            'Ouvert aux associations loi 1901 et collectifs portant un projet artistique.',
            'Appel à projets',
            country: 'France',
        );

        foreach ($this->matcher->evaluate($resource) as $match) {
            self::assertTrue($match->matches, $match->association->label());
            self::assertContains('Appel national (France)', $match->reasons);
        }
    }

    public function testIndividualOnlyOpportunityIsExcluded(): void
    {
        $resource = $this->resource(
            'Bourse de création à Paris',
            'Bourse réservée aux artistes individuels : les associations ne sont pas éligibles.',
            'Bourse',
            city: 'Paris',
        );

        $paris = $this->matcher->evaluateFor($resource, BazaartAssociation::Paris);
        self::assertFalse($paris->matches);
        self::assertSame('Réservée aux personnes physiques', $paris->excludedBecause);
    }

    public function testArtistTrainingInParisWithoutStructureSignalIsNotShown(): void
    {
        // Territoire seul (35 pts) sans signal « structure » ni type financement : sous le seuil.
        $resource = $this->resource('Atelier d\'écriture', 'Un atelier pour les artistes émergents à Paris.', 'Formation', city: 'Paris');

        self::assertFalse($this->matcher->evaluateFor($resource, BazaartAssociation::Paris)->matches);
    }

    public function testKeywordsAreMatchedAsWholeWordsWithoutAccents(): void
    {
        // « comparaison » ne doit pas être lu comme « paris » ; « Île-de-France » sans accent = « ile-de-france ».
        $resource = $this->resource('Fonds pour les structures', 'Une comparaison des dispositifs.', 'Fonds');
        self::assertNotContains('Territoire : Paris / Île-de-France', $this->matcher->evaluateFor($resource, BazaartAssociation::Paris)->reasons);

        $resource = $this->resource('Fonds pour les structures', 'Réservé aux acteurs d\'ÎLE-DE-FRANCE.', 'Fonds');
        self::assertContains('Territoire : Paris / Île-de-France', $this->matcher->evaluateFor($resource, BazaartAssociation::Paris)->reasons);
    }

    public function testOtherAssociationTerritoryOnlyExcludesUnlessNational(): void
    {
        $idf = $this->resource('Fonds pour les structures', 'Réservé aux acteurs d\'Île-de-France.', 'Fonds');
        self::assertFalse($this->matcher->evaluateFor($idf, BazaartAssociation::Guadeloupe)->matches);

        $national = $this->resource('Fonds pour les structures', 'Appel national, jury réuni à Paris.', 'Fonds');
        self::assertTrue($this->matcher->evaluateFor($national, BazaartAssociation::Guadeloupe)->matches);
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
}

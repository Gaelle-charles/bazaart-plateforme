<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AssociationProject;

use App\DTO\AssociationProject\AssociationProjectData;
use App\Service\AssociationProject\PublicAccessToken;
use App\Service\AssociationProject\SlugGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests unitaires de la page « Projets en cours » (ADR-0039) :
 * génération des slugs, jeton secret, validation du formulaire de fiche.
 */
class SlugAndTokenTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function titles(): iterable
    {
        // Les 9 fiches de départ : mêmes slugs que dans la migration Version20261013020000.
        yield 'point médian'      => ['Zagaza · Maison Verveau', 'zagaza-maison-verveau'];
        yield 'accents, apostrophe' => ["Coopération Guadeloupe-Côte d'Ivoire", 'cooperation-guadeloupe-cote-d-ivoire'];
        yield 'accent grave'      => ['An Ba Lanmè, chapitre 2', 'an-ba-lanme-chapitre-2'];
        yield 'minuscules'        => ['masonn · Bolivie', 'masonn-bolivie'];
        yield 'simple'            => ['Formations', 'formations'];
        yield 'points'            => ['Plateforme app.bazaart.fr', 'plateforme-app-bazaart-fr'];
        yield 'accent aigu'       => ['Média BazaArt', 'media-bazaart'];
        yield 'sigle'             => ['Groupes de travail ICC Guadeloupe', 'groupes-de-travail-icc-guadeloupe'];
        yield 'deux-points'       => ['Tiers-lieu, phase 1 : équipement', 'tiers-lieu-phase-1-equipement'];
        // Cas limites
        yield 'ligature'          => ['Œuvre « Kréyòl »', 'oeuvre-kreyol'];
        yield 'que des symboles'  => ['***', 'projet'];
    }

    #[DataProvider('titles')]
    public function testSlugify(string $title, string $expected): void
    {
        self::assertSame($expected, SlugGenerator::slugify($title));
    }

    public function testSlugIsTruncatedWithoutTrailingDash(): void
    {
        $slug = SlugGenerator::slugify(str_repeat('mot ', 100));

        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    public function testEmptyTokenNeverMatches(): void
    {
        $token = new PublicAccessToken('');

        self::assertFalse($token->isEnabled());
        self::assertFalse($token->matches(''), 'un jeton vide ne doit JAMAIS ouvrir la page');
        self::assertFalse($token->matches('nimportequoi'));
    }

    public function testTokenMatchesExactlyOnly(): void
    {
        $token = new PublicAccessToken(str_repeat('a1', 32));

        self::assertTrue($token->isEnabled());
        self::assertTrue($token->matches(str_repeat('a1', 32)));
        self::assertFalse($token->matches(str_repeat('a1', 31)));
        self::assertFalse($token->matches(strtoupper(str_repeat('a1', 32))));
        self::assertFalse($token->isTooShort());
        self::assertTrue((new PublicAccessToken('court'))->isTooShort());
    }

    public function testDescriptionLimitCountsLineBreaksOnce(): void
    {
        // 600 caractères dont un retour à la ligne envoyé en « \r\n » par le navigateur : accepté.
        $data = AssociationProjectData::fromRequest($this->post(['description' => str_repeat('a', 598) . "\r\nb"]));
        self::assertSame([], $data->validate());
        self::assertSame(600, mb_strlen((string) $data->description));

        // 601 caractères : refusé, avec le nombre exact.
        $data = AssociationProjectData::fromRequest($this->post(['description' => str_repeat('a', 599) . "\r\nb"]));
        self::assertSame(['La description fait 601 caractères : 600 maximum.'], $data->validate());
    }

    public function testLeadNameRejectsEmailAndTitleIsRequired(): void
    {
        $data = AssociationProjectData::fromRequest($this->post(['leadName' => 'prenom@exemple.fr']));
        self::assertContains('Référente : indique seulement le prénom et le nom, sans adresse e-mail.', $data->validate());

        $data = AssociationProjectData::fromRequest($this->post(['title' => '  ']));
        self::assertContains('Le titre est obligatoire (entre 2 et 150 caractères).', $data->validate());
    }

    /**
     * Requête POST de formulaire valide, complétée par $fields.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields): Request
    {
        return new Request([], array_merge([
            'title' => 'Projet test',
            'type'  => 'residency',
            'state' => 'idea',
        ], $fields));
    }
}

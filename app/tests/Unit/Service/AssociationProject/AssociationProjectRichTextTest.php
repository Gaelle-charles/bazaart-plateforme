<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AssociationProject;

use App\Service\AssociationProject\AssociationProjectRichText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du texte enrichi « Le projet en détail » (ADR-0039) :
 * liste blanche, images, liens, cas d'attaque connus.
 */
#[CoversClass(AssociationProjectRichText::class)]
class AssociationProjectRichTextTest extends TestCase
{
    private const IMAGE = '0123456789abcdef0123456789abcdef.jpg';

    private AssociationProjectRichText $richText;

    protected function setUp(): void
    {
        $this->richText = new AssociationProjectRichText();
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function cleanings(): iterable
    {
        yield 'mise en forme gardée' => [
            '<h2>Intertitre</h2><p>Un <strong>mot</strong> en <em>italique</em>.</p><ul><li>un</li></ul><ol><li>deux</li></ol><blockquote><p>Citation</p></blockquote>',
            '<h2>Intertitre</h2><p>Un <strong>mot</strong> en <em>italique</em>.</p><ul><li>un</li></ul><ol><li>deux</li></ol><blockquote><p>Citation</p></blockquote>',
        ];
        yield 'accents intacts' => ['<p>Côte d’Ivoire, été, « guillemets »</p>', '<p>Côte d’Ivoire, été, « guillemets »</p>'];
        yield 'balises renommées' => [
            '<h1>A</h1><h4>B</h4><b>gras</b> <i>ital</i>',
            '<h2>A</h2><h3>B</h3><p><strong>gras</strong> <em>ital</em></p>',
        ];
        yield 'div de Google Docs → paragraphes' => ['<div>Un</div><div>Deux</div>', '<p>Un</p><p>Deux</p>'];
        yield 'div conteneur retiré' => ['<div><p>Un</p><p>Deux</p></div>', '<p>Un</p><p>Deux</p>'];
        yield 'span et style retirés, texte gardé' => [
            '<p style="color:red" class="x"><span style="font-weight:700">Texte</span></p>',
            '<p>Texte</p>',
        ];
        yield 'script supprimé avec son contenu' => ['<p>Avant</p><script>alert(1)</script><p>Après</p>', '<p>Avant</p><p>Après</p>'];
        yield 'iframe et style supprimés' => ['<iframe src="https://x"></iframe><style>p{}</style><p>Ok</p>', '<p>Ok</p>'];
        yield 'gestionnaire onclick retiré' => ['<p onclick="alert(1)">Clic</p>', '<p>Clic</p>'];
        yield 'attributs à préfixe retirés' => [
            '<p xml:onclick="x()" xml:base="https://evil.example" xmlns="http://www.w3.org/1999/xhtml" xmlns:x="urn:x" xml:lang="fr">Texte <a href="https://bazaart.fr" xml:base="javascript:alert(1)//">lien</a></p>',
            '<p>Texte <a href="https://bazaart.fr">lien</a></p>',
        ];
        yield 'commentaire retiré' => ['<p>A<!-- secret --></p>', '<p>A</p>'];
        yield 'paragraphes vides retirés' => ['<p>A</p><p><br></p><p>&nbsp;</p><p>B</p>', '<p>A</p><p>B</p>'];
        yield 'texte seul → paragraphe' => ['Juste du texte', '<p>Juste du texte</p>'];
        yield 'li orphelin → paragraphe' => ['<li>Seul</li>', '<p>Seul</p>'];
        yield 'rien de visible → null' => ['<p> </p><script>x</script>', null];
        yield 'vide → null' => ['', null];
        yield 'texte échappé' => ['<p>1 &lt; 2 &amp; &lt;script&gt;</p>', '<p>1 &lt; 2 &amp; &lt;script&gt;</p>'];
        // Google Docs met parfois un simple espace en italique ou en gras entre deux mots.
        yield 'espace en italique gardé' => ['<p>un<em> </em>deux</p>', '<p>un deux</p>'];
        yield 'espace insécable en gras gardé' => ['<p>un<strong>&nbsp;</strong>deux</p>', "<p>un\u{A0}deux</p>"];
        yield 'retour à la ligne en gras gardé' => ['<p>a<strong><br></strong>b</p>', '<p>a<br>b</p>'];
        yield 'lien vide déplié' => ['<p>a<a href="https://x.fr"> </a>b</p>', '<p>a b</p>'];
        yield '</div> de trop : la suite est gardée' => ['avant</div>après', '<p>avant</p><p>après</p>'];
        yield '</div> de trop puis script' => ['<p>a</p></div><script>alert(1)</script><p onclick="x()">b</p>', '<p>a</p><p>b</p>'];
        yield 'tableau collé : cellules séparées' => [
            '<table><tr><td>un</td><td>deux</td></tr><tr><td>trois</td></tr></table>',
            '<p>un deux <br>trois <br></p>',
        ];
    }

    #[DataProvider('cleanings')]
    public function testSanitize(string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->richText->sanitize($input));
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousLinks(): iterable
    {
        yield 'javascript:' => ['javascript:alert(1)'];
        yield 'majuscules et espaces' => ['  JaVaScRiPt:alert(1)'];
        yield 'entité HTML' => ['&#106;avascript:alert(1)'];
        yield 'data:' => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'adresse relative' => ['/admin/fiches-projets'];
        yield 'vide' => [''];
    }

    #[DataProvider('dangerousLinks')]
    public function testDangerousLinkKeepsTextOnly(string $href): void
    {
        self::assertSame('<p>Voir <strong>ici</strong></p>', $this->richText->sanitize('<p>Voir <a href="' . $href . '"><strong>ici</strong></a></p>'));
    }

    public function testSafeLinks(): void
    {
        self::assertSame(
            '<p><a href="https://bazaart.fr/a?b=1&amp;c=2">site</a> <a href="mailto:bonjourbazaart@gmail.com">écrire</a></p>',
            $this->richText->sanitize('<p><a href="https://bazaart.fr/a?b=1&amp;c=2" target="_self" onclick="x()">site</a> <a href="mailto:bonjourbazaart@gmail.com">écrire</a></p>'),
        );
    }

    public function testImageKeepsOnlyNameAltAndSize(): void
    {
        $html = '<p><img src="https://evil.example/pixel.gif" data-ap-image="' . self::IMAGE . '" alt=" Une   photo " width="1600" height="900" onerror="alert(1)" style="x"></p>';

        self::assertSame(
            '<p><img data-ap-image="' . self::IMAGE . '" alt="Une photo" width="1600" height="900"></p>',
            $this->richText->sanitize($html),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidImages(): iterable
    {
        yield 'adresse externe sans nom' => ['<img src="https://evil.example/pixel.gif">'];
        yield 'remontée de dossier' => ['<img data-ap-image="../../.env">'];
        yield 'mauvaise extension' => ['<img data-ap-image="0123456789abcdef0123456789abcdef.svg">'];
        yield 'nom trop court' => ['<img data-ap-image="abc.jpg">'];
    }

    #[DataProvider('invalidImages')]
    public function testInvalidImageRemoved(string $img): void
    {
        self::assertSame('<p>Texte</p>', $this->richText->sanitize('<p>Texte' . $img . '</p>'));
    }

    public function testImageAloneIsKept(): void
    {
        $html = '<p><img data-ap-image="' . self::IMAGE . '" alt=""></p>';

        self::assertSame($html, $this->richText->sanitize($html));
    }

    public function testImageNameWithTrailingNewlineIsRefused(): void
    {
        self::assertSame(0, preg_match(AssociationProjectRichText::IMAGE_NAME_PATTERN, self::IMAGE . "\n"));
        self::assertSame('<p>Texte</p>', $this->richText->sanitize('<p>Texte<img data-ap-image="' . self::IMAGE . "\n" . '"></p>'));
    }

    public function testBadDimensionsDropped(): void
    {
        self::assertSame(
            '<p><img data-ap-image="' . self::IMAGE . '" alt=""></p>',
            $this->richText->sanitize('<img data-ap-image="' . self::IMAGE . '" width="0" height="99999">'),
        );
    }

    public function testRenderAddsImageAddressAndOpensLinksInNewTab(): void
    {
        $stored = '<p><a href="https://bazaart.fr">site</a> <a href="mailto:a@b.fr">mail</a></p><p><img data-ap-image="' . self::IMAGE . '" alt="Photo"></p>';

        $html = $this->richText->render($stored, static fn (string $name): string => '/projets/jeton/fiche/texte/' . $name);

        self::assertSame(
            '<p><a href="https://bazaart.fr" target="_blank" rel="noopener noreferrer">site</a> <a href="mailto:a@b.fr">mail</a></p>'
            . '<p><img data-ap-image="' . self::IMAGE . '" alt="Photo" src="/projets/jeton/fiche/texte/' . self::IMAGE . '" loading="lazy" decoding="async"></p>',
            $html,
        );
    }

    public function testRenderCanShiftHeadings(): void
    {
        self::assertSame(
            '<h3>Intertitre</h3><h4>Sous-titre</h4><p>Texte</p>',
            $this->richText->render('<h2>Intertitre</h2><h3>Sous-titre</h3><p>Texte</p>', static fn (string $n): string => $n, shiftHeadings: true),
        );
    }

    public function testRenderCleansAgain(): void
    {
        // Même si la base contenait du HTML dangereux, l'affichage le retire.
        self::assertSame('<p>Ok</p>', $this->richText->render('<p>Ok</p><script>alert(1)</script>', static fn (string $n): string => $n));
    }

    public function testImageNames(): void
    {
        $other = 'fedcba9876543210fedcba9876543210.png';
        $html = '<p><img data-ap-image="' . self::IMAGE . '"></p><p><img data-ap-image="' . $other . '"><img data-ap-image="' . self::IMAGE . '"></p>';

        self::assertSame([self::IMAGE, $other], $this->richText->imageNames($html));
        self::assertSame([], $this->richText->imageNames(null));
    }

    public function testFilterImages(): void
    {
        $other = 'fedcba9876543210fedcba9876543210.png';
        $html = '<p><img data-ap-image="' . self::IMAGE . '" alt=""></p><p><img data-ap-image="' . $other . '" alt=""></p><p>Texte</p>';

        self::assertSame(
            '<p><img data-ap-image="' . self::IMAGE . '" alt=""></p><p>Texte</p>',
            $this->richText->filterImages($html, static fn (string $name): bool => $name === self::IMAGE),
        );
        self::assertNull($this->richText->filterImages('<p><img data-ap-image="' . $other . '"></p>', static fn (string $n): bool => false));
    }

    public function testTextLength(): void
    {
        self::assertSame(9, $this->richText->textLength('<h2>Été</h2><p><strong>2026</strong>-7</p>'));
        self::assertSame(0, $this->richText->textLength(null));
    }

    public function testNestingIsFlattened(): void
    {
        $html = str_repeat('<span>', 50) . 'fond' . str_repeat('</span>', 50);

        self::assertSame('<p>fond</p>', $this->richText->sanitize($html));
    }

    public function testAbsurdNestingDoesNotCrash(): void
    {
        // libxml arrête l'analyse au-delà de 256 niveaux : le texte peut être perdu,
        // mais rien ne plante et aucune balise interdite ne passe.
        $html = str_repeat('<span>', 5000) . 'fond' . str_repeat('</span>', 5000);

        self::assertStringNotContainsString('<span', (string) $this->richText->sanitize($html));
    }
}

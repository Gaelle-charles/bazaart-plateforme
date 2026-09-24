<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Service\Project\TokenCipher;
use App\Twig\ProjectTwigExtension;
use PHPUnit\Framework\TestCase;

/**
 * Chiffrement du jeton Drive et transformation des URL en liens (ADR-0037).
 */
class TokenCipherAndAutolinkTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher    = new TokenCipher('secret-A');
        $encrypted = $cipher->encrypt('1//refresh-token');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString('refresh-token', $encrypted);
        self::assertSame('1//refresh-token', $cipher->decrypt($encrypted));
        // Deux chiffrements du même texte diffèrent (nonce aléatoire)
        self::assertNotSame($encrypted, $cipher->encrypt('1//refresh-token'));
    }

    public function testTamperedOrForeignValuesAreRejected(): void
    {
        $encrypted = (new TokenCipher('secret-A'))->encrypt('jeton');

        self::assertNull((new TokenCipher('secret-B'))->decrypt($encrypted), 'Autre APP_SECRET : illisible');
        self::assertNull((new TokenCipher('secret-A'))->decrypt(substr($encrypted, 0, -2) . 'AA'), 'Valeur altérée : rejetée');
        self::assertNull((new TokenCipher('secret-A'))->decrypt('jeton-en-clair'));
    }

    public function testAutolinkOnlyWrapsHttpUrlsInEscapedText(): void
    {
        $extension = (new \ReflectionClass(ProjectTwigExtension::class))->newInstanceWithoutConstructor();

        // Twig a DÉJÀ échappé le texte (pre_escape) : on reçoit des entités HTML.
        $escaped = htmlspecialchars('Doc : https://drive.google.com/x?a=1&b=2. Et "https://ex.org" <b>gras</b> javascript:alert(1)', ENT_QUOTES);
        $html    = $extension->autolink($escaped);

        self::assertStringContainsString('<a href="https://drive.google.com/x?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">', $html);
        self::assertStringContainsString('</a>. Et', $html, 'Le point final reste hors du lien');
        self::assertStringContainsString('<a href="https://ex.org"', $html, 'Le guillemet fermant ne fait pas partie du lien');
        self::assertStringContainsString('&lt;b&gt;gras&lt;/b&gt;', $html, 'Le HTML saisi reste échappé');
        self::assertStringNotContainsString('href="javascript', $html);
    }
}

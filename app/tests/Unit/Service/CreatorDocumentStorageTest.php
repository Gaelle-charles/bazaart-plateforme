<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\CreatorDocumentStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * CreatorDocumentStorageTest — Tests unitaires du service de stockage sécurisé (ADR-0027).
 *
 * Ce service est le coeur de la sécurité des pièces d'identité :
 *   - Il génère des noms de fichiers aléatoires (non devinables).
 *   - Il valide les types MIME via finfo (pas l'extension déclarée).
 *   - Il protège contre la traversée de répertoire ("..").
 *   - Il stocke les fichiers HORS du dossier public.
 *
 * STRATÉGIE DE TEST :
 *   Tests unitaires — on utilise un dossier temporaire système (sys_get_temp_dir())
 *   à la place du vrai dossier sécurisé (var/secure_uploads/).
 *   Les UploadedFile sont créés à partir de fichiers temporaires réels.
 *   Pas de kernel Symfony, pas de BDD.
 */
#[CoversClass(CreatorDocumentStorage::class)]
class CreatorDocumentStorageTest extends TestCase
{
    /** Dossier temporaire utilisé comme "var/secure_uploads/" de test. */
    private string $tempDir;

    /** Instance du service sous test. */
    private CreatorDocumentStorage $storage;

    /**
     * setUp() est appelé avant chaque méthode de test.
     * On crée un dossier temporaire unique par test pour l'isolation.
     */
    protected function setUp(): void
    {
        // Crée un dossier temporaire unique dans le dossier system temp
        $this->tempDir = sys_get_temp_dir() . '/bazaart_test_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);

        // Instancie le service avec le dossier temp comme racine sécurisée
        $this->storage = new CreatorDocumentStorage($this->tempDir);
    }

    /**
     * tearDown() est appelé après chaque méthode de test.
     * On nettoie les fichiers temporaires créés pendant le test.
     */
    protected function tearDown(): void
    {
        // Supprime récursivement le dossier temporaire
        $this->deleteDirectory($this->tempDir);
    }

    // ─── Tests de getAbsolutePath() ──────────────────────────────────────────

    /**
     * Test : getAbsolutePath() retourne le bon chemin absolu.
     */
    public function testGetAbsolutePathReturnsCorrectPath(): void
    {
        $relativePath = 'creator-docs/user42_abc.pdf';
        $expected     = $this->tempDir . DIRECTORY_SEPARATOR . 'creator-docs/user42_abc.pdf';

        $this->assertSame($expected, $this->storage->getAbsolutePath($relativePath));
    }

    /**
     * Test : getAbsolutePath() lève une exception si le chemin contient "..".
     *
     * Protection contre les attaques de traversée de répertoire (path traversal).
     * Un attaquant ne doit pas pouvoir accéder à /etc/passwd via "../../etc/passwd".
     */
    public function testGetAbsolutePathThrowsOnPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/traversée/i');

        $this->storage->getAbsolutePath('../../../etc/passwd');
    }

    // ─── Tests de exists() ───────────────────────────────────────────────────

    /**
     * Test : exists() retourne false pour un fichier inexistant.
     */
    public function testExistsReturnsFalseForNonExistentFile(): void
    {
        $this->assertFalse($this->storage->exists('creator-docs/inexistant.pdf'));
    }

    /**
     * Test : exists() retourne true pour un fichier existant.
     */
    public function testExistsReturnsTrueForExistingFile(): void
    {
        // Crée un fichier temporaire dans le dossier sécurisé de test
        $subDir = $this->tempDir . '/creator-docs';
        mkdir($subDir, 0750, true);
        file_put_contents($subDir . '/test.pdf', 'contenu test');

        $this->assertTrue($this->storage->exists('creator-docs/test.pdf'));
    }

    /**
     * Test : exists() retourne false si le chemin contient "..".
     *
     * Protection : on ne lève pas d'exception dans exists(), on retourne false
     * pour éviter les crashs dans les boucles de nettoyage admin.
     */
    public function testExistsReturnsFalseOnPathTraversal(): void
    {
        $this->assertFalse($this->storage->exists('../../../etc/passwd'));
    }

    // ─── Tests de delete() ───────────────────────────────────────────────────

    /**
     * Test : delete() supprime un fichier existant.
     */
    public function testDeleteRemovesFile(): void
    {
        // Crée un fichier à supprimer
        $subDir = $this->tempDir . '/creator-docs';
        mkdir($subDir, 0750, true);
        $filePath = $subDir . '/a_supprimer.pdf';
        file_put_contents($filePath, 'contenu');

        $this->assertTrue(is_file($filePath)); // pré-condition

        $this->storage->delete('creator-docs/a_supprimer.pdf');

        $this->assertFalse(is_file($filePath)); // le fichier a bien été supprimé
    }

    /**
     * Test : delete() est idempotent — ne lève pas d'exception si le fichier n'existe pas.
     */
    public function testDeleteIsIdempotent(): void
    {
        // Ne doit pas lever d'exception (idempotence)
        $this->storage->delete('creator-docs/inexistant.pdf');
        // Vérifie que le fichier n'existe pas (idempotent → pas créé non plus)
        $this->assertFalse($this->storage->exists('creator-docs/inexistant.pdf'));
    }

    /**
     * Test : delete() lève une exception si le chemin contient "..".
     */
    public function testDeleteThrowsOnPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->delete('../../etc/passwd');
    }

    // ─── Tests de store() — validation du type MIME ──────────────────────────

    /**
     * Test : store() lève une exception pour un type MIME non autorisé.
     *
     * On crée un fichier vide avec l'extension .exe (exécutable) —
     * le type MIME détecté par finfo sera "application/x-dosexec" → refusé.
     *
     * Note : on ne peut pas facilement tester les types autorisés (jpg, png, pdf)
     * en tests unitaires sans UploadedFile réels contenant les bons magic bytes.
     * On se concentre sur les cas de rejet.
     */
    public function testStoreRejectsInvalidMimeType(): void
    {
        // Crée un fichier binaire qui ressemble à un exécutable DOS/Windows
        // (magic bytes "MZ" = signature PE/COFF des .exe Windows)
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'MZ' . str_repeat("\x00", 50)); // magic bytes .exe

        // Crée un UploadedFile simulant un upload avec extension .pdf mais contenu exe
        // (l'utilisateur renomme son exe en .pdf pour tromper le filtre accept="application/pdf")
        $uploadedFile = new UploadedFile(
            $tmpFile,
            'malicious.pdf',  // nom original déclaré
            'application/pdf', // MIME type déclaré par le client
            null,
            true // test mode : désactive la vérification SAPI "is_uploaded_file"
        );

        $user = new User();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/Type de fichier non autorisé/');
            $this->storage->store($uploadedFile, $user);
        } finally {
            // Nettoyage (UploadedFile déplace le fichier, donc tmpFile peut ne plus exister)
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * Test : store() lève une exception si le fichier dépasse 5 Mo.
     *
     * On crée un fichier PDF valide (magic bytes %PDF) de plus de 5 Mo
     * pour tester la limite de taille.
     */
    public function testStoreRejectsFileTooLarge(): void
    {
        // Crée un faux PDF valide (magic bytes "%PDF-") de 6 Mo
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_large_');
        $content = '%PDF-1.4' . str_repeat('A', 6 * 1024 * 1024); // 6 Mo
        file_put_contents($tmpFile, $content);

        $uploadedFile = new UploadedFile(
            $tmpFile,
            'large.pdf',
            'application/pdf',
            null,
            true // test mode
        );

        $user = new User();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/trop volumineux/i');
            $this->storage->store($uploadedFile, $user);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    // ─── Helper : suppression récursive d'un dossier ────────────────────────

    /**
     * Supprime récursivement un dossier et tout son contenu.
     * Utilisé dans tearDown() pour nettoyer les fichiers de test.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

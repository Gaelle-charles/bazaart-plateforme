<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AssociationProject;

use App\Service\AssociationProject\AssociationProjectDocumentStorage;
use App\Service\AssociationProject\StoredDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests unitaires du stockage des documents PDF des fiches (ADR-0039).
 * Dossier temporaire, pas de kernel Symfony, pas de BDD.
 */
#[CoversClass(AssociationProjectDocumentStorage::class)]
class AssociationProjectDocumentStorageTest extends TestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private string $tempDir;
    private string $storageDir;
    private AssociationProjectDocumentStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bazaart_docs_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0750, true);
        // Le dossier des documents n'existe pas encore : store() doit le créer.
        $this->storageDir = $this->tempDir . '/documents';
        $this->storage = new AssociationProjectDocumentStorage($this->storageDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    public function testStoresPdfUnderRandomName(): void
    {
        $stored = $this->storage->store($this->upload(self::MINIMAL_PDF, 'Dossier de présentation.pdf'));

        self::assertInstanceOf(StoredDocument::class, $stored);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $stored->path);
        self::assertSame(strlen(self::MINIMAL_PDF), $stored->sizeBytes);
        self::assertSame(self::MINIMAL_PDF, file_get_contents($this->storageDir . '/' . $stored->path));
        self::assertSame(realpath($this->storageDir . '/' . $stored->path), $this->storage->absolutePath($stored->path));
    }

    public function testRejectsRenamedFile(): void
    {
        $result = $this->storage->store($this->upload("<?php echo 'x';\n", 'dossier.pdf'));

        self::assertSame('Format non accepté : seuls les fichiers PDF sont acceptés.', $result);
        self::assertDirectoryDoesNotExist($this->storageDir);
    }

    public function testRejectsImage(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
        self::assertIsString($png);

        self::assertSame('Format non accepté : seuls les fichiers PDF sont acceptés.', $this->storage->store($this->upload($png, 'photo.pdf')));
    }

    public function testRejectsTooLarge(): void
    {
        $result = $this->storage->store($this->upload('%PDF-1.4' . str_repeat('A', AssociationProjectDocumentStorage::MAX_SIZE_BYTES), 'gros.pdf'));

        self::assertSame('Le document ne doit pas dépasser 10 Mo.', $result);
    }

    public function testAbsolutePathRefusesTraversal(): void
    {
        mkdir($this->storageDir, 0750, true);
        file_put_contents($this->tempDir . '/secret.txt', 'secret');

        self::assertNull($this->storage->absolutePath('../secret.txt'));
        self::assertNull($this->storage->absolutePath(''));
        self::assertNull($this->storage->absolutePath(null));
        self::assertNull($this->storage->absolutePath('inexistant.pdf'));
    }

    public function testDelete(): void
    {
        $stored = $this->storage->store($this->upload(self::MINIMAL_PDF, 'a.pdf'));
        self::assertInstanceOf(StoredDocument::class, $stored);

        $this->storage->delete($stored->path);
        $this->storage->delete($stored->path); // deuxième fois : sans effet, sans erreur

        self::assertNull($this->storage->absolutePath($stored->path));
    }

    private function upload(string $content, string $originalName): UploadedFile
    {
        $tmp = tempnam($this->tempDir, 'up_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $originalName, 'application/pdf', null, true);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

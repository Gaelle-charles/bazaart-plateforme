<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AssociationProject;

use App\Entity\AssociationProject;
use App\Service\AssociationProject\AssociationProjectImageStorage;
use App\Service\AssociationProject\AssociationProjectTextImages;
use App\Service\AssociationProject\StoredImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests unitaires des images du texte « Le projet en détail » (ADR-0039) :
 * rangement par fiche, nettoyage des images qui ne sont plus citées (règle des 24 h).
 */
#[CoversClass(AssociationProjectTextImages::class)]
class AssociationProjectTextImagesTest extends TestCase
{
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $tempDir;
    private AssociationProjectTextImages $textImages;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bazaart_text_images_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0750, true);
        $this->textImages = new AssociationProjectTextImages(new AssociationProjectImageStorage($this->tempDir));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    public function testImagesAreStoredPerFiche(): void
    {
        $first = $this->project(12);
        $other = $this->project(13);
        $name = $this->store($first);

        self::assertFileExists($this->tempDir . '/texte/12/' . $name);
        self::assertNotNull($this->textImages->absolutePath($first, $name));
        self::assertNull($this->textImages->absolutePath($other, $name), "l'image n'appartient pas à l'autre fiche");
        self::assertSame($name, $this->textImages->findByHash($first, substr($name, 0, 32)));
        self::assertNull($this->textImages->findByHash($other, substr($name, 0, 32)));
        self::assertNull($this->textImages->findByHash($first, substr($name, 0, 32) . "\n"));
        self::assertNull($this->textImages->absolutePath($first, '../13/' . $name));
    }

    public function testDeleteUnusedFollowsTheRules(): void
    {
        $project = $this->project(12);
        $removedFromText = $this->store($project);
        $stillCited = $this->store($project);
        $recentUpload = $this->store($project);
        $oldUpload = $this->store($project);
        touch($this->tempDir . '/texte/12/' . $oldUpload, time() - 25 * 3600);

        $this->textImages->deleteUnused($project, [$removedFromText, $stillCited], [$stillCited]);

        self::assertNull($this->textImages->absolutePath($project, $removedFromText), 'retirée du texte : effacée tout de suite');
        self::assertNotNull($this->textImages->absolutePath($project, $stillCited), 'toujours citée : gardée');
        self::assertNotNull($this->textImages->absolutePath($project, $recentUpload), 'envoyée il y a moins de 24 h : gardée');
        self::assertNull($this->textImages->absolutePath($project, $oldUpload), 'jamais enregistrée, plus de 24 h : effacée');
    }

    public function testDeleteAllForProjectIdRemovesTheFolderOnly(): void
    {
        $project = $this->project(12);
        $other = $this->project(13);
        $this->store($project);
        $kept = $this->store($other);

        $this->textImages->deleteAllForProjectId(12);

        self::assertDirectoryDoesNotExist($this->tempDir . '/texte/12');
        self::assertNotNull($this->textImages->absolutePath($other, $kept));
    }

    private function store(AssociationProject $project): string
    {
        $tmp = (string) tempnam($this->tempDir, 'up-');
        file_put_contents($tmp, (string) base64_decode(self::TINY_PNG, true));
        $stored = $this->textImages->store($project, new UploadedFile($tmp, 'photo.png', 'image/png', null, true));
        self::assertInstanceOf(StoredImage::class, $stored);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $stored->path);

        return $stored->path;
    }

    private function project(int $id): AssociationProject
    {
        $project = new AssociationProject();
        (new \ReflectionProperty(AssociationProject::class, 'id'))->setValue($project, $id);

        return $project;
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

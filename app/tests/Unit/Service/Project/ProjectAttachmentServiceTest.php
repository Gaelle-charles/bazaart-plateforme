<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Project;
use App\Entity\ProjectDriveConnection;
use App\Entity\ProjectTask;
use App\Entity\User;
use App\Exception\GoogleDriveException;
use App\Repository\ProjectAttachmentRepository;
use App\Repository\ProjectDriveConnectionRepository;
use App\Service\Project\GoogleDriveService;
use App\Service\Project\ProjectActivityLogger;
use App\Service\Project\ProjectAttachmentService;
use App\Service\Project\TokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Téléversement depuis une tâche : le fichier doit atterrir dans le dossier CHOISI
 * (ex. le dossier « PV » ouvert dans le sélecteur), et non plus systématiquement
 * dans le dossier du projet ou « Mon Drive ».
 */
class ProjectAttachmentServiceTest extends TestCase
{
    /** @var list<string> corps des requêtes de téléversement envoyées à Google */
    private array $uploadBodies = [];

    public function testUploadGoesIntoTheChosenFolder(): void
    {
        $result = $this->service()->uploadAndAttach($this->taskInProject(), $this->file(), new User(), 'PV_FOLDER_1');

        self::assertSame('PV', $result['folderName']);
        self::assertStringContainsString('"parents":["PV_FOLDER_1"]', $this->uploadBodies[0]);
        self::assertSame('pv-septembre.pdf', $result['attachment']->getName());
    }

    public function testWithoutChoiceUploadFallsBackToTheProjectFolder(): void
    {
        $result = $this->service()->uploadAndAttach($this->taskInProject(), $this->file(), new User());

        self::assertSame('Festival 2026', $result['folderName']);
        self::assertStringContainsString('"parents":["PROJECT_FOLDER"]', $this->uploadBodies[0]);
    }

    public function testAFileCannotBeUsedAsDestination(): void
    {
        $this->expectException(GoogleDriveException::class);
        $this->expectExceptionMessage('n\'est pas un dossier');
        $this->service()->uploadAndAttach($this->taskInProject(), $this->file(), new User(), 'SOME_PDF_FILE');
    }

    // ─── Outils ──────────────────────────────────────────────────────────────

    private function service(): ProjectAttachmentService
    {
        $this->uploadBodies = [];
        $routes = [
            'oauth2.googleapis.com/token'  => ['access_token' => 'fresh', 'expires_in' => 3600],
            'drive/v3/files/PV_FOLDER_1'   => ['id' => 'PV_FOLDER_1', 'name' => 'PV', 'mimeType' => 'application/vnd.google-apps.folder'],
            'drive/v3/files/SOME_PDF_FILE' => ['id' => 'SOME_PDF_FILE', 'name' => 'Budget.pdf', 'mimeType' => 'application/pdf'],
            'upload/drive/v3/files'        => ['id' => 'NEW_FILE_1', 'name' => 'pv-septembre.pdf', 'mimeType' => 'application/pdf', 'webViewLink' => 'https://drive.google.com/file/d/NEW_FILE_1/view'],
        ];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($routes): MockResponse {
            if (str_contains($url, 'upload/drive/v3/files')) {
                $this->uploadBodies[] = (string) $options['body'];
            }
            foreach ($routes as $fragment => $response) {
                if (str_contains($url, $fragment)) {
                    return new MockResponse((string) json_encode($response));
                }
            }

            return new MockResponse('{"error":{"message":"not found"}}', ['http_code' => 404]);
        });

        $cipher     = new TokenCipher('test-secret');
        $repository = $this->createStub(ProjectDriveConnectionRepository::class);
        $repository->method('findCurrent')->willReturn(
            (new ProjectDriveConnection())->setAccountEmail('team@example.org')->setEncryptedRefreshToken($cipher->encrypt('rt')),
        );
        $em    = $this->createStub(EntityManagerInterface::class);
        $drive = new GoogleDriveService($client, $repository, $em, new ArrayAdapter(), $cipher, new NullLogger(), 'id', 'secret', 'team@example.org');

        return new ProjectAttachmentService($em, $this->createStub(ProjectAttachmentRepository::class), $drive, $this->createStub(ProjectActivityLogger::class));
    }

    private function taskInProject(): ProjectTask
    {
        $project = (new Project())->setName('Festival 2026')->setDriveFolder('PROJECT_FOLDER', 'Festival 2026');

        return (new ProjectTask())->setTitle('Rédiger les PV')->setProject($project);
    }

    private function file(): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($path, '%PDF-1.4 test');

        return new UploadedFile($path, 'pv-septembre.pdf', 'application/pdf', null, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\ProjectDriveConnection;
use App\Entity\User;
use App\Exception\GoogleDriveException;
use App\Exception\GoogleDriveNotConnectedException;
use App\Repository\ProjectDriveConnectionRepository;
use App\Service\Project\GoogleDriveService;
use App\Service\Project\TokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * GoogleDriveService testé SANS appeler Google : MockHttpClient simule les réponses
 * de l'API et enregistre les requêtes envoyées, qu'on inspecte ensuite (ADR-0037).
 */
class GoogleDriveServiceTest extends TestCase
{
    private const string EXPECTED = 'reinesdestempsmodernes@gmail.com';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    private TokenCipher $cipher;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->cipher   = new TokenCipher('test-secret');
    }

    public function testAuthorizationUrlAsksForOfflineDriveAccessOnTheTeamAccount(): void
    {
        $url = $this->service([])->buildAuthorizationUrl('https://app.bazaart.fr/admin/projets/drive/callback', 'etat123');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        self::assertSame('client-id', $params['client_id']);
        self::assertSame(GoogleDriveService::SCOPE, $params['scope']);
        self::assertSame('offline', $params['access_type']);
        self::assertSame('consent', $params['prompt']);
        self::assertSame(self::EXPECTED, $params['login_hint']);
        self::assertSame('etat123', $params['state']);
    }

    public function testWrongGoogleAccountIsRejectedAndRevoked(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $service = $this->service([
            'oauth2.googleapis.com/token'  => ['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600],
            'drive/v3/about'               => ['user' => ['emailAddress' => 'perso@gmail.com']],
            'oauth2.googleapis.com/revoke' => [],
        ], em: $em);

        try {
            $service->completeAuthorization('code', 'https://x/callback', new User());
            self::fail('Une exception était attendue');
        } catch (GoogleDriveException $e) {
            self::assertStringContainsString('perso@gmail.com', $e->getMessage());
            self::assertStringContainsString(self::EXPECTED, $e->getMessage());
        }
        self::assertTrue($this->wasCalled('oauth2.googleapis.com/revoke'), 'L\'autorisation du mauvais compte est révoquée');
    }

    public function testTeamAccountConnectionIsStoredEncrypted(): void
    {
        $stored = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(static function (object $entity) use (&$stored): void {
            $stored = $entity;
        });

        $service = $this->service([
            'oauth2.googleapis.com/token' => ['access_token' => 'at', 'refresh_token' => 'refresh-secret', 'expires_in' => 3600],
            'drive/v3/about'              => ['user' => ['emailAddress' => 'ReinesDesTempsModernes@gmail.com']],
        ], em: $em);

        self::assertSame(self::EXPECTED, $service->completeAuthorization('code', 'https://x/callback', new User()));
        self::assertInstanceOf(ProjectDriveConnection::class, $stored);
        self::assertStringNotContainsString('refresh-secret', $stored->getEncryptedRefreshToken());
        self::assertSame('refresh-secret', $this->cipher->decrypt($stored->getEncryptedRefreshToken()));
    }

    public function testNotConnectedDriveThrowsDedicatedException(): void
    {
        $this->expectException(GoogleDriveNotConnectedException::class);
        $this->service([], connection: null)->listFolder('root');
    }

    public function testListFolderRefreshesTokenAndNormalisesFiles(): void
    {
        $service = $this->service([
            'oauth2.googleapis.com/token' => ['access_token' => 'fresh', 'expires_in' => 3600],
            'drive/v3/files/FOLDER_123'   => ['id' => 'FOLDER_123', 'name' => 'Festival', 'parents' => ['ROOT_ID_1']],
            'drive/v3/files/ROOT_ID_1'    => ['id' => 'ROOT_ID_1', 'name' => 'My Drive'],
            'drive/v3/files'              => ['files' => [
                ['id' => 'SUB_FOLDER', 'name' => 'Visuels', 'mimeType' => 'application/vnd.google-apps.folder', 'webViewLink' => 'https://drive.google.com/drive/folders/SUB_FOLDER'],
                ['id' => 'FILE_PDF_1', 'name' => 'Budget.pdf', 'mimeType' => 'application/pdf', 'webViewLink' => 'javascript:alert(1)'],
            ], 'nextPageToken' => 'PAGE2'],
        ]);

        $result = $service->listFolder('FOLDER_123');

        self::assertSame('Festival', $result['folder']['name']);
        self::assertSame(['Mon Drive', 'Festival'], array_column($result['breadcrumb'], 'name'));
        self::assertSame('folder', $result['items'][0]['kind']);
        self::assertTrue($result['items'][0]['isFolder']);
        self::assertSame('pdf', $result['items'][1]['kind']);
        // Un lien non Google est remplacé par le lien standard (défense XSS)
        self::assertSame('https://drive.google.com/file/d/FILE_PDF_1/view', $result['items'][1]['webViewLink']);
        self::assertSame('PAGE2', $result['nextPageToken']);

        $list = $this->lastCall('drive/v3/files?');
        self::assertSame("'FOLDER_123' in parents and trashed = false", $list['options']['query']['q']);
        self::assertSame('fresh', $list['options']['auth_bearer'] ?? $this->bearer($list));
    }

    public function testSearchEscapesQuotesInQuery(): void
    {
        $service = $this->service([
            'oauth2.googleapis.com/token' => ['access_token' => 'fresh', 'expires_in' => 3600],
            'drive/v3/files'              => ['files' => []],
        ]);

        $service->search("l'expo d'hiver");

        self::assertSame("name contains 'l\\'expo d\\'hiver' and trashed = false", $this->lastCall('drive/v3/files?')['options']['query']['q']);
    }

    public function testInvalidIdsAreRejectedBeforeAnyCall(): void
    {
        $this->expectException(GoogleDriveException::class);
        $this->service([])->listFolder("root' or 1=1 or '");
    }

    public function testRevokedGrantForgetsTheConnection(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with(self::isInstanceOf(ProjectDriveConnection::class));

        $service = $this->service([
            'oauth2.googleapis.com/token' => new MockResponse((string) json_encode(['error' => 'invalid_grant']), ['http_code' => 400]),
        ], em: $em);

        $this->expectException(GoogleDriveNotConnectedException::class);
        $service->recent();
    }

    public function testUploadSendsMultipartRequestIntoProjectFolder(): void
    {
        $service = $this->service([
            'oauth2.googleapis.com/token' => ['access_token' => 'fresh', 'expires_in' => 3600],
            'upload/drive/v3/files'       => ['id' => 'NEW_FILE_1', 'name' => 'affiche.txt', 'mimeType' => 'text/plain', 'webViewLink' => 'https://drive.google.com/file/d/NEW_FILE_1/view'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($path, 'Contenu de test');
        $result = $service->upload(new UploadedFile($path, '../affiche.txt', 'text/plain', null, true), 'PROJECT_FOLDER');
        @unlink($path);

        self::assertSame('NEW_FILE_1', $result['id']);
        $call = $this->lastCall('upload/drive/v3/files');
        self::assertSame('multipart', $call['options']['query']['uploadType']);
        $body = (string) $call['options']['body'];
        self::assertStringContainsString('"parents":["PROJECT_FOLDER"]', $body);
        self::assertStringContainsString('"name":"affiche.txt"', $body, 'Aucun chemin (« ../ ») dans le nom envoyé au Drive');
        self::assertStringContainsString('Contenu de test', $body);
    }

    // ─── Outils ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, array<mixed>|MockResponse> $routes fragment d'URL => réponse JSON
     */
    private function service(array $routes, ?EntityManagerInterface $em = null, ?ProjectDriveConnection $connection = null): GoogleDriveService
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($routes): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            // Route la plus spécifique d'abord (clé la plus longue qui correspond)
            uksort($routes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($routes as $fragment => $response) {
                if (str_contains($url, $fragment)) {
                    return $response instanceof MockResponse ? $response : new MockResponse((string) json_encode($response));
                }
            }

            return new MockResponse('{"error":{"message":"not found"}}', ['http_code' => 404]);
        });

        // Connexion enregistrée par défaut (refresh token chiffré), sauf si null est passé explicitement.
        $args = func_get_args();
        if (!array_key_exists(2, $args)) {
            $connection = (new ProjectDriveConnection())
                ->setAccountEmail(self::EXPECTED)
                ->setEncryptedRefreshToken($this->cipher->encrypt('stored-refresh'));
        }

        // createStub : simple doublure sans attente (createMock est réservé aux vérifications d'appels).
        $repository = $this->createStub(ProjectDriveConnectionRepository::class);
        $repository->method('findCurrent')->willReturn($connection);

        return new GoogleDriveService(
            $client,
            $repository,
            $em ?? $this->createStub(EntityManagerInterface::class),
            new ArrayAdapter(),
            $this->cipher,
            new NullLogger(),
            'client-id',
            'client-secret',
            self::EXPECTED,
        );
    }

    private function wasCalled(string $fragment): bool
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{method: string, url: string, options: array<string, mixed>} */
    private function lastCall(string $fragment): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if (str_contains($request['url'], $fragment)) {
                return $request;
            }
        }
        self::fail('Aucun appel vers ' . $fragment);
    }

    /** @param array{options: array<string, mixed>} $call */
    private function bearer(array $call): string
    {
        foreach ((array) ($call['options']['normalized_headers']['authorization'] ?? []) as $header) {
            return trim(str_replace(['Authorization:', 'Bearer'], '', (string) $header));
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\ProjectDriveConnection;
use App\Entity\User;
use App\Enum\ProjectFileKind;
use App\Exception\GoogleDriveException;
use App\Exception\GoogleDriveNotConnectedException;
use App\Repository\ProjectDriveConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GoogleDriveService — accès au Google Drive de l'équipe depuis l'Espace projets (ADR-0037).
 *
 * ─── PRINCIPE ────────────────────────────────────────────────────────────────
 *
 * Le Drive de reinesdestempsmodernes@gmail.com est connecté UNE FOIS via OAuth 2.0
 * (« Connecter le Drive » → écran de consentement Google → retour sur la plateforme).
 * Google nous donne alors un « refresh token » durable, que l'on stocke chiffré.
 * Ensuite, TOUTE l'équipe parcourt ce Drive depuis l'outil, sans avoir à se
 * connecter elle-même à ce compte Google : c'est le serveur qui fait les appels.
 *
 *     Navigateur ──(fetch JSON)──► Symfony ──(HTTPS + jeton)──► API Google Drive v3
 *
 * Avantage de passer par le serveur : la CSP du site (connect-src 'self') n'a pas
 * besoin d'autoriser les domaines Google, et le jeton ne quitte jamais le serveur.
 *
 * ─── POURQUOI le client HTTP Symfony et pas google/apiclient ? ──────────────────
 *
 * Les 5 appels dont on a besoin sont simples (REST + JSON). HttpClient rend le code
 * lisible (on voit exactement ce qui part chez Google) et se teste facilement avec
 * MockHttpClient, sans configuration globale du client Google existant.
 *
 * ─── SCOPE ───────────────────────────────────────────────────────────────────
 *
 * `drive` (accès complet) : nécessaire pour PARCOURIR tout le Drive et TÉLÉVERSER
 * dans des dossiers existants. Le scope restreint `drive.file` ne verrait que les
 * fichiers créés par l'application.
 */
class GoogleDriveService
{
    public const string SCOPE = 'https://www.googleapis.com/auth/drive';

    private const string AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const string API_URL    = 'https://www.googleapis.com/drive/v3';
    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    /** Champs demandés à l'API pour chaque fichier (limite la taille des réponses). */
    private const string FILE_FIELDS = 'id,name,mimeType,webViewLink,modifiedTime,size,parents';

    /** Clé de cache du jeton d'accès (valable ~1 h chez Google). */
    private const string ACCESS_TOKEN_CACHE_KEY = 'project_drive_access_token';

    /** Taille maximale d'un téléversement via la plateforme (10 Mo, cohérent avec Nginx). */
    public const int MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ProjectDriveConnectionRepository $connectionRepository,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly TokenCipher $cipher,
        private readonly LoggerInterface $logger,
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
        private readonly string $expectedAccount,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // Connexion OAuth
    // ═════════════════════════════════════════════════════════════════════════

    /** Les identifiants OAuth de l'application Google sont-ils renseignés ? */
    public function isConfigured(): bool
    {
        return trim($this->googleClientId) !== '' && trim($this->googleClientSecret) !== '';
    }

    public function isConnected(): bool
    {
        return $this->connectionRepository->findCurrent() !== null;
    }

    public function getConnection(): ?ProjectDriveConnection
    {
        return $this->connectionRepository->findCurrent();
    }

    /** Adresse du Drive attendu (reinesdestempsmodernes@gmail.com par défaut). */
    public function getExpectedAccount(): string
    {
        return $this->expectedAccount;
    }

    /**
     * URL de l'écran de consentement Google.
     *
     *  - access_type=offline + prompt=consent : obligent Google à renvoyer un
     *    refresh token (sinon il n'en donne qu'à la toute première autorisation).
     *  - login_hint : pré-sélectionne le bon compte Google dans l'écran de choix.
     *  - state : valeur aléatoire vérifiée au retour (protection CSRF du flux OAuth).
     */
    public function buildAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => $this->googleClientId,
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint'             => $this->expectedAccount,
            'state'                  => $state,
        ]);
    }

    /**
     * Retour de Google : échange le code contre les jetons, VÉRIFIE que le compte
     * connecté est bien le Drive de l'équipe, puis enregistre la connexion.
     *
     * @return string l'adresse du compte connecté
     *
     * @throws GoogleDriveException si l'échange échoue ou si ce n'est pas le bon compte
     */
    public function completeAuthorization(string $code, string $redirectUri, User $connectedBy): string
    {
        $tokens = $this->requestToken([
            'code'          => $code,
            'client_id'     => $this->googleClientId,
            'client_secret' => $this->googleClientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $accessToken  = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
        $refreshToken = is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : '';
        if ($accessToken === '' || $refreshToken === '') {
            throw new GoogleDriveException('Google n\'a pas renvoyé d\'autorisation durable. Relance la connexion du Drive.');
        }

        // ── Vérification du compte : on demande à Drive QUI est connecté ──────
        $about = $this->request('GET', self::API_URL . '/about', $accessToken, ['query' => ['fields' => 'user(emailAddress,displayName)']]);
        $email = is_array($about['user'] ?? null) && is_string($about['user']['emailAddress'] ?? null)
            ? mb_strtolower($about['user']['emailAddress'])
            : '';

        if ($email !== mb_strtolower($this->expectedAccount)) {
            // Mauvais compte (ex. Drive personnel choisi par erreur) : on révoque
            // immédiatement l'autorisation pour ne rien garder de ce compte.
            $this->revoke($refreshToken);
            throw new GoogleDriveException(sprintf(
                'Le compte Google choisi (%s) n\'est pas le Drive de l\'équipe. Reconnecte-toi avec %s.',
                $email !== '' ? $email : 'inconnu',
                $this->expectedAccount,
            ));
        }

        // ── Enregistrement : une seule connexion active ───────────────────────
        $connection = $this->connectionRepository->findCurrent() ?? new ProjectDriveConnection();
        $connection
            ->setAccountEmail($email)
            ->setEncryptedRefreshToken($this->cipher->encrypt($refreshToken))
            ->setConnectedBy($connectedBy)
            ->renew();
        $this->em->persist($connection);
        $this->em->flush();

        $this->storeAccessToken($accessToken, (int) ($tokens['expires_in'] ?? 3600));

        return $email;
    }

    /** Déconnecte le Drive : révoque l'autorisation chez Google et oublie les jetons. */
    public function disconnect(): void
    {
        $connection = $this->connectionRepository->findCurrent();
        if ($connection !== null) {
            $refreshToken = $this->cipher->decrypt($connection->getEncryptedRefreshToken());
            if ($refreshToken !== null) {
                $this->revoke($refreshToken);
            }
            $this->em->remove($connection);
            $this->em->flush();
        }

        $this->cache->delete(self::ACCESS_TOKEN_CACHE_KEY);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Lecture du Drive
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Contenu d'un dossier (dossiers d'abord, puis fichiers par nom).
     *
     * @return array{folder: array{id: string, name: string}, breadcrumb: list<array{id: string, name: string}>, items: list<array<string, mixed>>, nextPageToken: string|null}
     */
    public function listFolder(?string $folderId = null, ?string $pageToken = null): array
    {
        $folderId = $folderId ?? 'root';
        $this->assertValidId($folderId);

        $result = $this->listFiles(
            sprintf("'%s' in parents and trashed = false", $folderId),
            'folder,name',
            $pageToken,
        );

        $breadcrumb = $this->breadcrumb($folderId);
        $current    = $breadcrumb[count($breadcrumb) - 1];

        return [
            'folder'        => $current,
            'breadcrumb'    => $breadcrumb,
            'items'         => $result['items'],
            'nextPageToken' => $result['nextPageToken'],
        ];
    }

    /**
     * Recherche par nom dans tout le Drive.
     *
     * @return array{items: list<array<string, mixed>>, nextPageToken: string|null}
     */
    public function search(string $term, ?string $pageToken = null): array
    {
        $term = trim(mb_substr($term, 0, 100));
        if ($term === '') {
            return ['items' => [], 'nextPageToken' => null];
        }

        return $this->listFiles(
            sprintf("name contains '%s' and trashed = false", self::escapeQueryValue($term)),
            'folder,modifiedTime desc',
            $pageToken,
        );
    }

    /**
     * Derniers fichiers modifiés (onglet « Récents » du sélecteur).
     *
     * @return array{items: list<array<string, mixed>>, nextPageToken: string|null}
     */
    public function recent(): array
    {
        return $this->listFiles(
            sprintf("mimeType != '%s' and trashed = false", ProjectFileKind::DRIVE_FOLDER_MIME),
            'modifiedTime desc',
            null,
            30,
        );
    }

    /**
     * Métadonnées d'un fichier. Utilisé au moment de JOINDRE un fichier : on relit
     * nom, type et lien chez Google plutôt que de faire confiance au navigateur.
     *
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        $this->assertValidId($fileId);
        $data = $this->request('GET', self::API_URL . '/files/' . $fileId, null, [
            'query' => ['fields' => self::FILE_FIELDS],
        ]);

        return $this->normalizeFile($data);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Écriture dans le Drive
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Crée un dossier (ex. un dossier par projet).
     *
     * @return array<string, mixed>
     */
    public function createFolder(string $name, ?string $parentId = null): array
    {
        $parentId = $parentId ?? 'root';
        $this->assertValidId($parentId);

        $data = $this->request('POST', self::API_URL . '/files', null, [
            'query' => ['fields' => self::FILE_FIELDS],
            'json'  => [
                'name'     => mb_substr(trim($name), 0, 200),
                'mimeType' => ProjectFileKind::DRIVE_FOLDER_MIME,
                'parents'  => [$parentId],
            ],
        ]);

        return $this->normalizeFile($data);
    }

    /**
     * Téléverse un fichier de l'ordinateur vers le Drive (requête « multipart »
     * de l'API Drive : une partie JSON de métadonnées + une partie binaire).
     *
     * @return array<string, mixed>
     */
    public function upload(UploadedFile $file, ?string $parentId = null): array
    {
        $parentId = $parentId ?? 'root';
        $this->assertValidId($parentId);

        if (!$file->isValid()) {
            throw new GoogleDriveException('Le fichier n\'a pas pu être reçu (trop volumineux ?).');
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new GoogleDriveException('Fichier trop lourd (10 Mo maximum). Dépose-le directement dans Drive puis joins-le.');
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            throw new GoogleDriveException('Lecture du fichier impossible.');
        }

        // Nom d'origine nettoyé (pas de chemin, pas de caractères de contrôle).
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', $file->getClientOriginalName()) ?: 'fichier';
        // Type MIME détecté par PHP à partir du CONTENU (pas de l'extension déclarée).
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        // Corps multipart/related construit à la main (format imposé par l'API Drive).
        $boundary = 'bazaart' . bin2hex(random_bytes(12));
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode(['name' => mb_substr($name, 0, 200), 'parents' => [$parentId]], JSON_THROW_ON_ERROR) . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $mime . "\r\n\r\n"
            . $content . "\r\n"
            . '--' . $boundary . '--';

        $data = $this->request('POST', self::UPLOAD_URL, null, [
            'query'   => ['uploadType' => 'multipart', 'fields' => self::FILE_FIELDS],
            'headers' => ['Content-Type' => 'multipart/related; boundary=' . $boundary],
            'body'    => $body,
            'timeout' => 120,
        ]);

        return $this->normalizeFile($data);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Outils internes
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Échappe une valeur insérée dans le langage de requête Drive (paramètre q).
     * Sans ça, une recherche contenant une apostrophe (« l'expo ») casserait la
     * requête, ou pourrait en modifier le sens (injection).
     */
    public static function escapeQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /** Les ID Drive sont alphanumériques (+ - et _) ; « root » désigne « Mon Drive ». */
    public static function isValidId(string $id): bool
    {
        return $id === 'root' || preg_match('/^[A-Za-z0-9_-]{5,128}$/', $id) === 1;
    }

    private function assertValidId(string $id): void
    {
        if (!self::isValidId($id)) {
            throw new GoogleDriveException('Identifiant de fichier Drive invalide.');
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextPageToken: string|null}
     */
    private function listFiles(string $query, string $orderBy, ?string $pageToken, int $pageSize = 100): array
    {
        $params = [
            'q'        => $query,
            'orderBy'  => $orderBy,
            'pageSize' => $pageSize,
            'fields'   => 'nextPageToken,files(' . self::FILE_FIELDS . ')',
        ];
        if ($pageToken !== null && preg_match('/^[A-Za-z0-9_\-.~+\/=]{1,512}$/', $pageToken) === 1) {
            $params['pageToken'] = $pageToken;
        }

        $data  = $this->request('GET', self::API_URL . '/files', null, ['query' => $params]);
        $files = is_array($data['files'] ?? null) ? $data['files'] : [];

        $items = [];
        foreach ($files as $file) {
            if (is_array($file)) {
                $items[] = $this->normalizeFile($file);
            }
        }

        return [
            'items'         => $items,
            'nextPageToken' => is_string($data['nextPageToken'] ?? null) ? $data['nextPageToken'] : null,
        ];
    }

    /**
     * Fil d'Ariane « Mon Drive › Bazaart › Festival » en remontant les parents.
     * Mis en cache 10 minutes par dossier (les dossiers bougent rarement).
     *
     * @return list<array{id: string, name: string}>
     */
    private function breadcrumb(string $folderId): array
    {
        if ($folderId === 'root') {
            return [['id' => 'root', 'name' => 'Mon Drive']];
        }

        /** @var list<array{id: string, name: string}> $crumbs */
        $crumbs = $this->cache->get('project_drive_crumbs_' . $folderId, function (ItemInterface $item) use ($folderId): array {
            $item->expiresAfter(600);
            $chain  = [];
            $cursor = $folderId;
            // 10 niveaux maximum : garde-fou contre une boucle infinie.
            for ($depth = 0; $depth < 10; ++$depth) {
                $data = $this->request('GET', self::API_URL . '/files/' . $cursor, null, ['query' => ['fields' => 'id,name,parents']]);
                $parents = is_array($data['parents'] ?? null) ? $data['parents'] : [];
                $parent  = isset($parents[0]) && is_string($parents[0]) ? $parents[0] : null;
                if ($parent === null) {
                    // Pas de parent = racine « Mon Drive » : on la nomme à la française.
                    array_unshift($chain, ['id' => 'root', 'name' => 'Mon Drive']);
                    break;
                }
                array_unshift($chain, ['id' => (string) ($data['id'] ?? $cursor), 'name' => (string) ($data['name'] ?? 'Dossier')]);
                $cursor = $parent;
            }

            return $chain;
        });

        return $crumbs;
    }

    /**
     * Format commun renvoyé au front (et utilisé pour créer les pièces jointes).
     *
     * @param array<mixed> $file
     *
     * @return array<string, mixed>
     */
    private function normalizeFile(array $file): array
    {
        $id   = (string) ($file['id'] ?? '');
        $mime = is_string($file['mimeType'] ?? null) ? $file['mimeType'] : null;
        $kind = ProjectFileKind::fromMimeType($mime);

        // Lien d'ouverture : on n'accepte qu'une URL Google en https (défense en
        // profondeur, ce lien finit dans un href). Sinon on reconstruit le lien standard.
        $link = is_string($file['webViewLink'] ?? null) ? $file['webViewLink'] : '';
        if (preg_match('#^https://(drive|docs)\.google\.com/#', $link) !== 1) {
            $link = $kind === ProjectFileKind::Folder
                ? 'https://drive.google.com/drive/folders/' . rawurlencode($id)
                : 'https://drive.google.com/file/d/' . rawurlencode($id) . '/view';
        }

        return [
            'id'           => $id,
            'name'         => (string) ($file['name'] ?? 'Sans nom'),
            'mimeType'     => $mime,
            'kind'         => $kind->value,
            'kindLabel'    => $kind->label(),
            'isFolder'     => $kind === ProjectFileKind::Folder,
            'webViewLink'  => $link,
            'modifiedTime' => is_string($file['modifiedTime'] ?? null) ? $file['modifiedTime'] : null,
            'size'         => isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : null,
        ];
    }

    /**
     * Jeton d'accès valide : lu dans le cache, sinon renouvelé avec le refresh token.
     *
     * @throws GoogleDriveNotConnectedException
     */
    private function getAccessToken(): string
    {
        // Cache « manqué » : le callback renvoie '' avec une durée de vie d'1 seconde,
        // pour ne pas mémoriser durablement une valeur vide.
        /** @var string $cached */
        $cached = $this->cache->get(self::ACCESS_TOKEN_CACHE_KEY, static function (ItemInterface $item): string {
            $item->expiresAfter(1);

            return '';
        });
        if ($cached !== '') {
            $token = $this->cipher->decrypt($cached);
            if ($token !== null) {
                return $token;
            }
        }

        $connection = $this->connectionRepository->findCurrent();
        if ($connection === null) {
            throw new GoogleDriveNotConnectedException('Le Google Drive de l\'équipe n\'est pas encore connecté.');
        }

        $refreshToken = $this->cipher->decrypt($connection->getEncryptedRefreshToken());
        if ($refreshToken === null) {
            throw new GoogleDriveNotConnectedException('La connexion au Drive n\'est plus lisible (clé du serveur modifiée ?). Reconnecte le Drive.');
        }

        try {
            $tokens = $this->requestToken([
                'client_id'     => $this->googleClientId,
                'client_secret' => $this->googleClientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);
        } catch (GoogleDriveNotConnectedException $e) {
            // invalid_grant : l'autorisation a été retirée (mot de passe changé,
            // accès révoqué dans le compte Google…). On oublie la connexion pour que
            // l'interface propose clairement de reconnecter le Drive.
            $this->em->remove($connection);
            $this->em->flush();
            throw $e;
        }

        $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
        if ($accessToken === '') {
            throw new GoogleDriveException('Google n\'a pas renvoyé de jeton d\'accès.');
        }
        $this->storeAccessToken($accessToken, (int) ($tokens['expires_in'] ?? 3600));

        return $accessToken;
    }

    private function storeAccessToken(string $accessToken, int $expiresIn): void
    {
        $this->cache->delete(self::ACCESS_TOKEN_CACHE_KEY);
        $encrypted = $this->cipher->encrypt($accessToken);
        // Marge de 2 minutes : on renouvelle un peu AVANT l'expiration réelle.
        $this->cache->get(self::ACCESS_TOKEN_CACHE_KEY, static function (ItemInterface $item) use ($encrypted, $expiresIn): string {
            $item->expiresAfter(max(60, $expiresIn - 120));

            return $encrypted;
        });
    }

    /**
     * Appel à l'endpoint de jetons OAuth.
     *
     * @param array<string, string> $form
     *
     * @return array<mixed>
     */
    private function requestToken(array $form): array
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, ['body' => $form, 'timeout' => 20]);
            $status   = $response->getStatusCode();
            $data     = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('[Drive] Échange de jeton impossible : ' . $e->getMessage());
            throw new GoogleDriveException('Impossible de joindre Google pour le moment. Réessaie dans quelques instants.');
        }

        if ($status >= 400) {
            $error = is_string($data['error'] ?? null) ? $data['error'] : 'unknown';
            $this->logger->warning('[Drive] Erreur OAuth : ' . $error, ['status' => $status]);
            if ($error === 'invalid_grant') {
                throw new GoogleDriveNotConnectedException('L\'autorisation d\'accès au Drive a expiré ou a été retirée. Reconnecte le Drive.');
            }
            throw new GoogleDriveException('Google a refusé la connexion au Drive (' . $error . ').');
        }

        return $data;
    }

    /**
     * Appel authentifié à l'API Drive, avec traduction des erreurs en français.
     *
     * @param array<string, mixed> $options options Symfony HttpClient
     *
     * @return array<mixed>
     */
    private function request(string $method, string $url, ?string $accessToken, array $options = []): array
    {
        $accessToken ??= $this->getAccessToken();
        $options['auth_bearer'] = $accessToken;
        $options['timeout'] ??= 20;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();
            $data     = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('[Drive] Appel API impossible : ' . $e->getMessage(), ['url' => $url]);
            throw new GoogleDriveException('Le Drive ne répond pas pour le moment. Réessaie dans quelques instants.');
        }

        if ($status < 400) {
            return $data;
        }

        $message = is_array($data['error'] ?? null) && is_string($data['error']['message'] ?? null)
            ? $data['error']['message']
            : 'HTTP ' . $status;
        $this->logger->warning('[Drive] Erreur API : ' . $message, ['status' => $status, 'url' => $url]);

        if ($status === 401) {
            // Jeton d'accès refusé : on vide le cache pour forcer un renouvellement au prochain appel.
            $this->cache->delete(self::ACCESS_TOKEN_CACHE_KEY);
            throw new GoogleDriveException('La session Drive a expiré. Réessaie.');
        }

        throw match ($status) {
            403     => new GoogleDriveException('Accès refusé par Google Drive pour ce fichier ou ce dossier.'),
            404     => new GoogleDriveException('Fichier ou dossier introuvable dans le Drive (supprimé ou déplacé ?).'),
            429     => new GoogleDriveException('Trop de requêtes vers le Drive, patiente une minute.'),
            default => new GoogleDriveException('Le Drive a renvoyé une erreur. Réessaie dans quelques instants.'),
        };
    }

    /** Révocation « best effort » : une erreur ici ne doit pas bloquer l'utilisatrice. */
    private function revoke(string $token): void
    {
        try {
            $this->httpClient->request('POST', self::REVOKE_URL, ['body' => ['token' => $token], 'timeout' => 10])->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->notice('[Drive] Révocation impossible : ' . $e->getMessage());
        }
    }
}

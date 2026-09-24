<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use App\Entity\ProjectAttachment;
use App\Entity\ProjectTask;
use App\Exception\GoogleDriveException;
use App\Exception\GoogleDriveNotConnectedException;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTaskRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\Project\GoogleDriveService;
use App\Service\Project\ProjectAttachmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminProjectDriveController — Google Drive de l'équipe + pièces jointes (ADR-0037).
 *
 *   /drive                  page « Drive » : parcourir, rechercher, ouvrir
 *   /drive/connexion        démarre la connexion OAuth (redirection vers Google)
 *   /drive/callback         retour de Google après consentement
 *   /drive/deconnexion      révoque et oublie la connexion
 *   /drive/api/*            API JSON utilisée par le sélecteur (fetch)
 *   /pieces-jointes/*       joindre (Drive, téléversement, lien) / retirer
 */
#[Route('/admin/projets', name: 'app_admin_pm_')]
#[IsGranted(ProjectVoter::ACCESS)]
class AdminProjectDriveController extends AbstractController
{
    use ProjectControllerTrait;

    /** Clé de session où l'on mémorise le « state » OAuth le temps de l'aller-retour chez Google. */
    private const string OAUTH_STATE_KEY = 'pm_drive_oauth_state';

    public function __construct(
        private readonly GoogleDriveService $driveService,
        private readonly ProjectAttachmentService $attachmentService,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectTaskRepository $taskRepository,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // Page Drive et connexion OAuth
    // ═════════════════════════════════════════════════════════════════════════

    #[Route('/drive', name: 'drive', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/projects/drive.html.twig', [
            'connection'   => $this->driveService->getConnection(),
            'configured'   => $this->driveService->isConfigured(),
            'driveAccount' => $this->driveService->getExpectedAccount(),
        ]);
    }

    /** GET /admin/projets/drive/connexion — redirige vers l'écran de consentement Google. */
    #[Route('/drive/connexion', name: 'drive_connect', methods: ['GET'])]
    public function connect(Request $request): Response
    {
        if (!$this->driveService->isConfigured()) {
            $this->addFlash('error', 'Les identifiants OAuth Google ne sont pas configurés sur le serveur (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).');

            return $this->redirectToRoute('app_admin_pm_drive');
        }

        // « state » : valeur aléatoire gardée en session et renvoyée par Google.
        // Si elle ne correspond pas au retour, quelqu'un essaie de nous faire
        // enregistrer SON propre Drive (attaque CSRF sur le flux OAuth).
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::OAUTH_STATE_KEY, $state);

        return $this->redirect($this->driveService->buildAuthorizationUrl($this->callbackUrl(), $state));
    }

    /** GET /admin/projets/drive/callback — retour de Google (?code=…&state=… ou ?error=…). */
    #[Route('/drive/callback', name: 'drive_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $session  = $request->getSession();
        $expected = $session->get(self::OAUTH_STATE_KEY);
        $session->remove(self::OAUTH_STATE_KEY);

        if ($request->query->has('error')) {
            $this->addFlash('warning', 'Connexion au Drive annulée.');

            return $this->redirectToRoute('app_admin_pm_drive');
        }

        $state = (string) $request->query->get('state', '');
        $code  = (string) $request->query->get('code', '');
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $state) || $code === '') {
            $this->addFlash('error', 'Retour de Google invalide ou expiré. Relance la connexion du Drive.');

            return $this->redirectToRoute('app_admin_pm_drive');
        }

        try {
            $email = $this->driveService->completeAuthorization($code, $this->callbackUrl(), $this->currentUser());
            $this->addFlash('success', sprintf('Drive %s connecté : toute l\'équipe peut maintenant y accéder depuis l\'Espace projets.', $email));
        } catch (GoogleDriveException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_pm_drive');
    }

    #[Route('/drive/deconnexion', name: 'drive_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): Response
    {
        if ($this->isCsrfTokenValid('pm_drive_disconnect', (string) $request->request->get('_token'))) {
            $this->driveService->disconnect();
            $this->addFlash('success', 'Drive déconnecté. Les pièces jointes restent visibles, mais on ne peut plus parcourir le Drive.');
        } else {
            $this->flashInvalidToken();
        }

        return $this->redirectToRoute('app_admin_pm_drive');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // API JSON du sélecteur
    // ═════════════════════════════════════════════════════════════════════════

    /** GET /admin/projets/drive/api/dossier?id=…&pageToken=… */
    #[Route('/drive/api/dossier', name: 'drive_api_list', methods: ['GET'])]
    public function apiList(Request $request): JsonResponse
    {
        $id = (string) $request->query->get('id', 'root');

        return $this->driveJson(fn (): array => $this->driveService->listFolder(
            $id !== '' ? $id : 'root',
            $request->query->has('pageToken') ? (string) $request->query->get('pageToken') : null,
        ));
    }

    /** GET /admin/projets/drive/api/recherche?q=… */
    #[Route('/drive/api/recherche', name: 'drive_api_search', methods: ['GET'])]
    public function apiSearch(Request $request): JsonResponse
    {
        return $this->driveJson(fn (): array => $this->driveService->search((string) $request->query->get('q', '')));
    }

    /** GET /admin/projets/drive/api/recents */
    #[Route('/drive/api/recents', name: 'drive_api_recent', methods: ['GET'])]
    public function apiRecent(): JsonResponse
    {
        return $this->driveJson(fn (): array => $this->driveService->recent());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Pièces jointes
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * POST /admin/projets/pieces-jointes/drive — joint des fichiers choisis dans le sélecteur (JSON).
     * Corps : {"target": "task"|"project", "targetId": 12, "fileIds": ["1AbC…", …]}
     */
    #[Route('/pieces-jointes/drive', name: 'attachment_drive', methods: ['POST'])]
    public function attachDrive(Request $request): JsonResponse
    {
        if (!$this->isAjaxCsrfValid($request)) {
            return $this->jsonError('Jeton de sécurité invalide, recharge la page.', 403);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->jsonError('Requête invalide.');
        }

        $parent  = $this->resolveParent((string) ($payload['target'] ?? ''), (int) ($payload['targetId'] ?? 0));
        $fileIds = is_array($payload['fileIds'] ?? null) ? array_slice(array_filter($payload['fileIds'], 'is_string'), 0, 20) : [];
        if ($parent === null || $fileIds === []) {
            return $this->jsonError('Rien à joindre.');
        }

        $attached = 0;
        try {
            foreach ($fileIds as $fileId) {
                $this->attachmentService->attachDriveFile($parent, $fileId, $this->currentUser());
                ++$attached;
            }
        } catch (GoogleDriveException $e) {
            return $this->jsonError($e->getMessage(), $attached > 0 ? 207 : 400);
        }

        return new JsonResponse(['ok' => true, 'attached' => $attached]);
    }

    /** POST /admin/projets/pieces-jointes/televerser — fichier de l'ordinateur → Drive → pièce jointe. */
    #[Route('/pieces-jointes/televerser', name: 'attachment_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $parent = $this->resolveParent((string) $request->request->get('target', ''), $request->request->getInt('targetId'));
        if ($parent === null || !$this->isCsrfTokenValid('pm_attachment', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Requête invalide ou jeton de sécurité expiré.');

            return $this->redirectBack($request, 'app_admin_pm_tasks');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier reçu (10 Mo maximum).');

            return $this->redirectBack($request, 'app_admin_pm_tasks');
        }

        try {
            $attachment = $this->attachmentService->uploadAndAttach($parent, $file, $this->currentUser());
            $this->addFlash('success', sprintf('« %s » téléversé dans le Drive et joint.', $attachment->getName()));
        } catch (GoogleDriveException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectBack($request, 'app_admin_pm_tasks');
    }

    /** POST /admin/projets/pieces-jointes/lien — joint un simple lien web. */
    #[Route('/pieces-jointes/lien', name: 'attachment_link', methods: ['POST'])]
    public function link(Request $request): Response
    {
        $parent = $this->resolveParent((string) $request->request->get('target', ''), $request->request->getInt('targetId'));
        if ($parent === null || !$this->isCsrfTokenValid('pm_attachment', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Requête invalide ou jeton de sécurité expiré.');

            return $this->redirectBack($request, 'app_admin_pm_tasks');
        }

        $error = $this->attachmentService->attachLink(
            $parent,
            (string) $request->request->get('url', ''),
            (string) $request->request->get('name', ''),
            $this->currentUser(),
        );
        $error === null ? $this->addFlash('success', 'Lien ajouté.') : $this->addFlash('error', $error);

        return $this->redirectBack($request, 'app_admin_pm_tasks');
    }

    /** POST /admin/projets/pieces-jointes/{id}/retirer — ne supprime RIEN dans le Drive. */
    #[Route('/pieces-jointes/{id}/retirer', name: 'attachment_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(ProjectAttachment $attachment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('pm_attachment_' . $attachment->getId(), (string) $request->request->get('_token'))) {
            $this->attachmentService->remove($attachment);
            $this->addFlash('success', 'Pièce jointe retirée (le fichier reste dans le Drive).');
        } else {
            $this->flashInvalidToken();
        }

        return $this->redirectBack($request, 'app_admin_pm_tasks');
    }

    // ═════════════════════════════════════════════════════════════════════════

    /** URL absolue de retour OAuth : doit être déclarée à l'identique dans la console Google. */
    private function callbackUrl(): string
    {
        return $this->generateUrl('app_admin_pm_drive_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function resolveParent(string $target, int $id): Project|ProjectTask|null
    {
        return match ($target) {
            'project' => $this->projectRepository->find($id),
            'task'    => $this->taskRepository->find($id),
            default   => null,
        };
    }

    /**
     * Exécute un appel Drive et le convertit en réponse JSON homogène :
     * {"ok": true, ...données} ou {"ok": false, "error": "...", "notConnected": bool}.
     *
     * @param callable(): array<string, mixed> $call
     */
    private function driveJson(callable $call): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true] + $call());
        } catch (GoogleDriveNotConnectedException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage(), 'notConnected' => true], 409);
        } catch (GoogleDriveException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage(), 'notConnected' => false], 502);
        }
    }
}

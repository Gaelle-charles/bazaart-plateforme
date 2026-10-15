<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AssociationProject\AssociationProjectData;
use App\Entity\AssociationProject;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use App\Repository\AssociationProjectRepository;
use App\Service\AssociationProject\AssociationProjectImageStorage;
use App\Service\AssociationProject\AssociationProjectPresenter;
use App\Service\AssociationProject\AssociationProjectRichText;
use App\Service\AssociationProject\AssociationProjectService;
use App\Service\AssociationProject\AssociationProjectTextImages;
use App\Service\AssociationProject\PublicAccessToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office des fiches « Projets en cours » de l'association (ADR-0039).
 *
 * Préfixe d'URL : /admin/fiches-projets (préfixe de nom de route : app_admin_asso_project_).
 *
 * ⚠️ POURQUOI PAS /admin/projets-… ?
 *   Dans security.yaml, la règle `^/admin/projets` réserve à ROLE_PROJECT tout ce
 *   qui COMMENCE par /admin/projets (l'Espace projets). Une URL /admin/projets-partenaires
 *   tomberait sous cette règle. /admin/fiches-projets relève bien de `^/admin` (ROLE_ADMIN).
 *
 * Accès : ROLE_ADMIN, vérifié deux fois (access_control `^/admin` + #[IsGranted] ici),
 * comme les autres contrôleurs d'administration.
 *
 * Même construction que les écrans admin existants : formulaires HTML simples,
 * jeton CSRF sur chaque envoi, données validées par un DTO, logique dans un service.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/fiches-projets', name: 'app_admin_asso_project_')]
class AdminAssociationProjectController extends AbstractController
{
    /** Identifiants CSRF (un par type de formulaire). */
    private const CSRF_FORM = 'asso_project_form';
    private const CSRF_REORDER = 'asso_project_reorder';
    private const CSRF_DELETE_PREFIX = 'asso_project_delete_';
    private const CSRF_TEXT_IMAGE = 'asso_project_text_image';

    public function __construct(
        private readonly AssociationProjectRepository $repository,
        private readonly AssociationProjectService $service,
        private readonly AssociationProjectImageStorage $imageStorage,
        private readonly PublicAccessToken $accessToken,
        private readonly AssociationProjectPresenter $presenter,
        private readonly AssociationProjectRichText $richText,
        private readonly AssociationProjectTextImages $textImages,
    ) {
    }

    /**
     * GET /admin/fiches-projets : liste de toutes les fiches, dans l'ordre d'affichage,
     * avec le lien secret à partager (si le jeton est configuré sur le serveur).
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // Lien à partager : généré seulement si le jeton existe. Avec un jeton vide,
        // le routeur refuserait de construire l'adresse (paramètre {token} vide).
        $shareUrl = $this->accessToken->isEnabled()
            ? $this->generateUrl('app_asso_projects_page', ['token' => $this->accessToken->value()], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;

        return $this->render('admin/association_projects/index.html.twig', [
            'projects'       => $this->repository->findAllOrdered(),
            'shareUrl'       => $shareUrl,
            'tokenTooShort'  => $this->accessToken->isTooShort(),
        ]);
    }

    /** GET|POST /admin/fiches-projets/nouvelle : création d'une fiche. */
    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new AssociationProjectData();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_FORM, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

                return $this->redirectToRoute('app_admin_asso_project_new');
            }

            $data   = AssociationProjectData::fromRequest($request);
            $errors = $data->validate();

            if ($errors === []) {
                $result = $this->service->create($data, $this->uploadedImage($request));
                if ($result instanceof AssociationProject) {
                    $this->addFlash('success', sprintf('Fiche « %s » créée.', $result->getTitle()));

                    return $this->redirectToRoute('app_admin_asso_project_edit', ['id' => $result->getId()]);
                }
                $errors[] = $result;
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->renderProjectForm(null, $data);
    }

    /** GET|POST /admin/fiches-projets/{id}/modifier : édition d'une fiche. */
    #[Route('/{id}/modifier', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(AssociationProject $project, Request $request): Response
    {
        $data = AssociationProjectData::fromEntity($project);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_FORM, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

                return $this->redirectToRoute('app_admin_asso_project_edit', ['id' => $project->getId()]);
            }

            $data   = AssociationProjectData::fromRequest($request);
            $errors = $data->validate();

            if ($errors === []) {
                $result = $this->service->update($project, $data, $this->uploadedImage($request));
                if ($result instanceof AssociationProject) {
                    $this->addFlash('success', 'Fiche enregistrée.');

                    return $this->redirectToRoute('app_admin_asso_project_edit', ['id' => $project->getId()]);
                }
                $errors[] = $result;
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->renderProjectForm($project, $data);
    }

    /**
     * GET /admin/fiches-projets/{id}/apercu : la fiche telle que la verront les
     * partenaires, MÊME SI elle n'est pas encore publiée (pour relire avant de publier).
     *
     * Même gabarit que la vraie fiche ; seules les adresses des images changent
     * (routes du back-office, réservées aux admins).
     */
    #[Route('/{id}/apercu', name: 'preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preview(AssociationProject $project): Response
    {
        $response = $this->render('association_projects/show.html.twig', [
            'project'      => $this->presenter->present($project, null, adminPreview: true),
            'token'        => null,
            'contactEmail' => AssociationProjectPublicController::CONTACT_EMAIL,
            'preview'      => [
                'published' => $project->isPublished(),
                'editUrl'   => $this->generateUrl('app_admin_asso_project_edit', ['id' => $project->getId()]),
            ],
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** POST /admin/fiches-projets/{id}/supprimer : suppression définitive (fiche, blocs, images). */
    #[Route('/{id}/supprimer', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(AssociationProject $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_DELETE_PREFIX . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

            return $this->redirectToRoute('app_admin_asso_project_index');
        }

        $title = $project->getTitle();
        $this->service->delete($project);
        $this->addFlash('success', sprintf('Fiche « %s » supprimée.', $title));

        return $this->redirectToRoute('app_admin_asso_project_index');
    }

    /** POST /admin/fiches-projets/ordre : enregistre l'ordre saisi dans la liste. */
    #[Route('/ordre', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_REORDER, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

            return $this->redirectToRoute('app_admin_asso_project_index');
        }

        $changed = $this->service->reorder($request->request->all('positions'));
        $this->addFlash('success', $changed > 0
            ? sprintf('Ordre enregistré (%d fiche(s) déplacée(s)).', $changed)
            : 'Ordre inchangé.');

        return $this->redirectToRoute('app_admin_asso_project_index');
    }

    /**
     * GET /admin/fiches-projets/{id}/visuel : aperçu du visuel dans le back-office.
     *
     * Les images sont hors du dossier public : même l'admin passe par PHP pour les voir.
     * Cache « private, no-cache » : le navigateur revalide à chaque fois, l'admin
     * voit donc tout de suite une image qu'elle vient de remplacer.
     */
    #[Route('/{id}/visuel', name: 'image', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function image(AssociationProject $project): Response
    {
        $path = $this->imageStorage->absolutePath($project->getImagePath());
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');

        return $response;
    }

    /**
     * POST /admin/fiches-projets/{id}/texte/image : image insérée dans « Le projet en
     * détail », envoyée par l'éditeur sans recharger la page.
     *
     * Réponse JSON : {name, url, width, height} ou {error}. L'image est rangée dans le
     * dossier de la fiche ; elle n'apparaît aux partenaires qu'une fois la fiche
     * enregistrée avec l'image dans son texte.
     */
    #[Route('/{id}/texte/image', name: 'text_image_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadTextImage(AssociationProject $project, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TEXT_IMAGE, (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Jeton de sécurité expiré : enregistrez la fiche puis rechargez la page.'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => "Aucune image reçue (fichier trop lourd ?). L'image doit faire moins de 5 Mo."], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $stored = $this->service->storeTextImage($project, $file);
        if (is_string($stored)) {
            return $this->json(['error' => $stored], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'name'   => $stored->path,
            'url'    => $this->textImageUrl($project, $stored->path),
            'width'  => $stored->width,
            'height' => $stored->height,
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /admin/fiches-projets/{id}/texte/{hash} : une image du texte, dans l'éditeur
     * et dans l'aperçu. Pas d'extension dans l'adresse (voir AssociationProjectTextImages::findByHash).
     */
    #[Route('/{id}/texte/{hash}', name: 'text_image', requirements: ['id' => '\d+', 'hash' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function textImage(AssociationProject $project, string $hash): Response
    {
        $name = $this->textImages->findByHash($project, $hash);
        $path = $name !== null ? $this->textImages->absolutePath($project, $name) : null;
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        // Le nom du fichier change avec l'image : le navigateur peut la garder.
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    private function renderProjectForm(?AssociationProject $project, AssociationProjectData $data): Response
    {
        // Texte de l'éditeur : TOUJOURS nettoyé (même après une erreur, quand il vient
        // du formulaire envoyé), avec l'adresse admin de chaque image.
        $detailsHtml = $project !== null
            ? $this->richText->render($data->details, fn (string $name): string => $this->textImageUrl($project, $name))
            : $this->richText->filterImages($this->richText->sanitize($data->details), static fn (string $name): bool => false);

        return $this->render('admin/association_projects/form.html.twig', [
            'project' => $project,
            'data'    => $data,
            'types'   => AssociationProjectType::cases(),
            'states'  => AssociationProjectState::cases(),
            'descriptionMax' => AssociationProject::DESCRIPTION_MAX_LENGTH,
            'detailsHtml'    => $detailsHtml,
            'detailsMax'     => AssociationProjectRichText::TEXT_MAX_LENGTH,
            'textImageCsrf'  => self::CSRF_TEXT_IMAGE,
        ]);
    }

    private function textImageUrl(AssociationProject $project, string $name): string
    {
        return $this->generateUrl('app_admin_asso_project_text_image', [
            'id'   => $project->getId(),
            'hash' => substr($name, 0, 32),
        ]);
    }

    /** Fichier « visuel » envoyé avec le formulaire, ou null si aucun. */
    private function uploadedImage(Request $request): ?UploadedFile
    {
        $file = $request->files->get('image');

        return $file instanceof UploadedFile ? $file : null;
    }
}

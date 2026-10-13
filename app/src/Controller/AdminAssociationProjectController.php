<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AssociationProject\AssociationProjectData;
use App\Entity\AssociationProject;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use App\Repository\AssociationProjectRepository;
use App\Service\AssociationProject\AssociationProjectImageStorage;
use App\Service\AssociationProject\AssociationProjectService;
use App\Service\AssociationProject\PublicAccessToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    public function __construct(
        private readonly AssociationProjectRepository $repository,
        private readonly AssociationProjectService $service,
        private readonly AssociationProjectImageStorage $imageStorage,
        private readonly PublicAccessToken $accessToken,
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

    private function renderProjectForm(?AssociationProject $project, AssociationProjectData $data): Response
    {
        return $this->render('admin/association_projects/form.html.twig', [
            'project' => $project,
            'data'    => $data,
            'types'   => AssociationProjectType::cases(),
            'states'  => AssociationProjectState::cases(),
            'descriptionMax' => AssociationProject::DESCRIPTION_MAX_LENGTH,
        ]);
    }

    /** Fichier « visuel » envoyé avec le formulaire, ou null si aucun. */
    private function uploadedImage(Request $request): ?UploadedFile
    {
        $file = $request->files->get('image');

        return $file instanceof UploadedFile ? $file : null;
    }
}

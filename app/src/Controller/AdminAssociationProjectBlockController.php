<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AssociationProject\AssociationProjectBlockData;
use App\Entity\AssociationProject;
use App\Entity\AssociationProjectBlock;
use App\Service\AssociationProject\AssociationProjectBlockService;
use App\Service\AssociationProject\AssociationProjectImageStorage;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office : page « Présentation » d'une fiche projet (ADR-0039).
 *
 * C'est ici que l'équipe écrit le texte (et ajoute parfois des images) que le
 * partenaire lit en descendant sur la fiche. La présentation est une suite de
 * BLOCS : titre facultatif + texte + image facultative avec légende.
 *
 * Même préfixe d'URL et de nom de route que AdminAssociationProjectController
 * (/admin/fiches-projets, app_admin_asso_project_) : le lien de la barre latérale
 * reste actif sur ces pages. Accès ROLE_ADMIN.
 *
 * Chaque bloc a ses propres petits formulaires (enregistrer, monter, descendre,
 * supprimer) : aucun JavaScript n'est nécessaire, chaque bouton fait un envoi
 * classique puis revient sur le bloc concerné (#bloc-12).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/fiches-projets', name: 'app_admin_asso_project_')]
class AdminAssociationProjectBlockController extends AbstractController
{
    public function __construct(
        private readonly AssociationProjectBlockService $blockService,
        private readonly AssociationProjectImageStorage $imageStorage,
    ) {
    }

    /** GET /admin/fiches-projets/{id}/presentation : liste des blocs + formulaire d'ajout. */
    #[Route('/{id}/presentation', name: 'presentation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function presentation(AssociationProject $project): Response
    {
        return $this->renderPresentation($project);
    }

    /** POST /admin/fiches-projets/{id}/presentation/blocs : ajoute un bloc à la fin. */
    #[Route('/{id}/presentation/blocs', name: 'block_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function newBlock(AssociationProject $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('asso_project_block_new_' . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

            return $this->redirectToRoute('app_admin_asso_project_presentation', ['id' => $project->getId()]);
        }

        $data   = AssociationProjectBlockData::fromRequest($request);
        $image  = $this->uploadedImage($request);
        $errors = $data->validate($image !== null);

        if ($errors === []) {
            $result = $this->blockService->create($project, $data, $image);
            if ($result instanceof AssociationProjectBlock) {
                $this->addFlash('success', 'Bloc ajouté.');

                return $this->redirectToBlock($project, $result);
            }
            $errors[] = $result;
        }

        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        // On ré-affiche la page AVEC le texte saisi : rien n'est perdu en cas d'erreur.
        return $this->renderPresentation($project, newData: $data);
    }

    /** POST /admin/fiches-projets/blocs/{blockId} : enregistre un bloc modifié. */
    #[Route('/blocs/{blockId}', name: 'block_update', requirements: ['blockId' => '\d+'], methods: ['POST'])]
    public function updateBlock(#[MapEntity(id: 'blockId')] AssociationProjectBlock $block, Request $request): Response
    {
        $project = $block->getProject();
        if (!$this->isBlockTokenValid($block, $request)) {
            return $this->redirectToBlock($project, $block);
        }

        $data  = AssociationProjectBlockData::fromRequest($request);
        $image = $this->uploadedImage($request);
        // Le bloc aura-t-il une image après l'envoi ? (nouvelle image, ou ancienne gardée)
        $willHaveImage = $image !== null || ($block->getImagePath() !== null && !$data->removeImage);
        $errors = $data->validate($willHaveImage);

        if ($errors === []) {
            $result = $this->blockService->update($block, $data, $image);
            if ($result instanceof AssociationProjectBlock) {
                $this->addFlash('success', 'Bloc enregistré.');

                return $this->redirectToBlock($project, $block);
            }
            $errors[] = $result;
        }

        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->renderPresentation($project, editedBlockId: $block->getId(), editedData: $data);
    }

    /** POST /admin/fiches-projets/blocs/{blockId}/deplacer : monte ou descend un bloc. */
    #[Route('/blocs/{blockId}/deplacer', name: 'block_move', requirements: ['blockId' => '\d+'], methods: ['POST'])]
    public function moveBlock(#[MapEntity(id: 'blockId')] AssociationProjectBlock $block, Request $request): Response
    {
        if ($this->isBlockTokenValid($block, $request)) {
            $direction = $request->request->get('direction') === 'up' ? -1 : 1;
            $this->blockService->move($block, $direction);
        }

        return $this->redirectToBlock($block->getProject(), $block);
    }

    /** POST /admin/fiches-projets/blocs/{blockId}/supprimer : supprime un bloc et son image. */
    #[Route('/blocs/{blockId}/supprimer', name: 'block_delete', requirements: ['blockId' => '\d+'], methods: ['POST'])]
    public function deleteBlock(#[MapEntity(id: 'blockId')] AssociationProjectBlock $block, Request $request): Response
    {
        $project = $block->getProject();
        if ($this->isBlockTokenValid($block, $request)) {
            $this->blockService->delete($block);
            $this->addFlash('success', 'Bloc supprimé.');
        }

        return $this->redirectToRoute('app_admin_asso_project_presentation', ['id' => $project->getId()]);
    }

    /**
     * GET /admin/fiches-projets/blocs/{blockId}/image : aperçu de l'image d'un bloc
     * dans le back-office (fichier hors du dossier public, servi par PHP).
     */
    #[Route('/blocs/{blockId}/image', name: 'block_image', requirements: ['blockId' => '\d+'], methods: ['GET'])]
    public function blockImage(#[MapEntity(id: 'blockId')] AssociationProjectBlock $block): Response
    {
        $path = $this->imageStorage->absolutePath($block->getImagePath());
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
     * Affiche la page Présentation.
     *
     * @param AssociationProjectBlockData|null $newData    texte saisi dans « Ajouter un bloc » (après une erreur)
     * @param int|null                         $editedBlockId bloc dont le formulaire a été refusé
     * @param AssociationProjectBlockData|null $editedData texte saisi dans ce bloc (après une erreur)
     */
    private function renderPresentation(
        AssociationProject $project,
        ?AssociationProjectBlockData $newData = null,
        ?int $editedBlockId = null,
        ?AssociationProjectBlockData $editedData = null,
    ): Response {
        // Données de chaque formulaire de bloc : celles de la BDD, sauf pour le bloc
        // refusé, qui ré-affiche ce que l'admin venait de saisir.
        $blockForms = [];
        foreach ($project->getBlocks() as $block) {
            $blockForms[(int) $block->getId()] = $block->getId() === $editedBlockId && $editedData !== null
                ? $editedData
                : AssociationProjectBlockData::fromEntity($block);
        }

        return $this->render('admin/association_projects/presentation.html.twig', [
            'project'    => $project,
            'blocks'     => $project->getBlocks(),
            'blockForms' => $blockForms,
            'newData'    => $newData ?? new AssociationProjectBlockData(),
        ]);
    }

    private function isBlockTokenValid(AssociationProjectBlock $block, Request $request): bool
    {
        if ($this->isCsrfTokenValid('asso_project_block_' . $block->getId(), (string) $request->request->get('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

        return false;
    }

    private function redirectToBlock(AssociationProject $project, AssociationProjectBlock $block): Response
    {
        return $this->redirect(
            $this->generateUrl('app_admin_asso_project_presentation', ['id' => $project->getId()]) . '#bloc-' . $block->getId()
        );
    }

    private function uploadedImage(Request $request): ?UploadedFile
    {
        $file = $request->files->get('image');

        return $file instanceof UploadedFile ? $file : null;
    }
}

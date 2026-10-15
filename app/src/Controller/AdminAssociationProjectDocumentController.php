<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AssociationProject;
use App\Entity\AssociationProjectDocument;
use App\Service\AssociationProject\AssociationProjectDocumentStorage;
use App\Service\AssociationProject\AssociationProjectService;
use App\Service\AssociationProject\SlugGenerator;
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
 * Back-office : page « Documents » d'une fiche projet (ADR-0039, complément d'octobre 2026).
 *
 * Les documents PDF (dossier de présentation, budget prévisionnel…) s'affichent
 * dans « En pratique » sur la fiche. Une page à part, comme « Présentation » :
 * chaque envoi de PDF est indépendant du formulaire de la fiche, donc un gros
 * fichier refusé ne fait jamais perdre ce qui a été saisi dans la fiche.
 *
 * Même préfixe d'URL et de nom de route que AdminAssociationProjectController.
 * Accès ROLE_ADMIN. Chaque bouton fait un envoi classique (aucun JavaScript
 * nécessaire, sauf la confirmation avant suppression).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/fiches-projets', name: 'app_admin_asso_project_')]
class AdminAssociationProjectDocumentController extends AbstractController
{
    public function __construct(
        private readonly AssociationProjectService $service,
        private readonly AssociationProjectDocumentStorage $documentStorage,
    ) {
    }

    /** GET /admin/fiches-projets/{id}/documents : liste des documents + formulaire d'ajout. */
    #[Route('/{id}/documents', name: 'documents', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documents(AssociationProject $project): Response
    {
        return $this->render('admin/association_projects/documents.html.twig', [
            'project'   => $project,
            'documents' => $project->getDocuments(),
            'maxMb'     => intdiv(AssociationProjectDocumentStorage::MAX_SIZE_BYTES, 1024 * 1024),
            'labelMax'  => AssociationProjectDocument::LABEL_MAX_LENGTH,
        ]);
    }

    /** POST /admin/fiches-projets/{id}/documents : ajoute un PDF à la fin de la liste. */
    #[Route('/{id}/documents', name: 'document_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function newDocument(AssociationProject $project, Request $request): Response
    {
        // Un envoi plus lourd que la limite du serveur (post_max_size) arrive VIDE :
        // ni fichier, ni jeton. On le dit clairement plutôt que « jeton expiré ».
        if ($request->request->count() === 0 && $request->files->count() === 0 && (int) $request->server->get('CONTENT_LENGTH') > 0) {
            $this->addFlash('error', sprintf('Le document est trop lourd : %d Mo maximum.', intdiv(AssociationProjectDocumentStorage::MAX_SIZE_BYTES, 1024 * 1024)));

            return $this->redirectToDocuments($project);
        }

        if (!$this->isCsrfTokenValid('asso_project_document_new_' . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

            return $this->redirectToDocuments($project);
        }

        $file = $request->files->get('document');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Choisis un fichier PDF à envoyer.');

            return $this->redirectToDocuments($project);
        }

        $result = $this->service->addDocument($project, $file, (string) $request->request->get('label', ''));
        if (is_string($result)) {
            $this->addFlash('error', $result);
        } else {
            $this->addFlash('success', sprintf('Document « %s » ajouté.', $result->getLabel()));
        }

        return $this->redirectToDocuments($project);
    }

    /** POST /admin/fiches-projets/documents/{documentId} : modifie l'intitulé d'un document. */
    #[Route('/documents/{documentId}', name: 'document_update', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function updateDocument(#[MapEntity(id: 'documentId')] AssociationProjectDocument $document, Request $request): Response
    {
        if ($this->isDocumentTokenValid($document, $request)) {
            $error = $this->service->renameDocument($document, (string) $request->request->get('label', ''));
            $this->addFlash($error === null ? 'success' : 'error', $error ?? 'Intitulé enregistré.');
        }

        return $this->redirectToDocuments($document->getProject());
    }

    /** POST /admin/fiches-projets/documents/{documentId}/deplacer : monte ou descend un document. */
    #[Route('/documents/{documentId}/deplacer', name: 'document_move', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function moveDocument(#[MapEntity(id: 'documentId')] AssociationProjectDocument $document, Request $request): Response
    {
        if ($this->isDocumentTokenValid($document, $request)) {
            $this->service->moveDocument($document, $request->request->get('direction') === 'up' ? -1 : 1);
        }

        return $this->redirectToDocuments($document->getProject());
    }

    /** POST /admin/fiches-projets/documents/{documentId}/supprimer : supprime le document et son fichier. */
    #[Route('/documents/{documentId}/supprimer', name: 'document_delete', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function deleteDocument(#[MapEntity(id: 'documentId')] AssociationProjectDocument $document, Request $request): Response
    {
        $project = $document->getProject();
        if ($this->isDocumentTokenValid($document, $request)) {
            $label = $document->getLabel();
            $this->service->deleteDocument($document);
            $this->addFlash('success', sprintf('Document « %s » supprimé.', $label));
        }

        return $this->redirectToDocuments($project);
    }

    /**
     * GET /admin/fiches-projets/documents/{documentId}/fichier : ouvre le PDF dans
     * le back-office (fichier hors du dossier public, servi par PHP).
     */
    #[Route('/documents/{documentId}/fichier', name: 'document_file', requirements: ['documentId' => '\d+'], methods: ['GET'])]
    public function documentFile(#[MapEntity(id: 'documentId')] AssociationProjectDocument $document): Response
    {
        $path = $this->documentStorage->absolutePath($document->getPath());
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        return self::pdfResponse($path, $document->getLabel());
    }

    /**
     * Réponse « PDF » commune au back-office et à la page partenaires : affiché dans
     * le navigateur (inline), sous un nom lisible tiré de l'intitulé.
     */
    public static function pdfResponse(string $absolutePath, string $label): BinaryFileResponse
    {
        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', 'application/pdf');
        $filename = SlugGenerator::slugify($label) . '.pdf';
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename, $filename);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');

        return $response;
    }

    private function isDocumentTokenValid(AssociationProjectDocument $document, Request $request): bool
    {
        if ($this->isCsrfTokenValid('asso_project_document_' . $document->getId(), (string) $request->request->get('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');

        return false;
    }

    private function redirectToDocuments(AssociationProject $project): Response
    {
        return $this->redirectToRoute('app_admin_asso_project_documents', ['id' => $project->getId()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CreatorPayoutProfile;
use App\Entity\User;
use App\Repository\CreatorPayoutProfileRepository;
use App\Service\CreatorDocumentStorage;
use App\Service\CreatorPayoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminCreatorPayoutController — gestion admin des profils de versement créateur (ADR-0027).
 *
 * SÉCURITÉ :
 *   - #[IsGranted('ROLE_ADMIN')] sur la classe : toutes les routes nécessitent ROLE_ADMIN.
 *   - La route /document/{id} streame le fichier de pièce d'identité via BinaryFileResponse
 *     SANS jamais exposer l'URL réelle du fichier (hors public/).
 *   - Jamais de lien direct vers le fichier dans les templates.
 *
 * Préfixe : /admin/versements (cohérent avec la sidebar admin existante).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/versements', name: 'app_admin_creator_payout_')]
class AdminCreatorPayoutController extends AbstractController
{
    public function __construct(
        private readonly CreatorPayoutProfileRepository $profileRepository,
        private readonly CreatorPayoutService $payoutService,
        private readonly CreatorDocumentStorage $documentStorage,
    ) {}

    /**
     * Liste tous les profils de versement, priorité aux Pending.
     *
     * GET /admin/versements
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/creator_payouts.html.twig', [
            // Profils en attente de vérification (triés par date croissante : FIFO)
            'pending'  => $this->profileRepository->findPending(),
            // Profils déjà traités (triés par date de mise à jour décroissante : récents d'abord)
            'reviewed' => $this->profileRepository->findReviewed(),
        ]);
    }

    /**
     * Streame la pièce d'identité d'un créateur vers le navigateur de l'admin.
     *
     * GET /admin/versements/{id}/document
     *
     * POURQUOI STREAMER PLUTÔT QUE LIEN DIRECT ?
     * Le fichier est stocké dans var/secure_uploads/ (hors public/).
     * Nginx ne peut pas servir ce dossier directement.
     * PHP lit le fichier et le renvoie au navigateur en tant que téléchargement sécurisé.
     *
     * BinaryFileResponse = réponse Symfony pour les fichiers binaires :
     *   - Gère automatiquement les en-têtes Content-Type, Content-Length, ETag.
     *   - Supporte les requêtes partielles (Range pour les PDF dans le navigateur).
     *   - Content-Disposition: inline → le navigateur affiche le PDF au lieu de le télécharger.
     */
    #[Route('/{id}/document', name: 'document', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function document(CreatorPayoutProfile $profile): Response
    {
        // Vérifie qu'une pièce d'identité a bien été uploadée
        if ($profile->getIdentityDocumentPath() === null) {
            throw $this->createNotFoundException('Aucun document disponible pour ce profil.');
        }

        // Obtient le chemin absolu sur le disque à partir du chemin relatif stocké en BDD
        $absolutePath = $this->documentStorage->getAbsolutePath($profile->getIdentityDocumentPath());

        // Vérifie que le fichier existe physiquement (protection contre les données orphelines)
        if (!$this->documentStorage->exists($profile->getIdentityDocumentPath())) {
            throw $this->createNotFoundException(
                'Le fichier de pièce d\'identité est introuvable sur le disque. Contacte un développeur.'
            );
        }

        // Construit le nom de fichier lisible pour le téléchargement admin
        // Format : "doc_userId_nomOriginal" (ex : "doc_42_CNI_dupont.pdf")
        $downloadName = sprintf(
            'doc_user%d_%s',
            $profile->getUser()->getId() ?? 0,
            $profile->getIdentityDocumentOriginalName() ?? 'document'
        );

        // BinaryFileResponse : Symfony lit et streame le fichier
        // DISPOSITION_INLINE → affiche le fichier dans le navigateur (pas de force-download)
        //   → pratique pour les PDF et images : l'admin peut les voir directement
        // makeDisposition() génère l'en-tête Content-Disposition standard
        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $downloadName
        );

        // En-têtes de sécurité supplémentaires :
        // Cache-Control: private → le fichier ne doit jamais être mis en cache par un proxy
        // no-store → le navigateur ne doit pas conserver ce fichier en cache local
        $response->headers->set('Cache-Control', 'private, no-store');
        // X-Content-Type-Options: nosniff → empêche le navigateur de "deviner" le type MIME
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Valide un profil de versement.
     *
     * POST /admin/versements/{id}/valider
     */
    #[Route('/{id}/valider', name: 'verify', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function verify(CreatorPayoutProfile $profile, Request $request): Response
    {
        // Vérification CSRF : token 'payout_review_{id}' partagé avec la route reject()
        // (un seul formulaire avec deux boutons d'action dans le template admin)
        if (!$this->isCsrfTokenValid('payout_review_' . $profile->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_creator_payout_index');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $this->payoutService->verify($profile, $admin);

        $this->addFlash('success', 'Profil de versement validé. Le créateur a été notifié par email.');
        return $this->redirectToRoute('app_admin_creator_payout_index');
    }

    /**
     * Refuse un profil de versement avec un motif.
     *
     * POST /admin/versements/{id}/refuser
     */
    #[Route('/{id}/refuser', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(CreatorPayoutProfile $profile, Request $request): Response
    {
        // Même token CSRF que verify() (formulaire partagé, deux boutons)
        if (!$this->isCsrfTokenValid('payout_review_' . $profile->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_creator_payout_index');
        }

        $reason = trim($request->request->getString('rejectionReason'));
        if ($reason === '') {
            $this->addFlash('error', 'Merci de préciser un motif de refus (obligatoire pour que le créateur puisse corriger).');
            return $this->redirectToRoute('app_admin_creator_payout_index');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $this->payoutService->reject($profile, $admin, $reason);

        $this->addFlash('success', 'Profil refusé. Le créateur a été notifié avec le motif.');
        return $this->redirectToRoute('app_admin_creator_payout_index');
    }
}

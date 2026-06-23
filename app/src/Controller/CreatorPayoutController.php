<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Creator\PayoutProfileDTO;
use App\Entity\CreatorPayoutProfile;
use App\Entity\User;
use App\Repository\CreatorPayoutProfileRepository;
use App\Service\CreatorPayoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CreatorPayoutController — espace membre "Mes coordonnées de versement" (ADR-0027, Lot 1).
 *
 * Tout membre connecté (ROLE_USER) peut :
 *   - Consulter ses coordonnées bancaires actuelles et le statut de vérification.
 *   - Soumettre ou mettre à jour ses coordonnées (IBAN, BIC, SIRET, titulaire, pièce d'identité).
 *
 * Convention du projet : aucune logique métier dans le contrôleur.
 * Le contrôleur orchestre uniquement : Request → Service → Template.
 * La logique (validation, stockage fichier, email, statut) est dans CreatorPayoutService.
 */
#[IsGranted('ROLE_USER')]
class CreatorPayoutController extends AbstractController
{
    public function __construct(
        private readonly CreatorPayoutProfileRepository $profileRepository,
        private readonly CreatorPayoutService $payoutService,
    ) {}

    /**
     * Affiche et traite le formulaire de coordonnées de versement.
     *
     * GET  /compte/versement → affiche le formulaire (pré-rempli si profil existant)
     * POST /compte/versement → traite la soumission
     *
     * Le même contrôleur gère GET et POST (pattern "PRG simplifié" :
     * Symfony recommande POST/Redirect/Get en production pour éviter la re-soumission
     * du formulaire si l'utilisateur recharge la page après un POST réussi).
     */
    #[Route('/compte/versement', name: 'app_creator_payout', methods: ['GET', 'POST'])]
    public function payout(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Charge le profil existant (ou null si première visite)
        $profile = $this->profileRepository->findByUser($user);

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $user, $profile);
        }

        // GET : affiche le formulaire pré-rempli depuis l'entité existante
        return $this->renderForm($profile);
    }

    /**
     * Traite la soumission POST du formulaire.
     *
     * Séparé dans une méthode privée pour garder payout() lisible.
     * Applique le pattern POST → Redirect → GET pour éviter la re-soumission.
     */
    private function handlePost(Request $request, User $user, ?CreatorPayoutProfile $profile): Response
    {
        // ── Vérification CSRF ──────────────────────────────────────────────────
        // Le token CSRF protège contre les attaques CSRF (Cross-Site Request Forgery) :
        // sans ce token, une page malveillante pourrait déclencher un POST au nom de l'utilisateur.
        // Le token est unique par formulaire (nom : 'payout_form') et par session utilisateur.
        if (!$this->isCsrfTokenValid('payout_form', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Recharge la page et réessaie.');
            return $this->redirectToRoute('app_creator_payout');
        }

        // ── Construction du DTO depuis la requête POST ─────────────────────────
        // On hydrate le DTO manuellement (pas de Form Type Symfony) pour rester simple
        // et garder le contrôle sur la validation de l'upload.
        $dto = new PayoutProfileDTO();
        $dto->iban               = $request->request->getString('iban');
        $dto->bic                = $request->request->getString('bic') ?: null;
        $dto->siret              = $request->request->getString('siret');
        $dto->accountHolderName  = $request->request->getString('accountHolderName');

        // ── Récupération du fichier uploadé (optionnel) ────────────────────────
        // $request->files->get('identityDocument') retourne un UploadedFile ou null.
        // null si l'utilisateur n'a pas sélectionné de nouveau fichier.
        $uploadedFile = $request->files->get('identityDocument');
        if ($uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $dto->identityDocument = $uploadedFile;
        }

        // ── Délégation au service ─────────────────────────────────────────────
        $result = $this->payoutService->save($user, $dto);

        if (is_string($result)) {
            // Erreur de validation ou d'upload → message d'erreur retourné par le service
            $this->addFlash('error', $result);
            // On re-affiche le formulaire avec les valeurs saisies (depuis $dto, pas $profile)
            return $this->renderForm($profile, $dto);
        }

        // Succès → redirection GET pour éviter la re-soumission si l'utilisateur recharge
        $this->addFlash('success', 'Tes coordonnées de versement ont bien été enregistrées. L\'équipe Bazaart va vérifier tes informations.');
        return $this->redirectToRoute('app_creator_payout');
    }

    /**
     * Prépare les variables pour le template et rend la réponse.
     *
     * @param PayoutProfileDTO|null $dto Données du POST en cas d'erreur (pour pré-remplir avec ce que l'user a saisi)
     */
    private function renderForm(?CreatorPayoutProfile $profile, ?PayoutProfileDTO $dto = null): Response
    {
        return $this->render('creator/payout_form.html.twig', [
            // $profile : profil existant en BDD (null si première visite)
            'profile' => $profile,
            // $dto : données du POST en cas d'erreur (pré-rempli avec ce que l'user a saisi)
            // Si null, le template utilisera les valeurs de $profile (ou des champs vides)
            'dto'     => $dto,
        ]);
    }
}

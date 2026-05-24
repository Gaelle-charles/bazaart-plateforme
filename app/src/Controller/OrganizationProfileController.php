<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\OrganizationProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/profile/organization', name: 'app_org_profile_')]
class OrganizationProfileController extends AbstractController
{
    public function __construct(
        private readonly OrganizationProfileService $profileService,
    ) {}

    /**
     * Affiche le profil de l'organisation de l'utilisateur connecté.
     * Si pas encore de profil, redirige vers l'édition.
     */
    #[Route('', name: 'show')]
    public function show(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $profile = $user->getOrganizationProfile();

        if ($profile === null) {
            $this->addFlash('info', 'Créez le profil de votre organisation pour soumettre des ressources.');
            return $this->redirectToRoute('app_org_profile_edit');
        }

        return $this->render('organization_profile/show.html.twig', [
            'profile' => $profile,
        ]);
    }

    /**
     * Formulaire de création / modification du profil organisation.
     */
    #[Route('/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $profile = $user->getOrganizationProfile();

        if ($request->isMethod('POST')) {
            // ── Validation CSRF ──────────────────────────────────────────────
            // On vérifie en priorité absolue que le token soumis correspond
            // au token généré pour cet utilisateur et cette action.
            // isCsrfTokenValid() compare le token reçu avec celui stocké en session ;
            // si quelqu'un soumet ce formulaire depuis un autre site, le token sera absent
            // ou invalide → on rejette la requête avant tout traitement de données.
            if (!$this->isCsrfTokenValid('org_profile_edit', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_org_profile_edit');
            }

            $logoFile = $request->files->get('logo');

            // saveProfile retourne soit le profil, soit un message d'erreur (SIRET invalide)
            $result = $this->profileService->saveProfile(
                $user,
                $request->request->all(),
                $logoFile
            );

            if (is_string($result)) {
                // C'est une erreur de validation.
                // On renvoie les données saisies (formData) pour que le template
                // puisse re-remplir les champs sans que l'utilisateur retape tout.
                return $this->render('organization_profile/edit.html.twig', [
                    'profile'  => $profile,
                    'error'    => $result,
                    'formData' => $request->request->all(),
                ]);
            }

            $this->addFlash('success', 'Profil organisation mis à jour.');
            return $this->redirectToRoute('app_org_profile_show');
        }

        return $this->render('organization_profile/edit.html.twig', [
            'profile'  => $profile,
            'error'    => null,
            'formData' => [],
        ]);
    }
}

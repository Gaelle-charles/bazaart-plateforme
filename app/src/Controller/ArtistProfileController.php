<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Service\ArtistProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gère les pages liées au profil artiste.
 * #[IsGranted('ROLE_USER')] = toutes les routes de ce controller nécessitent d'être connecté.
 */
#[IsGranted('ROLE_USER')]
#[Route('/profile/artist', name: 'app_artist_profile_')]
class ArtistProfileController extends AbstractController
{
    public function __construct(
        private readonly ArtistProfileService $profileService,
        private readonly ArtistProfileRepository $profileRepository,
    ) {}

    /**
     * Affiche le profil artiste de l'utilisateur connecté.
     * Si il n'a pas encore de profil, redirige vers la page d'édition.
     */
    #[Route('', name: 'show')]
    public function show(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $profile = $user->getArtistProfile();

        // Pas encore de profil → on invite à en créer un
        if ($profile === null) {
            $this->addFlash('info', 'Complétez votre profil artiste pour apparaître dans l\'annuaire.');
            return $this->redirectToRoute('app_artist_profile_edit');
        }

        return $this->render('artist_profile/show.html.twig', [
            'profile' => $profile,
        ]);
    }

    /**
     * Formulaire de création / modification du profil artiste.
     * GET  → affiche le formulaire pré-rempli si un profil existe déjà.
     * POST → traite les données soumises.
     */
    #[Route('/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $profile = $user->getArtistProfile();

        if ($request->isMethod('POST')) {
            // Récupère le fichier avatar s'il a été envoyé
            // files->get() retourne null si aucun fichier n'a été uploadé
            $avatarFile = $request->files->get('avatar');

            $this->profileService->saveProfile(
                $user,
                $request->request->all(), // tous les champs texte du formulaire
                $avatarFile
            );

            $this->addFlash('success', 'Profil mis à jour avec succès.');
            return $this->redirectToRoute('app_artist_profile_show');
        }

        return $this->render('artist_profile/edit.html.twig', [
            'profile' => $profile, // null si nouveau profil, objet si existant
        ]);
    }

    /**
     * Annuaire public des artistes — liste tous les profils.
     * Permet de découvrir les artistes présents sur la plateforme.
     */
    #[Route('/directory', name: 'directory')]
    public function directory(): Response
    {
        // Charge tous les profils artistes triés alphabétiquement
        $profiles = $this->profileRepository->findAllForDirectory();

        return $this->render('artist_profile/directory.html.twig', [
            'profiles' => $profiles,
        ]);
    }

    /**
     * Affiche le profil public d'un artiste par son ID.
     * Accessible à tous les membres connectés (pas seulement le propriétaire).
     */
    #[Route('/{id}', name: 'public', requirements: ['id' => '\d+'])]
    public function publicShow(int $id): Response
    {
        // On cherche le profil par son ID
        $profile = $this->profileRepository->find($id);

        if ($profile === null) {
            throw $this->createNotFoundException('Profil artiste introuvable.');
        }

        // On réutilise le même template que le profil personnel.
        // Le template gère lui-même l'affichage du bouton "Modifier" selon le propriétaire.
        return $this->render('artist_profile/show.html.twig', [
            'profile' => $profile,
        ]);
    }
}

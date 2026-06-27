<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\Country;
use App\Repository\ArtistProfileRepository;
use App\Repository\DisciplineRepository;
use App\Security\Voter\FreemiumVoter;
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
        // Injecté pour alimenter la liste des disciplines dans le formulaire d'édition
        // et les filtres de l'annuaire (prêt pour V1).
        private readonly DisciplineRepository $disciplineRepository,
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
            // Validation CSRF — protège contre les modifications forgées depuis un autre site
            // (attaque Cross-Site Request Forgery : un site tiers qui soumet le formulaire
            // au nom de l'utilisateur connecté sans son consentement).
            // Le token '_token' doit être présent dans le formulaire Twig via csrf_token('artist_profile_edit').
            if (!$this->isCsrfTokenValid('artist_profile_edit', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_artist_profile_edit');
            }

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

        // Charge toutes les disciplines disponibles triées alphabétiquement.
        // Elles sont passées au template pour construire la grille de checkboxes.
        $allDisciplines = $this->disciplineRepository->findBy([], ['name' => 'ASC']);

        return $this->render('artist_profile/edit.html.twig', [
            'profile'        => $profile,        // null si nouveau profil, objet si existant
            'allDisciplines' => $allDisciplines, // pour la section "Disciplines artistiques"
            // Liste des pays regroupés par optgroup pour le <select> de localisation.
            // casesGroupedFr() retourne array<string, list<Country>> :
            //   'France et Outre-mer' → [France, Guadeloupe, Guyane, ...]
            //   'Europe'              → [Albanie, Allemagne, ...]
            // Le template artist_profile/edit.html.twig itère sur ces groupes
            // pour rendre des <optgroup> dans le <select name="location">.
            'countries'      => Country::casesGroupedFr(),
        ]);
    }

    /**
     * Annuaire public des artistes — liste tous les profils.
     * Permet de découvrir les artistes présents sur la plateforme.
     *
     * PAYWALL (ADR-0022, Lot D) : réservé aux abonnés et aux admins.
     * Les utilisateurs gratuits sont redirigés vers /tarifs avec un flash message.
     */
    #[Route('/directory', name: 'directory')]
    public function directory(): Response
    {
        // ── Vérification du paywall freemium : annuaire artistes réservé aux abonnés ──
        if (!$this->isGranted(FreemiumVoter::FEATURE_DIRECTORY)) {
            $this->addFlash(
                'info',
                'L\'annuaire des artistes est réservé aux abonnés Bazaart. Découvrez nos offres ci-dessous.'
            );
            return $this->redirectToRoute('app_pricing');
        }

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

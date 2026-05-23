<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtistProfile;
use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Contient toute la logique métier liée au profil artiste.
 * Le controller ne fait qu'appeler ce service — il ne contient pas de logique.
 */
class ArtistProfileService
{
    // Dossier de stockage des avatars, relatif à public/
    private const AVATAR_DIR = 'uploads/avatars';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArtistProfileRepository $repository,
        // %kernel.project_dir% est injecté via services.yaml automatiquement
        private readonly string $projectDir,
    ) {}

    /**
     * Crée ou met à jour le profil artiste d'un utilisateur.
     *
     * Si l'utilisateur n'a pas encore de profil, on en crée un nouveau.
     * Sinon, on met à jour le profil existant.
     */
    public function saveProfile(User $user, array $data, ?UploadedFile $avatarFile): ArtistProfile
    {
        // Récupère le profil existant ou en crée un nouveau
        $profile = $this->repository->findByUser($user) ?? new ArtistProfile();

        // Associe l'utilisateur si c'est un nouveau profil
        if ($profile->getId() === null) {
            $profile->setUser($user);
        }

        // Hydratation des champs simples
        $profile->setDisplayName(trim($data['displayName'] ?? ''));
        $profile->setBio(!empty($data['bio']) ? trim($data['bio']) : null);
        $profile->setLocation(!empty($data['location']) ? trim($data['location']) : null);
        $profile->setWebsiteUrl(!empty($data['websiteUrl']) ? trim($data['websiteUrl']) : null);
        $profile->setPortfolioUrl(!empty($data['portfolioUrl']) ? trim($data['portfolioUrl']) : null);

        // Construit le tableau socialLinks depuis les champs du formulaire
        $profile->setSocialLinks($this->buildSocialLinks($data));

        // Gestion de l'avatar uploadé
        if ($avatarFile !== null) {
            // Supprime l'ancien avatar si il existe
            $this->deleteAvatar($profile->getAvatarPath());

            // Génère un nom de fichier unique pour éviter les collisions
            $filename = uniqid('avatar_') . '.' . $avatarFile->guessExtension();
            $avatarFile->move($this->projectDir . '/public/' . self::AVATAR_DIR, $filename);
            $profile->setAvatarPath(self::AVATAR_DIR . '/' . $filename);
        }

        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    /**
     * Assemble le tableau JSON des réseaux sociaux depuis les champs du formulaire.
     * N'inclut que les valeurs non vides.
     */
    private function buildSocialLinks(array $data): ?array
    {
        $links = [];
        $networks = ['instagram', 'linkedin', 'twitter', 'facebook', 'youtube'];

        foreach ($networks as $network) {
            $key = 'social_' . $network;
            if (!empty($data[$key])) {
                $links[$network] = trim($data[$key]);
            }
        }

        return empty($links) ? null : $links;
    }

    /**
     * Supprime le fichier avatar du disque si il existe.
     */
    private function deleteAvatar(?string $avatarPath): void
    {
        if ($avatarPath === null) {
            return;
        }

        $fullPath = $this->projectDir . '/public/' . $avatarPath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}

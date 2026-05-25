<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtistProfile;
use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\DisciplineRepository;
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
        private readonly DisciplineRepository $disciplineRepository,
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

        // ── Gestion des disciplines ──────────────────────────────────────────
        // Le formulaire envoie un tableau d'IDs via disciplines[] (checkboxes).
        // On commence par retirer toutes les disciplines actuellement associées,
        // puis on recharge uniquement celles cochées par l'utilisateur.
        // C'est la stratégie "clear + re-add" : simple et sans risque de doublon.
        $this->syncDisciplines($profile, $data['disciplines'] ?? []);

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
     * Synchronise les disciplines du profil artiste avec les IDs envoyés par le formulaire.
     *
     * Stratégie "clear + re-add" :
     *   1. On retire toutes les disciplines existantes du profil.
     *   2. On charge chaque Discipline depuis la BDD par son ID (findAll éviterait N+1,
     *      mais le nombre de disciplines reste limité — on garde find() pour la lisibilité).
     *   3. On ajoute les disciplines valides (celles trouvées en BDD).
     *
     * Les IDs inconnus ou invalides sont simplement ignorés (find() retourne null).
     *
     * @param ArtistProfile $profile  Profil à mettre à jour
     * @param mixed[]       $ids      Tableau d'IDs de disciplines (chaînes ou entiers)
     */
    private function syncDisciplines(ArtistProfile $profile, array $ids): void
    {
        // Étape 1 : retirer toutes les disciplines actuelles
        // On itère sur une copie (toArray) pour éviter de modifier la collection
        // pendant qu'on l'itère — comportement indéfini avec Doctrine.
        foreach ($profile->getDisciplines()->toArray() as $discipline) {
            $profile->removeDiscipline($discipline);
        }

        // Étape 2 : ajouter les nouvelles disciplines
        foreach ($ids as $id) {
            // Sécurité : s'assurer que l'ID est un entier valide
            $id = (int) $id;
            if ($id <= 0) {
                continue; // Ignorer les IDs invalides (0, négatifs, chaînes vides)
            }

            $discipline = $this->disciplineRepository->find($id);
            if ($discipline !== null) {
                // addDiscipline() vérifie en interne les doublons via contains()
                $profile->addDiscipline($discipline);
            }
        }
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

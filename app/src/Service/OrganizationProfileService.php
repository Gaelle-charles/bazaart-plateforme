<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OrganizationProfile;
use App\Entity\User;
use App\Repository\OrganizationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Logique métier pour le profil organisation.
 * Gère la validation du SIRET, l'upload de logo, et la persistance.
 */
class OrganizationProfileService
{
    private const LOGO_DIR = 'uploads/logos';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizationProfileRepository $repository,
        private readonly string $projectDir,
    ) {}

    /**
     * Crée ou met à jour le profil organisation d'un utilisateur.
     * Retourne le profil sauvegardé, ou une chaîne d'erreur si le SIRET est invalide.
     */
    public function saveProfile(User $user, array $data, ?UploadedFile $logoFile): OrganizationProfile|string
    {
        // Validation du SIRET si renseigné
        if (!empty($data['siret']) && !$this->isValidSiret($data['siret'])) {
            return 'Le numéro SIRET doit contenir exactement 14 chiffres.';
        }

        $profile = $this->repository->findByUser($user) ?? new OrganizationProfile();

        if ($profile->getId() === null) {
            $profile->setUser($user);
        }

        $profile->setName(trim($data['name'] ?? ''));
        $profile->setSiret(!empty($data['siret']) ? $data['siret'] : null);
        $profile->setDescription(!empty($data['description']) ? trim($data['description']) : null);
        $profile->setWebsiteUrl(!empty($data['websiteUrl']) ? trim($data['websiteUrl']) : null);
        $profile->setContactEmail(!empty($data['contactEmail']) ? trim($data['contactEmail']) : null);
        $profile->setLocation(!empty($data['location']) ? trim($data['location']) : null);

        // Gestion du logo uploadé
        if ($logoFile !== null) {
            $this->deleteLogo($profile->getLogoPath());
            $filename = uniqid('logo_') . '.' . $logoFile->guessExtension();
            $logoFile->move($this->projectDir . '/public/' . self::LOGO_DIR, $filename);
            $profile->setLogoPath(self::LOGO_DIR . '/' . $filename);
        }

        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    /**
     * Valide le format d'un numéro SIRET.
     *
     * Un SIRET est composé de 14 chiffres : SIREN (9) + NIC (5).
     * On ne vérifie que le format ici — la vérification légale est faite manuellement par un admin.
     */
    public function isValidSiret(string $siret): bool
    {
        // Supprime les espaces et tirets éventuels (ex: "123 456 789 01234")
        $siret = preg_replace('/\D/', '', $siret);

        // Un SIRET valide contient exactement 14 chiffres
        return strlen($siret) === 14;
    }

    /**
     * Supprime le fichier logo du disque si il existe.
     */
    private function deleteLogo(?string $logoPath): void
    {
        if ($logoPath === null) {
            return;
        }

        $fullPath = $this->projectDir . '/public/' . $logoPath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}

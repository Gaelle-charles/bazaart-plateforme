<?php

declare(strict_types=1);

namespace App\DTO\Onboarding;

/**
 * OnboardingStep2DTO — Données du formulaire "Profil artiste" (étape 2).
 *
 * Ce DTO porte les données soumises à l'étape 2 de l'onboarding artiste :
 * informations de base du profil (displayName, location, disciplines, bio, liens).
 *
 * Pourquoi un DTO plutôt que l'entité directement ?
 *   Le DTO isole la validation des données HTTP de l'entité Doctrine.
 *   Cela évite de persister des données invalides et permet de valider
 *   plusieurs champs ensemble (ici : disciplines obligatoires).
 *
 * Note : la validation des champs obligatoires est faite dans OnboardingService::saveStep2()
 * plutôt que via #[Assert\...] — cohérent avec le pattern du reste du projet
 * (validations inline qui retournent des messages d'erreur en français).
 */
final class OnboardingStep2DTO
{
    /**
     * Nom d'affichage public de l'artiste (obligatoire).
     * Sera stocké dans ArtistProfile::$displayName.
     * Validation : non vide, max 100 caractères (vérifiée dans OnboardingService).
     */
    public string $displayName = '';

    /**
     * Ville / pays de l'artiste (optionnel).
     * Sera stocké dans ArtistProfile::$location.
     */
    public ?string $location = null;

    /**
     * Identifiants (int) des disciplines sélectionnées.
     * Au moins 1 obligatoire : validé dans OnboardingService.
     * Sera utilisé pour charger les entités Discipline et les attacher à ArtistProfile.
     *
     * @var list<int>
     */
    public array $disciplineIds = [];

    /**
     * Biographie libre de l'artiste (optionnelle).
     * Sera stocké dans ArtistProfile::$bio.
     */
    public ?string $bio = null;

    /**
     * URL du portfolio (optionnel).
     * Sera stocké dans ArtistProfile::$portfolioUrl.
     */
    public ?string $portfolioUrl = null;

    /**
     * URL du site web (optionnel).
     * Sera stocké dans ArtistProfile::$websiteUrl.
     */
    public ?string $websiteUrl = null;

    /**
     * Handle Instagram (sans le @, optionnel).
     * Sera utilisé pour construire socialLinks['instagram'].
     */
    public ?string $instagram = null;

    /**
     * Crée un DTO à partir des données brutes du formulaire HTTP POST.
     *
     * Convention du projet : fromRequest() parse manuellement le tableau $data
     * plutôt que d'utiliser Symfony Form (plus léger pour ce parcours custom).
     *
     * @param array<string, mixed> $data Données brutes de la requête POST
     */
    public static function fromRequest(array $data): self
    {
        $dto = new self();

        // Récupération sécurisée de chaque champ (trim + null si vide)
        $dto->displayName  = trim((string) ($data['display_name'] ?? ''));
        $dto->location     = self::nullIfEmpty(trim((string) ($data['location'] ?? '')));
        $dto->bio          = self::nullIfEmpty(trim((string) ($data['bio'] ?? '')));
        $dto->portfolioUrl = self::nullIfEmpty(trim((string) ($data['portfolio_url'] ?? '')));
        $dto->websiteUrl   = self::nullIfEmpty(trim((string) ($data['website_url'] ?? '')));
        $dto->instagram    = self::nullIfEmpty(trim((string) ($data['instagram'] ?? '')));

        // Les disciplines sont soumises comme un tableau de cases à cocher.
        // Ex : disciplines[] = "3", disciplines[] = "7"
        // On cast chaque valeur en int pour pouvoir faire un find() Doctrine.
        $rawDisciplines = $data['disciplines'] ?? [];
        if (is_array($rawDisciplines)) {
            // array_filter retire les valeurs vides/nulles, array_values réindexe
            $dto->disciplineIds = array_values(
                array_map('intval', array_filter($rawDisciplines))
            );
        }

        return $dto;
    }

    /**
     * Retourne null si la chaîne est vide, sinon retourne la chaîne.
     * Évite de stocker une chaîne vide "" en base (on préfère null).
     */
    private static function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\Onboarding;

/**
 * OnboardingStep3DTO — Données du formulaire "Que recherches-tu ?" (étape 3).
 *
 * Ce DTO porte les données soumises à l'étape 3 de l'onboarding artiste :
 * les objectifs de l'artiste sur la plateforme (choix multiples + texte libre).
 *
 * Validations appliquées dans OnboardingService::saveStep3() :
 *   - lookingForValues : au moins 1 case cochée (obligatoire)
 *   - lookingForOther  : requis SI "autre" est coché (validation croisée)
 *   - Chaque valeur doit être un ArtistLookingFor::value valide
 */
final class OnboardingStep3DTO
{
    /**
     * Valeurs sélectionnées dans les cases à cocher "Que recherches-tu ?".
     * Chaque valeur correspond à ArtistLookingFor::value (string backé).
     * Ex : ["formations", "ressources_appels"]
     *
     * @var list<string>
     */
    public array $lookingForValues = [];

    /**
     * Texte libre pour la case "Autre chose".
     * Non null uniquement si "autre" est dans lookingForValues.
     * La validation de ce caractère obligatoire conditionnel est faite dans OnboardingService.
     */
    public ?string $lookingForOther = null;

    /**
     * Crée le DTO depuis les données brutes POST du formulaire HTML.
     *
     * Les cases à cocher sont envoyées comme tableau : looking_for[] = "formations", etc.
     * Le champ libre est envoyé comme : looking_for_other = "..."
     *
     * @param array<string, mixed> $data Données brutes de la requête POST
     */
    public static function fromRequest(array $data): self
    {
        $dto = new self();

        // Récupère toutes les cases cochées (tableau de strings)
        $raw = $data['looking_for'] ?? [];
        if (is_array($raw)) {
            // On ne conserve que les valeurs non vides pour éviter les entrées parasites
            $dto->lookingForValues = array_values(array_filter(
                array_map('strval', $raw)
            ));
        }

        // Récupère le texte libre (optionnel, seulement si "autre" est coché)
        $other = trim((string) ($data['looking_for_other'] ?? ''));
        $dto->lookingForOther = $other === '' ? null : $other;

        return $dto;
    }
}

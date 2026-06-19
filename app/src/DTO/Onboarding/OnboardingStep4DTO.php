<?php

declare(strict_types=1);

namespace App\DTO\Onboarding;

use App\Enum\AlertFrequency;

/**
 * OnboardingStep4DTO — Données du formulaire "Alertes ressources" (étape 4).
 *
 * Ce DTO porte les données soumises à l'étape 4 de l'onboarding artiste :
 * configuration des alertes email pour les nouvelles ressources.
 *
 * Les champs correspondent à ResourceAlert :
 *   - frequency       : cadence d'envoi (IMMEDIATE, DAILY, WEEKLY)
 *   - disciplineIds   : disciplines filtrées (vide = toutes)
 *   - resourceTypeIds : types de ressources filtrés (vide = tous)
 *
 * Tous les champs sont optionnels à l'étape 4 (l'étape est complétable
 * même sans sélectionner de filtre — dans ce cas, toutes les ressources matchent).
 */
final class OnboardingStep4DTO
{
    /**
     * Fréquence d'envoi choisie (valeur de AlertFrequency::value).
     * Défaut : 'daily' (résumé quotidien, le moins intrusif pour débuter).
     * Validée dans OnboardingService (tryFrom retourne null si invalide → fallback Daily).
     */
    public string $frequency = 'daily';

    /**
     * Identifiants (int) des disciplines filtrées.
     * Vide = pas de filtre de discipline (toutes les disciplines matchent).
     *
     * @var list<int>
     */
    public array $disciplineIds = [];

    /**
     * Identifiants (int) des types de ressources filtrés.
     * Vide = pas de filtre de type (tous les types matchent).
     *
     * @var list<int>
     */
    public array $resourceTypeIds = [];

    /**
     * Crée le DTO depuis les données brutes POST du formulaire HTML.
     *
     * Les disciplines et types sont envoyés comme tableaux de cases à cocher :
     *   alert_disciplines[] = "3", alert_disciplines[] = "7"
     *   alert_types[] = "1"
     *
     * @param array<string, mixed> $data Données brutes de la requête POST
     */
    public static function fromRequest(array $data): self
    {
        $dto = new self();

        // Fréquence — on récupère la string et on valide via AlertFrequency::tryFrom
        $freq = trim((string) ($data['frequency'] ?? 'daily'));
        $dto->frequency = $freq !== '' ? $freq : 'daily';

        // Disciplines filtrées (tableau de cases à cocher, cast en int)
        $rawDisc = $data['alert_disciplines'] ?? [];
        if (is_array($rawDisc)) {
            $dto->disciplineIds = array_values(
                array_map('intval', array_filter($rawDisc))
            );
        }

        // Types de ressources filtrés (même traitement)
        $rawTypes = $data['alert_types'] ?? [];
        if (is_array($rawTypes)) {
            $dto->resourceTypeIds = array_values(
                array_map('intval', array_filter($rawTypes))
            );
        }

        return $dto;
    }

    /**
     * Convertit la string de fréquence en enum AlertFrequency.
     * Retourne AlertFrequency::Daily si la valeur est inconnue (fallback sécurisé).
     */
    public function getFrequencyEnum(): AlertFrequency
    {
        return AlertFrequency::tryFrom($this->frequency) ?? AlertFrequency::Daily;
    }
}

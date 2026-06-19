<?php

declare(strict_types=1);

namespace App\DTO\Onboarding;

use App\Enum\LegalStatus;

/**
 * OnboardingStep4MatchingDTO — Données du formulaire "Statut juridique" (étape 4 matching).
 *
 * Ce DTO remplace l'ancien OnboardingStep4DTO (alertes) dans le parcours d'onboarding
 * reformulé pour le matching (Lot A, ADR-0021/0022).
 *
 * L'étape 4 est désormais la DERNIÈRE étape et collecte uniquement le statut juridique.
 * C'est une information clé pour le moteur de matching (Lot C) mais optionnelle
 * (l'artiste peut passer sans choisir).
 *
 * L'ancien DTO OnboardingStep4DTO (alertes) est conservé dans le dossier
 * pour les usages existants (ex: tableau de bord, configuration alertes ultérieure).
 *
 * Convention de code : fromRequest() parse manuellement le tableau $data
 * plutôt qu'utiliser Symfony Form (cohérence avec le reste de l'onboarding).
 */
final class OnboardingStep4MatchingDTO
{
    /**
     * Valeur de l'enum LegalStatus choisie (string backed, ex: 'artiste_auteur').
     *
     * null → l'utilisateur n'a rien sélectionné (champ optionnel).
     * La conversion string → LegalStatus est faite dans getLegalStatusEnum().
     */
    public ?string $legalStatusValue = null;

    /**
     * Crée le DTO depuis les données brutes POST du formulaire HTML.
     *
     * Le champ legal_status est un select ou radio unique : on reçoit
     * une seule valeur string (ou rien si non renseigné).
     *
     * @param array<string, mixed> $data Données brutes de la requête POST
     */
    public static function fromRequest(array $data): self
    {
        $dto = new self();

        // Récupération de la valeur du statut juridique
        // trim() pour nettoyer les espaces parasites, null si vide
        $raw = trim((string) ($data['legal_status'] ?? ''));
        $dto->legalStatusValue = $raw !== '' ? $raw : null;

        return $dto;
    }

    /**
     * Convertit la valeur string en instance de LegalStatus.
     *
     * Retourne null si :
     *   - legalStatusValue est null (non renseigné)
     *   - la valeur ne correspond à aucun cas de l'enum (valeur inconnue)
     *
     * LegalStatus::tryFrom() retourne null si la valeur n'existe pas dans l'enum,
     * ce qui nous protège contre les valeurs POST falsifiées.
     *
     * @return LegalStatus|null Instance de l'enum, ou null si non renseigné / invalide
     */
    public function getLegalStatusEnum(): ?LegalStatus
    {
        if ($this->legalStatusValue === null) {
            return null;
        }

        // tryFrom() est sécurisé : retourne null si la valeur n'est pas dans l'enum
        // (protection contre injection d'une valeur arbitraire via POST)
        return LegalStatus::tryFrom($this->legalStatusValue);
    }
}

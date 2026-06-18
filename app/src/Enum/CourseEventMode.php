<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * CourseEventMode — Mode de déroulement d'un événement de formation.
 *
 * Cet enum n'a de sens que lorsque CourseType = EVENEMENT.
 * Il détermine quels champs additionnels sont attendus :
 *
 *   VISIO       → événement en ligne (Zoom, Meet, Jitsi, etc.).
 *                 Champ attendu : eventExternalUrl (lien de connexion).
 *                 eventLocation est ignoré / optionnel.
 *
 *   PRESENTIEL  → événement physique en salle ou sur site.
 *                 Champ attendu : eventLocation (adresse complète).
 *                 eventExternalUrl est ignoré / optionnel.
 *
 * Backing strings 'online' / 'in_person' :
 *   Ces valeurs sont stockées telles quelles en base PostgreSQL (VARCHAR).
 *   Elles sont intentionnellement en anglais pour la cohérence interne
 *   et pour faciliter une éventuelle intégration API future.
 *
 * Validation métier associée (voir CourseEventValidationService) :
 *   - VISIO      → eventExternalUrl obligatoire, eventLocation facultatif
 *   - PRESENTIEL → eventLocation obligatoire, eventExternalUrl facultatif
 */
enum CourseEventMode: string
{
    /**
     * Événement en ligne : lien de connexion (Zoom / Meet / Jitsi / etc.).
     * Le champ eventExternalUrl de la formation doit être renseigné.
     */
    case VISIO      = 'online';

    /**
     * Événement en présentiel : adresse physique.
     * Le champ eventLocation de la formation doit être renseigné.
     */
    case PRESENTIEL = 'in_person';

    /**
     * Retourne l'intitulé lisible en français pour l'affichage dans les templates.
     *
     * Utilisation Twig : {{ course.eventMode.label() }}
     */
    public function label(): string
    {
        return match($this) {
            self::VISIO      => 'En ligne (visio)',
            self::PRESENTIEL => 'En présentiel',
        };
    }
}

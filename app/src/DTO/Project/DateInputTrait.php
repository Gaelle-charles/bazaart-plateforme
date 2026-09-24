<?php

declare(strict_types=1);

namespace App\DTO\Project;

/**
 * DateInputTrait — lecture sûre des champs <input type="date"> (format AAAA-MM-JJ).
 *
 * Partagé par les DTO de l'Espace projets (ADR-0037).
 */
trait DateInputTrait
{
    /**
     * Convertit « 2026-10-03 » en DateTimeImmutable à minuit, ou null si vide / invalide.
     *
     * Le « ! » en tête du format remet l'heure à 00:00:00 (sinon PHP prendrait
     * l'heure courante). On vérifie ensuite que la date relue est identique à la
     * saisie : « 2026-02-31 » serait sinon silencieusement transformé en 3 mars.
     */
    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($date !== false && $date->format('Y-m-d') === $value) ? $date : null;
    }

    /** true si un texte non vide a été saisi mais n'est pas une date valide. */
    private static function isInvalidDate(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && self::parseDate($value) === null;
    }
}

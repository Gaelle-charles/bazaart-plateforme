<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * ProjectClock — « quelle heure / quel jour est-il pour l'équipe ? » (ADR-0037).
 *
 * Le conteneur Docker tourne en UTC (cf. commentaires de ResourceRepository).
 * Sans précaution, à 22h en Guadeloupe (2h du matin UTC) une tâche due « aujourd'hui »
 * apparaîtrait déjà « en retard ». Ce service centralise donc :
 *   - today()   : minuit du jour courant dans le fuseau de l'équipe ;
 *   - toLocal() : conversion d'un horodatage (stocké en UTC) pour l'affichage.
 *
 * Fuseau configurable via PROJECT_TIMEZONE (défaut : America/Guadeloupe, siège de
 * l'association). Les ÉCHÉANCES sont des dates sans heure : elles ne sont jamais converties.
 */
final class ProjectClock
{
    private readonly \DateTimeZone $timezone;

    public function __construct(string $timezone)
    {
        $this->timezone = new \DateTimeZone($timezone);
    }

    /** Aujourd'hui à 00:00 dans le fuseau de l'équipe. */
    public function today(): \DateTimeImmutable
    {
        // On reconstruit une date « naïve » (fuseau PHP par défaut) à partir du
        // jour local : c'est comparable directement aux colonnes DATE de Doctrine.
        $localDay = (new \DateTimeImmutable('now', $this->timezone))->format('Y-m-d');

        return new \DateTimeImmutable($localDay . ' 00:00:00');
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** Horodatage converti dans le fuseau de l'équipe (pour l'affichage uniquement). */
    public function toLocal(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone);
    }

    public function getTimezone(): \DateTimeZone
    {
        return $this->timezone;
    }
}

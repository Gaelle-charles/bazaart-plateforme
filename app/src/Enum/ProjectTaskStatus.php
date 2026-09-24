<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ProjectTaskStatus — colonnes du Kanban de l'Espace projets (ADR-0037).
 *
 * Cinq étapes, pensées pour une petite équipe de 3 personnes :
 *   À faire → En cours → En attente (bloquée par un tiers) → À valider → Terminé
 *
 * « En attente » et « À valider » sont les deux colonnes qui manquent le plus
 * dans un simple « à faire / fait » : elles rendent visibles les tâches qui
 * attendent quelqu'un d'autre (partenaire, relecture d'une collègue).
 */
enum ProjectTaskStatus: string
{
    case Todo       = 'todo';
    case InProgress = 'in_progress';
    case Waiting    = 'waiting';
    case Review     = 'review';
    case Done       = 'done';

    /** Libellé français (en-têtes de colonnes Kanban, badges). */
    public function label(): string
    {
        return match ($this) {
            self::Todo       => 'À faire',
            self::InProgress => 'En cours',
            self::Waiting    => 'En attente',
            self::Review     => 'À valider',
            self::Done       => 'Terminé',
        };
    }

    /** Courte explication affichée en info-bulle sur l'en-tête de colonne. */
    public function hint(): string
    {
        return match ($this) {
            self::Todo       => 'Tâches prêtes à être prises en charge',
            self::InProgress => 'Quelqu\'un travaille dessus',
            self::Waiting    => 'Bloquée : on attend un tiers (partenaire, réponse, paiement…)',
            self::Review     => 'Terminée par la personne assignée, à relire / valider',
            self::Done       => 'Tâches terminées',
        };
    }

    /** Suffixe de classe CSS (ex. .pm-status--in-progress). */
    public function cssModifier(): string
    {
        return str_replace('_', '-', $this->value);
    }

    public function isDone(): bool
    {
        return $this === self::Done;
    }
}

<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ProjectTaskPriority — niveau de priorité d'une tâche ou d'un projet (ADR-0037).
 *
 * L'ordre des cases (de la plus urgente à la moins urgente) est aussi l'ordre
 * des colonnes de la vue « Par priorité ».
 */
enum ProjectTaskPriority: string
{
    case Urgent = 'urgent';
    case High   = 'high';
    case Medium = 'medium';
    case Low    = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'Urgente',
            self::High   => 'Haute',
            self::Medium => 'Normale',
            self::Low    => 'Basse',
        };
    }

    /**
     * Poids numérique pour trier (plus grand = plus prioritaire).
     * On ne trie pas sur la valeur string ('high' < 'low' alphabétiquement !).
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High   => 3,
            self::Medium => 2,
            self::Low    => 1,
        };
    }

    /** Suffixe de classe CSS (ex. .pm-prio--urgent). */
    public function cssModifier(): string
    {
        return $this->value;
    }
}

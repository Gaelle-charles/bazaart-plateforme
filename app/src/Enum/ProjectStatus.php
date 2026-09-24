<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ProjectStatus — cycle de vie d'un projet dans l'Espace projets (ADR-0037).
 *
 * Backed enum 'string' : la valeur (ex. 'active') est stockée telle quelle en BDD
 * (colonne VARCHAR), ce qui reste lisible en SQL brut. Doctrine convertit
 * automatiquement grâce à `enumType: ProjectStatus::class` dans le mapping.
 *
 * Ordre des cases = ordre d'affichage dans les listes déroulantes.
 */
enum ProjectStatus: string
{
    /** Projet identifié, pas encore démarré (idée validée, date à venir). */
    case Planned = 'planned';

    /** Projet en cours de réalisation — le statut « normal » d'un projet vivant. */
    case Active = 'active';

    /** Projet mis en pause (attente d'un financement, d'un partenaire…). */
    case OnHold = 'on_hold';

    /** Projet livré / terminé. Il reste visible dans les listes. */
    case Completed = 'completed';

    /** Projet rangé : masqué par défaut des listes et des filtres. */
    case Archived = 'archived';

    /** Libellé français affiché dans l'interface. */
    public function label(): string
    {
        return match ($this) {
            self::Planned   => 'À venir',
            self::Active    => 'En cours',
            self::OnHold    => 'En pause',
            self::Completed => 'Terminé',
            self::Archived  => 'Archivé',
        };
    }

    /**
     * Suffixe de classe CSS pour le badge (ex. .pm-badge--active).
     * On réutilise la valeur de l'enum : une seule source de vérité.
     */
    public function cssModifier(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /**
     * Statuts considérés comme « ouverts » (projet encore à piloter).
     * Utilisé par les compteurs du tableau de bord et le filtre par défaut.
     *
     * @return list<self>
     */
    public static function openCases(): array
    {
        return [self::Planned, self::Active, self::OnHold];
    }
}

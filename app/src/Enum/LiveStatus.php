<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * LiveStatus — statut d'un live planifié sur Bazaart.
 *
 * En V1, un live est simplement une annonce avec un lien externe
 * (Twitch, Jitsi Meet, Google Meet). Le streaming natif est prévu en V2.
 *
 * Transitions attendues :
 *   SCHEDULED → LIVE      (démarrage manuel par l'admin ou l'hôte)
 *   SCHEDULED → CANCELLED (annulation avant démarrage)
 *   LIVE      → ENDED     (fin du live, possibilité d'ajouter un replay)
 *
 * Pourquoi un enum backed string ?
 *   Doctrine stocke la valeur string en base (colonne VARCHAR),
 *   ce qui rend les données lisibles directement en SQL.
 *   L'enum garantit que seules les valeurs déclarées peuvent être stockées.
 */
enum LiveStatus: string
{
    /** Live planifié — pas encore démarré, inscriptions ouvertes */
    case SCHEDULED = 'scheduled';

    /** Live en cours — stream actif en ce moment */
    case LIVE = 'live';

    /** Live terminé — replay éventuellement disponible */
    case ENDED = 'ended';

    /** Live annulé — les inscrits ont été ou vont être notifiés */
    case CANCELLED = 'cancelled';

    /**
     * Retourne l'intitulé lisible en français pour l'affichage dans les templates.
     *
     * Utilisation Twig : {{ live.status.label() }}
     */
    public function label(): string
    {
        return match($this) {
            self::SCHEDULED => 'Planifié',
            self::LIVE      => 'En direct',
            self::ENDED     => 'Terminé',
            self::CANCELLED => 'Annulé',
        };
    }

    /**
     * Retourne la classe CSS correspondant au statut pour le badge coloré.
     *
     * Ces classes sont définies dans les templates avec le préfixe .lv-
     * Exemple : .lv-badge--scheduled (vert acide), .lv-badge--live (orange), etc.
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::SCHEDULED => 'lv-badge--scheduled',
            self::LIVE      => 'lv-badge--live',
            self::ENDED     => 'lv-badge--ended',
            self::CANCELLED => 'lv-badge--cancelled',
        };
    }
}

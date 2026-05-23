<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Fréquence d'envoi des alertes email pour les nouvelles ressources.
 *
 * Cet enum est utilisé dans ResourceAlert::$frequency.
 * Il détermine à quelle cadence l'utilisateur reçoit les notifications
 * pour les nouvelles opportunités qui correspondent à ses filtres.
 *
 * Valeurs backed 'string' pour le stockage Doctrine (colonne VARCHAR).
 *
 *   Immediate → email dès qu'une nouvelle ressource est publiée (temps réel)
 *   Daily     → résumé quotidien (défaut — bon équilibre entre info et spam)
 *   Weekly    → résumé hebdomadaire (pour les utilisateurs peu actifs)
 *
 * Note V1 : l'envoi effectif des emails est délégué à n8n via un webhook.
 * Le champ frequency est lu par le job de dispatch pour décider quels
 * profils d'alertes inclure dans chaque batch d'envoi.
 */
enum AlertFrequency: string
{
    case Immediate = 'immediate';
    case Daily     = 'daily';
    case Weekly    = 'weekly';

    /**
     * Retourne le libellé lisible en français pour l'affichage dans les formulaires.
     */
    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immédiate (dès qu\'une opportunité est publiée)',
            self::Daily     => 'Quotidienne (résumé chaque matin)',
            self::Weekly    => 'Hebdomadaire (résumé le lundi)',
        };
    }
}

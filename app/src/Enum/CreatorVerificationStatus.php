<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * CreatorVerificationStatus — statuts de vérification du profil de versement créateur.
 *
 * Cycle de vie d'un profil de paiement (ADR-0027, Lot 1) :
 *
 *   Pending  → le membre vient de soumettre (ou re-soumettre) ses coordonnées bancaires
 *              et sa pièce d'identité ; l'admin doit les vérifier.
 *   Verified → l'admin a contrôlé les documents et les a validés ; le membre peut être payé.
 *   Rejected → les documents sont invalides (ex : IBAN incorrect, pièce illisible) ;
 *              l'admin a fourni un motif de refus, le membre doit re-soumettre.
 *
 * Enum "backed string" : la valeur (ex 'pending') est celle stockée en base de données.
 * Doctrine utilise `enumType: CreatorVerificationStatus::class` sur la colonne pour
 * faire le mapping automatique PHP enum ↔ chaîne SQL.
 */
enum CreatorVerificationStatus: string
{
    /** En attente de vérification par un administrateur (état initial après soumission). */
    case Pending = 'pending';

    /** Pièce d'identité et coordonnées bancaires vérifiées par l'admin. */
    case Verified = 'verified';

    /** Documents refusés par l'admin (motif dans CreatorPayoutProfile::$rejectionReason). */
    case Rejected = 'rejected';

    /**
     * Libellé lisible en français pour l'affichage dans les interfaces.
     *
     * Utilisé dans les templates Twig (ex : {{ profile.status.label() }})
     * et dans les emails de notification.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'En attente de vérification',
            self::Verified => 'Vérifié',
            self::Rejected => 'Refusé',
        };
    }

    /**
     * Variante de badge pour le rendu CSS côté template.
     *
     * Convention partagée avec CourseProposalStatus (même palette) :
     *   - 'warn'   → orange (--accent-2) : action requise
     *   - 'ok'     → vert : validé / positif
     *   - 'danger' → rouge : refusé / bloquant
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending  => 'warn',
            self::Verified => 'ok',
            self::Rejected => 'danger',
        };
    }
}

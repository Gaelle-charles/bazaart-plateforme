<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * EnrollmentStatus — Statut d'une inscription à une formation ou un événement.
 *
 * Cet enum remplace les constantes STATUS_* qui auraient pu exister en V1.
 * Convention du projet : les enums PHP 8.1 à valeur string ("backed enum")
 * permettent un stockage direct en base (ORM\Column type:'string') et
 * la désérialisation via ::from() / ::tryFrom().
 *
 * Valeurs :
 *   ACTIVE    → inscription en cours, la place est comptée dans countActiveByCourse()
 *   CANCELLED → inscription annulée (par le membre ou par l'admin),
 *               la place est LIBÉRÉE (ne compte plus dans les places prises)
 *
 * Cycle de vie :
 *   new CourseEnrollment() → status = ACTIVE  (défaut dans l'entité)
 *   EventCancellationService::cancelByMember() → CANCELLED
 *   EventCancellationService::cancelByAdmin()  → CANCELLED
 */
enum EnrollmentStatus: string
{
    /** Inscription active — la place est réservée */
    case ACTIVE = 'active';

    /** Inscription annulée — la place est libérée */
    case CANCELLED = 'cancelled';

    /**
     * Libellé humain en français, utilisé dans les emails et le back-office.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE    => 'Active',
            self::CANCELLED => 'Annulée',
        };
    }
}

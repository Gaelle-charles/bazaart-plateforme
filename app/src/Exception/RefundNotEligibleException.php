<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * RefundNotEligibleException — Levée quand un remboursement n'est pas possible.
 *
 * Levée par EventCancellationService::cancelByMember() quand l'inscription
 * est hors de la fenêtre de remboursement (délai de rétractation dépassé
 * ou événement déjà commencé).
 *
 * Le message de l'exception est directement destiné à l'utilisateur final
 * (pas un message technique) et peut être affiché dans un flash message
 * sans transformation supplémentaire.
 *
 * Exemple d'usage dans le controller :
 *   try {
 *       $this->cancellationService->cancelByMember($enrollment);
 *   } catch (RefundNotEligibleException $e) {
 *       $this->addFlash('warning', $e->getMessage());
 *   }
 */
class RefundNotEligibleException extends \RuntimeException
{
    // Hérite de RuntimeException → message, code, previous sont gérés par le parent.
    // Pas de propriétés supplémentaires en V1 — le message suffit pour l'UX.
    //
    // En V2, on pourrait ajouter une propriété 'reason' (enum) pour différencier
    // "délai dépassé" de "événement commencé" et personnaliser davantage l'affichage.
}

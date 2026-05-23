<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut de modération d'une ressource (opportunité) dans la Ressourcerie.
 *
 * Workflow V1 (cf. CDC V3 §5.2) — 5 statuts :
 *
 *   ┌──────────────────────────────────────────────────────────────────────┐
 *   │  Draft               Brouillon, non visible. Réservé à un usage      │
 *   │                      futur (auto-save artistes, par exemple).        │
 *   │                                                                       │
 *   │  PendingValidation   Soumise par un artiste, en attente d'un admin.  │
 *   │                      ⚠ valeur backed 'pending' — historique préservé│
 *   │                                                                       │
 *   │  Published           Validée et visible publiquement.                │
 *   │                                                                       │
 *   │  Rejected            Refusée par un admin (motif renseigné séparément)│
 *   │                                                                       │
 *   │  Archived            Retirée (deadline passée, organisateur archivé)│
 *   └──────────────────────────────────────────────────────────────────────┘
 *
 * Logique d'auto-publication selon le contributeur (cf. SubmitterRole) :
 *   - submitterRole = Admin     → status = Published directement
 *   - submitterRole = Structure → status = Published directement
 *   - submitterRole = Artist    → status = PendingValidation
 *
 * Pourquoi PendingValidation a la valeur backed 'pending' ?
 * La colonne `status` de la table `resources` contient peut-être déjà la
 * valeur 'pending' (depuis l'ancien modèle 3-statuts). En gardant cette
 * valeur backed, on évite toute migration de données SQL : seule la classe
 * PHP change. Le case "PendingValidation" est juste un renommage côté code,
 * plus aligné avec le vocabulaire du cahier des charges.
 */
enum ResourceStatus: string
{
    case Draft             = 'draft';
    case PendingValidation = 'pending';
    case Published         = 'published';
    case Rejected          = 'rejected';
    case Archived          = 'archived';
}

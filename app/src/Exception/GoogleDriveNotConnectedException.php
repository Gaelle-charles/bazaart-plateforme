<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Le Drive n'est pas (ou plus) connecté : il faut (re)lancer la connexion OAuth
 * depuis « Équipe & réglages » ou la page Drive (ADR-0037).
 */
class GoogleDriveNotConnectedException extends GoogleDriveException
{
}

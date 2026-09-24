<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Erreur lors d'un échange avec l'API Google Drive (ADR-0037).
 *
 * Le message est TOUJOURS rédigé en français pour l'équipe : il peut être affiché
 * tel quel dans un message flash ou une réponse JSON. Les détails techniques
 * (réponse brute de Google) sont écrits dans les logs, jamais montrés.
 */
class GoogleDriveException extends \RuntimeException
{
}

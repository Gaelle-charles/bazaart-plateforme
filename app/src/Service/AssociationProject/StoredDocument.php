<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

/**
 * StoredDocument : résultat de l'enregistrement d'un document PDF (ADR-0039).
 */
final readonly class StoredDocument
{
    public function __construct(
        /** Nom du fichier dans le dossier privé des documents (c'est ce qu'on enregistre en BDD). */
        public string $path,
        /** Taille en octets, mesurée sur le fichier. */
        public int $sizeBytes,
    ) {
    }
}

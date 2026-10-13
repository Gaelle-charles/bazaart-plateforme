<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

/**
 * StoredImage : résultat d'un téléversement réussi (ADR-0039).
 *
 * Petit objet en lecture seule plutôt qu'un tableau : PHPStan connaît le type de
 * chaque propriété, et l'appelant ne peut pas se tromper de clé.
 */
final readonly class StoredImage
{
    public function __construct(
        /** Nom du fichier dans le dossier privé (c'est ce qu'on enregistre en BDD). */
        public string $path,
        /** Largeur en pixels, mesurée sur le fichier. */
        public int $width,
        /** Hauteur en pixels, mesurée sur le fichier. */
        public int $height,
    ) {
    }
}

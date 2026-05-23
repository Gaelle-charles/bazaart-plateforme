<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut de publication d'un article du blog Bazaart.
 *
 * --- Pourquoi un enum PHP 8.1 plutôt que des constantes string ? ---
 *
 * Avant (constantes) :
 *     public const STATUS_DRAFT = 'draft';
 *     $article->setStatus('drft');  // <- typo silencieuse, aucune erreur PHP
 *
 * Après (enum) :
 *     $article->setStatus(ArticleStatus::Draft);
 *     $article->setStatus('drft');  // <- erreur de type immédiate
 *
 * L'enum verrouille les valeurs autorisées au niveau du moteur PHP.
 * Toute valeur invalide est rejetée à l'exécution (et détectée par PHPStan).
 *
 * --- "Backed enum" : pourquoi `: string` ? ---
 *
 * Un enum "backed" associe à chaque case une valeur scalaire (string ou int).
 * Cette valeur est ce qui est stocké en base de données par Doctrine via
 * `enumType` — ici 'draft' et 'published', identiques aux anciennes constantes
 * pour rester compatible avec les lignes existantes en BDD (zéro migration SQL).
 */
enum ArticleStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
}

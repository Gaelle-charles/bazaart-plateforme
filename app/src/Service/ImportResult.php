<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;

/**
 * ImportResult — Résultat du parsing d'un CSV d'import de grants.
 *
 * Ce DTO porte le résultat de GrantCsvImporter::parseFile() :
 *   - opportunities  : liste des ScrapedOpportunity prêts à être persistés
 *   - ignoredLines   : rapport des lignes ignorées (pas d'URL, titre vide, doublon...)
 *
 * IMMUABILITÉ :
 *   Les propriétés sont readonly (PHP 8.1) car les résultats d'un parsing
 *   ne doivent pas être modifiés après construction — ils décrivent un fait passé.
 *
 * Utilisé par ImportGrantCsvCommand pour :
 *   1. Afficher le rapport des ignorées (avec raison)
 *   2. Passer les opportunities à ScrapedResourcePersister::persistBatch()
 *   3. Calculer les stats finales (nb importées, nb ignorées, nb enrichies)
 */
final readonly class ImportResult
{
    /**
     * @param ScrapedOpportunity[]                         $opportunities  Opportunités parsées et prêtes
     * @param array<int, array{title: string, reason: string}> $ignoredLines   Lignes ignorées avec raison
     */
    public function __construct(
        public array $opportunities,
        public array $ignoredLines,
    ) {
    }

    /**
     * Nombre total de lignes dans le CSV (données) = importées + ignorées.
     * Utile pour les stats : "X sur Y lignes importées".
     */
    public function totalLines(): int
    {
        return count($this->opportunities) + count($this->ignoredLines);
    }
}

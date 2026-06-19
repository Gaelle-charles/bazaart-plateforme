<?php

declare(strict_types=1);

namespace App\Service;

/**
 * PersistResult — Résultat d'un appel à ScrapedResourcePersister::persistBatch().
 *
 * Ce petit DTO (Data Transfer Object) porte les compteurs de déduplication
 * renvoyés par le persister. Il remplace le retour de plusieurs variables
 * scalaires par un objet typé, plus clair et plus extensible.
 *
 * ── POURQUOI une classe dédiée et pas un tableau ? ───────────────────────────
 * PHP ne peut pas typer les clés d'un tableau (`array{inserted: int, ...}`).
 * Une classe readonly permet à PHPStan de vérifier les types sans ambiguïté,
 * et à l'IDE d'auto-compléter les propriétés → moins d'erreurs.
 *
 * ── READONLY ────────────────────────────────────────────────────────────────
 * Les propriétés readonly PHP 8.1 sont initialisées dans le constructeur
 * et ne peuvent plus être modifiées ensuite. C'est approprié ici car les
 * compteurs sont des faits immuables (ils décrivent ce QUI S'EST PASSÉ,
 * pas un état en cours).
 *
 * ── CORRECTION AV-4 (ADR-0016 Lot 2) ───────────────────────────────────────
 * Ajout de $insertedUrls : liste des URLs réellement insérées en BDD (Cas 1 du
 * persister). Permet à ImportGrantCsvCommand de n'enrichir QUE les opportunités
 * nouvelles, et non celles déjà en BDD (qui auraient sinon régénéré un appel
 * Mistral inutile et coûteux à chaque ré-import).
 */
final readonly class PersistResult
{
    public function __construct(
        // Nouvelles URLs jamais vues → INSERT avec status pending
        public int $inserted,

        // URLs connues qui étaient archivées → réactivées en pending
        public int $reactivated,

        // URLs connues (pending ou rejected) → données rafraîchies, statut inchangé
        public int $updated,

        // URLs ignorées : déjà vérifiées par un admin, ou doublons intra-lot
        public int $skipped,

        /**
         * Liste des URLs effectivement insérées (Cas 1 — nouvelles en BDD).
         * Utilisé par ImportGrantCsvCommand (--enrich) pour ne Mistraliser que les
         * nouvelles entrées, pas les URL déjà connues (updated/skipped/reactivated).
         *
         * @var string[]
         */
        public array $insertedUrls = [],
    ) {
    }

    /**
     * Nombre total d'opportunités traitées (hors doublons intra-lot).
     *
     * Utile pour les logs : "X opportunités traitées" sans détailler les cas.
     */
    public function total(): int
    {
        return $this->inserted + $this->reactivated + $this->updated + $this->skipped;
    }
}

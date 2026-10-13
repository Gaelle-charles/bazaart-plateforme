<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

/**
 * Compte rendu complet d'un import de fiches (ADR-0039) : une entrée par fiche du
 * JSON, des erreurs générales (fichier absent, dossier non accessible…) et les
 * totaux affichés par la commande.
 */
final class AssociationProjectImportReport
{
    /** @var list<AssociationProjectImportEntry> */
    public array $entries = [];

    /** @var list<string> erreurs qui empêchent tout enregistrement */
    public array $globalErrors = [];

    /** @var list<string> noms des fichiers images enregistrés dans le dossier privé */
    public array $storedImages = [];

    /**
     * Vrai seulement si les modifications ont été ENREGISTRÉES (transaction validée).
     * Faux en essai (--dry-run) et quand une erreur générale a bloqué l'import :
     * les compteurs décrivent alors ce qui SERAIT rempli.
     */
    public bool $written = false;

    public function __construct(
        public readonly bool $dryRun,
        public readonly string $directory,
    ) {
    }

    public function hasProblems(): bool
    {
        if ($this->globalErrors !== []) {
            return true;
        }
        foreach ($this->entries as $entry) {
            if ($entry->hasProblem()) {
                return true;
            }
        }

        return false;
    }

    public function countStatus(string ...$statuses): int
    {
        return count(array_filter(
            $this->entries,
            static fn (AssociationProjectImportEntry $entry): bool => in_array($entry->status, $statuses, true),
        ));
    }

    /**
     * Somme d'une liste de champs sur toutes les entrées.
     *
     * @param 'filled'|'alreadySame'|'alreadyDifferent'|'notRefilled'|'nullInJson'|'typeStateDifferences' $list
     */
    public function sum(string $list): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += count($entry->{$list});
        }

        return $total;
    }

    /** Visuels envoyés (en essai : visuels qui passeraient les contrôles). */
    public function imagesSent(): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += in_array(AssociationProjectImporter::IMAGE_LABEL, $entry->filled, true) ? 1 : 0;
        }

        return $total;
    }

    public function errorCount(): int
    {
        $total = count($this->globalErrors);
        foreach ($this->entries as $entry) {
            $total += count($entry->errors);
        }

        return $total;
    }
}

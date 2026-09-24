<?php

declare(strict_types=1);

namespace App\DTO\Project;

/**
 * ProjectImportReport — résultat de la lecture d'un tableur de tâches (ADR-0037).
 *
 * Le même rapport sert à l'aperçu (rien n'est enregistré) et au compte rendu
 * après import : tâches prêtes, projets à créer, doublons ignorés, avertissements.
 */
final class ProjectImportReport
{
    /** Erreur bloquante (fichier illisible, colonne « Tâche » absente…) : rien n'est importable. */
    public ?string $error = null;

    /** @var list<ProjectImportRow> */
    public array $rows = [];

    /**
     * Projets qui n'existent pas encore et seront créés.
     *
     * @var list<string>
     */
    public array $newProjects = [];

    /** Tâches déjà présentes (même titre dans le même projet) : ignorées. */
    public int $duplicates = 0;

    /**
     * Remarques non bloquantes (« ligne 4 : statut inconnu… »).
     *
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * Colonnes reconnues : en-tête du fichier → champ de la tâche.
     *
     * @var array<string, string>
     */
    public array $columns = [];

    /**
     * En-têtes non reconnus (ignorés).
     *
     * @var list<string>
     */
    public array $ignoredColumns = [];

    /** Y a-t-il quelque chose à enregistrer ? */
    public function isImportable(): bool
    {
        return $this->error === null && ($this->rows !== [] || $this->newProjects !== []);
    }
}

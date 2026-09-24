<?php

declare(strict_types=1);

namespace App\DTO\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;

/**
 * ProjectImportRow — une ligne de tableur prête à devenir une tâche (import, ADR-0037).
 *
 * Produite par ProjectTaskImporter::analyze() : toutes les valeurs sont déjà
 * nettoyées et traduites (statut, priorité, dates, personnes). Sert à la fois
 * à l'aperçu (« Vérifier ») et à l'enregistrement (« Importer »).
 */
final class ProjectImportRow
{
    /** Numéro de la ligne dans le tableur (l'en-tête est la ligne 1). */
    public int $line = 0;

    public string $title = '';

    public ?string $description = null;

    /** Projet existant, ou null (nouveau projet ou tâche sans projet). */
    public ?Project $project = null;

    /** Nom du projet à créer (clé de ProjectImportReport::$newProjects), sinon null. */
    public ?string $newProjectName = null;

    public ProjectTaskStatus $status = ProjectTaskStatus::Todo;

    public ProjectTaskPriority $priority = ProjectTaskPriority::Medium;

    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $dueDate = null;

    /** @var list<User> */
    public array $assignees = [];

    /** Autrice d'origine (colonne « Par »), sinon la personne qui importe. */
    public ?User $createdBy = null;

    /** @var list<string> noms d'étiquettes */
    public array $labels = [];

    /** @var list<string> liens http(s) joints à la tâche */
    public array $links = [];

    /** @var list<array{title: string, done: bool}> */
    public array $subtasks = [];

    /** Nom du projet à afficher dans l'aperçu (existant ou à créer). */
    public function projectLabel(): ?string
    {
        return $this->project?->getName() ?? $this->newProjectName;
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\Project;

use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use Symfony\Component\HttpFoundation\Request;

/**
 * ProjectTaskData — données saisies pour créer ou modifier une tâche (ADR-0037).
 *
 * Sert aussi bien au formulaire complet (fiche tâche) qu'à l'ajout rapide
 * (champ titre en bas d'une colonne Kanban) : les champs absents du POST
 * gardent leur valeur par défaut.
 */
final class ProjectTaskData
{
    use DateInputTrait;

    public string $title = '';
    public ?string $description = null;
    public ?int $projectId = null;
    public ProjectTaskStatus $status = ProjectTaskStatus::Todo;
    public ProjectTaskPriority $priority = ProjectTaskPriority::Medium;
    public ?\DateTimeImmutable $startDate = null;
    public ?\DateTimeImmutable $dueDate = null;

    /** @var list<int> IDs des personnes assignées */
    public array $assigneeIds = [];

    /** @var list<int> IDs des étiquettes existantes cochées */
    public array $labelIds = [];

    /** @var list<string> nouvelles étiquettes tapées (« Budget, Presse ») */
    public array $newLabels = [];

    /** @var list<string> */
    private array $parseErrors = [];

    public static function fromRequest(Request $request): self
    {
        $post = $request->request;
        $data = new self();

        $data->title       = trim((string) $post->get('title', ''));
        $description       = trim((string) $post->get('description', ''));
        $data->description = $description !== '' ? $description : null;

        $project         = (string) $post->get('projectId', '');
        $data->projectId = ctype_digit($project) && (int) $project > 0 ? (int) $project : null;

        $data->status   = ProjectTaskStatus::tryFrom((string) $post->get('status', '')) ?? ProjectTaskStatus::Todo;
        $data->priority = ProjectTaskPriority::tryFrom((string) $post->get('priority', '')) ?? ProjectTaskPriority::Medium;

        // Dates : une saisie illisible est signalée (plutôt qu'ignorée en silence).
        $rawStart = $post->get('startDate');
        $rawDue   = $post->get('dueDate');
        if (self::isInvalidDate($rawStart)) {
            $data->parseErrors[] = 'La date de début n\'est pas une date valide.';
        }
        if (self::isInvalidDate($rawDue)) {
            $data->parseErrors[] = 'L\'échéance n\'est pas une date valide.';
        }
        $data->startDate = self::parseDate($rawStart);
        $data->dueDate   = self::parseDate($rawDue);

        // Cases à cocher multiples : name="assignees[]" → tableau de chaînes.
        // all() renvoie [] si le champ est absent (aucune case cochée).
        $data->assigneeIds = self::toIdList($post->all('assignees'));
        $data->labelIds    = self::toIdList($post->all('labels'));

        $newLabels = trim((string) $post->get('newLabels', ''));
        if ($newLabels !== '') {
            $names = array_map(static fn (string $n): string => mb_substr(trim($n), 0, 40), explode(',', $newLabels));
            $data->newLabels = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
        }

        return $data;
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = $this->parseErrors;

        $length = mb_strlen($this->title);
        if ($length < 1 || $length > 255) {
            $errors[] = 'Le titre de la tâche doit faire entre 1 et 255 caractères.';
        }
        if ($this->description !== null && mb_strlen($this->description) > 20000) {
            $errors[] = 'La description ne peut pas dépasser 20 000 caractères.';
        }
        if ($this->startDate !== null && $this->dueDate !== null && $this->dueDate < $this->startDate) {
            $errors[] = 'L\'échéance doit être postérieure à la date de début.';
        }
        if (count($this->newLabels) > 10) {
            $errors[] = 'Tu ne peux pas créer plus de 10 étiquettes à la fois.';
        }

        return $errors;
    }

    /**
     * Garde uniquement les entiers positifs, sans doublon.
     *
     * @param array<mixed> $values
     *
     * @return list<int>
     */
    private static function toIdList(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}

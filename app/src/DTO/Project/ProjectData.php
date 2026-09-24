<?php

declare(strict_types=1);

namespace App\DTO\Project;

use App\Enum\ProjectStatus;
use App\Enum\ProjectTaskPriority;
use App\Service\Project\ProjectService;
use Symfony\Component\HttpFoundation\Request;

/**
 * ProjectData — données saisies dans le formulaire projet (création / édition) — ADR-0037.
 *
 * Même approche que les autres DTO du projet (symfony/validator n'est pas installé) :
 *   1. fromRequest() lit et NETTOIE le POST (trim, conversions de type) ;
 *   2. validate() renvoie la liste des erreurs en français (vide = tout va bien) ;
 *   3. le service applique ensuite les données à l'entité.
 */
final class ProjectData
{
    use DateInputTrait;

    public string $name = '';
    public ?string $description = null;
    public string $color = ProjectService::COLORS[0];
    public ProjectStatus $status = ProjectStatus::Active;
    public ProjectTaskPriority $priority = ProjectTaskPriority::Medium;
    public ?int $ownerId = null;
    public ?\DateTimeImmutable $startDate = null;
    public ?\DateTimeImmutable $dueDate = null;

    /** Clé d'un modèle de ProjectTemplateCatalog (création uniquement). */
    public ?string $templateKey = null;

    /** Créer un dossier dédié dans le Google Drive (création uniquement). */
    public bool $createDriveFolder = false;

    /** @var list<string> erreurs détectées dès la lecture (dates illisibles…) */
    private array $parseErrors = [];

    public static function fromRequest(Request $request): self
    {
        $post = $request->request;
        $data = new self();

        $data->name        = trim((string) $post->get('name', ''));
        $description       = trim((string) $post->get('description', ''));
        $data->description = $description !== '' ? $description : null;
        $data->color       = (string) $post->get('color', ProjectService::COLORS[0]);
        $data->status      = ProjectStatus::tryFrom((string) $post->get('status', '')) ?? ProjectStatus::Active;
        $data->priority    = ProjectTaskPriority::tryFrom((string) $post->get('priority', '')) ?? ProjectTaskPriority::Medium;

        $owner         = (string) $post->get('ownerId', '');
        $data->ownerId = ctype_digit($owner) && (int) $owner > 0 ? (int) $owner : null;

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

        $template          = trim((string) $post->get('template', ''));
        $data->templateKey = $template !== '' ? $template : null;
        $data->createDriveFolder = $post->getBoolean('createDriveFolder');

        return $data;
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = $this->parseErrors;

        $length = mb_strlen($this->name);
        if ($length < 2 || $length > 150) {
            $errors[] = 'Le nom du projet doit faire entre 2 et 150 caractères.';
        }
        if ($this->description !== null && mb_strlen($this->description) > 10000) {
            $errors[] = 'La description ne peut pas dépasser 10 000 caractères.';
        }
        if (!in_array($this->color, ProjectService::COLORS, true)) {
            $errors[] = 'Choisis une couleur dans la palette proposée.';
        }
        if ($this->startDate !== null && $this->dueDate !== null && $this->dueDate < $this->startDate) {
            $errors[] = 'L\'échéance doit être postérieure à la date de début.';
        }

        return $errors;
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\Project;

use App\Enum\ProjectNoteColor;
use Symfony\Component\HttpFoundation\Request;

/**
 * ProjectNoteData — données saisies pour une note du mur d'équipe (ADR-0037).
 */
final class ProjectNoteData
{
    public ?string $title = null;
    public string $content = '';
    public ProjectNoteColor $color = ProjectNoteColor::Yellow;
    public ?int $projectId = null;
    public bool $pinned = false;

    public static function fromRequest(Request $request): self
    {
        $post = $request->request;
        $data = new self();

        $title         = trim((string) $post->get('title', ''));
        $data->title   = $title !== '' ? $title : null;
        $data->content = trim((string) $post->get('content', ''));
        $data->color   = ProjectNoteColor::tryFrom((string) $post->get('color', '')) ?? ProjectNoteColor::Yellow;

        $project         = (string) $post->get('projectId', '');
        $data->projectId = ctype_digit($project) && (int) $project > 0 ? (int) $project : null;
        $data->pinned    = $post->getBoolean('pinned');

        return $data;
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = [];

        if ($this->content === '') {
            $errors[] = 'La note ne peut pas être vide.';
        } elseif (mb_strlen($this->content) > 10000) {
            $errors[] = 'Une note ne peut pas dépasser 10 000 caractères.';
        }
        if ($this->title !== null && mb_strlen($this->title) > 150) {
            $errors[] = 'Le titre de la note ne peut pas dépasser 150 caractères.';
        }

        return $errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\DTO\Project\ProjectNoteData;
use App\Entity\ProjectActivity;
use App\Entity\ProjectNote;
use App\Entity\User;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ProjectNoteService — mur de notes signées de l'Espace projets (ADR-0037).
 *
 * « Qui a écrit quoi » : l'autrice est fixée à la création et ne change jamais,
 * même si la note est épinglée par une autre personne. La date de modification
 * n'est mise à jour que lorsque le TEXTE change (ProjectNote::markEdited()).
 *
 * Les droits (seule l'autrice modifie / supprime) sont vérifiés par
 * ProjectVoter::NOTE_EDIT dans le contrôleur, AVANT d'appeler ce service.
 */
class ProjectNoteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectActivityLogger $activityLogger,
    ) {}

    public function create(ProjectNoteData $data, User $author): ProjectNote
    {
        $note = (new ProjectNote())->setAuthor($author);
        $this->apply($note, $data);
        $note->setPinned($data->pinned);
        $this->em->persist($note);

        $label = $note->getTitle() !== null ? ProjectActivityLogger::quote($note->getTitle()) : 'une note';
        $this->activityLogger->log(ProjectActivity::NOTE_ADDED, sprintf('a écrit %s sur le mur', $label), $author, $note->getProject());
        $this->em->flush();

        return $note;
    }

    public function update(ProjectNote $note, ProjectNoteData $data): void
    {
        $changed = $note->getContent() !== $data->content || $note->getTitle() !== $data->title;
        $this->apply($note, $data);
        if ($changed) {
            $note->markEdited();
        }
        $this->em->flush();
    }

    /** Épingler / désépingler : ouvert à toute l'équipe (ne modifie pas la signature). */
    public function togglePin(ProjectNote $note): void
    {
        $note->setPinned(!$note->isPinned());
        $this->em->flush();
    }

    public function delete(ProjectNote $note): void
    {
        $this->em->remove($note);
        $this->em->flush();
    }

    private function apply(ProjectNote $note, ProjectNoteData $data): void
    {
        $note
            ->setTitle($data->title)
            ->setContent($data->content)
            ->setColor($data->color)
            ->setProject($data->projectId !== null ? $this->projectRepository->find($data->projectId) : null);
    }
}

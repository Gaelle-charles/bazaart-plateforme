<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\ProjectActivity;
use App\Entity\ProjectTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ProjectActivityLogger — écrit une ligne dans le journal d'activité (ADR-0037).
 *
 * Le message est le PRÉDICAT de la phrase ; l'autrice est affichée à part par le
 * template : « <strong>Gaëlle</strong> a déplacé « Réserver la salle » vers En cours ».
 *
 * Pas de flush() ici : c'est le service appelant qui flush une seule fois à la fin
 * de son opération (l'action ET sa trace sont enregistrées dans la même transaction).
 */
class ProjectActivityLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function log(string $action, string $message, User $actor, ?Project $project = null, ?ProjectTask $task = null): void
    {
        $activity = (new ProjectActivity())
            ->setAction($action)
            ->setMessage($message)
            ->setActor($actor)
            ->setTask($task)
            // Si on ne précise pas le projet, on le déduit de la tâche : l'activité
            // apparaît ainsi dans le fil du projet ET dans celui de la tâche.
            ->setProject($project ?? $task?->getProject());

        $this->em->persist($activity);
    }

    /** Titre de tâche entre guillemets français, tronqué pour rester lisible. */
    public static function quote(string $title): string
    {
        $title = mb_strlen($title) > 80 ? mb_substr($title, 0, 79) . '…' : $title;

        return '« ' . $title . ' »';
    }
}

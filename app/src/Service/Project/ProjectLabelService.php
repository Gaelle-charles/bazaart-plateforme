<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\ProjectLabel;
use App\Repository\ProjectLabelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ProjectLabelService — gestion des étiquettes de tâches (ADR-0037).
 *
 * Les étiquettes se créent aussi « à la volée » depuis une tâche
 * (ProjectTaskService::findOrCreateLabel) ; ce service sert à la page
 * « Équipe & réglages » pour les gérer explicitement.
 */
class ProjectLabelService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectLabelRepository $labelRepository,
    ) {}

    /** @return string|null message d'erreur, ou null si l'étiquette est créée */
    public function create(string $name, string $color): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            return 'Le nom d\'une étiquette doit faire entre 1 et 40 caractères.';
        }
        if (!in_array($color, ProjectService::COLORS, true)) {
            return 'Choisis une couleur dans la palette.';
        }
        if ($this->labelRepository->findOneByNameInsensitive($name) !== null) {
            return sprintf('L\'étiquette « %s » existe déjà.', $name);
        }

        $this->em->persist((new ProjectLabel())->setName($name)->setColor($color));
        $this->em->flush();

        return null;
    }

    /** Supprime l'étiquette ; la table de jointure est nettoyée par ON DELETE CASCADE. */
    public function delete(ProjectLabel $label): void
    {
        $this->em->remove($label);
        $this->em->flush();
    }
}

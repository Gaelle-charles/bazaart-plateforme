<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectLabelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectLabel — étiquette libre posée sur les tâches (ADR-0037).
 *
 * Exemples : « Communication », « Budget », « Partenaires », « Logistique ».
 * Les étiquettes permettent un filtrage transversal (toutes les tâches « Budget »
 * de tous les projets). Le nom est unique pour éviter les doublons « Com » / « com ».
 */
#[ORM\Entity(repositoryClass: ProjectLabelRepository::class)]
#[ORM\Table(name: 'project_labels')]
class ProjectLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 40, unique: true)]
    private string $name = '';

    /** Couleur hexadécimale (#RRGGBB) issue de la même palette que les projets. */
    #[ORM\Column(type: 'string', length: 7)]
    private string $color = '#FFCB10';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }
}

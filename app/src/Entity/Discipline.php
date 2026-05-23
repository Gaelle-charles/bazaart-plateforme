<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DisciplineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Discipline représente un domaine artistique.
 * Exemples : "Musique", "Arts visuels", "Théâtre", "Danse", "Littérature"
 *
 * Une ressource peut couvrir plusieurs disciplines (relation ManyToMany).
 * Ces disciplines sont pré-remplies via des fixtures.
 */
#[ORM\Entity(repositoryClass: DisciplineRepository::class)]
#[ORM\Table(name: 'disciplines')]
class Discipline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Nom de la discipline artistique.
     */
    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $name;

    /**
     * Icône emoji optionnelle (ex: "🎵", "🎭", "🖼️").
     */
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $icon = null;

    /**
     * Relation inverse de Resource::$disciplines.
     * On n'a pas besoin de cette collection pour le moment, mais Doctrine en a besoin
     * pour la cohérence de la relation bidirectionnelle.
     */
    #[ORM\ManyToMany(mappedBy: 'disciplines', targetEntity: Resource::class)]
    private Collection $resources;

    public function __construct()
    {
        $this->resources = new ArrayCollection();
    }

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

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function getResources(): Collection
    {
        return $this->resources;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResourceTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * ResourceType représente le type d'une ressource.
 * Exemples : "Résidence artistique", "Appel à projets", "Financement", "Formation"
 *
 * Ces types sont pré-remplis via des fixtures — les utilisateurs n'en créent pas.
 */
#[ORM\Entity(repositoryClass: ResourceTypeRepository::class)]
#[ORM\Table(name: 'resource_types')]
class ResourceType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Nom du type, affiché dans les filtres et les formulaires.
     */
    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $name;

    /**
     * Icône emoji optionnelle pour l'affichage (ex: "🎨", "💰").
     */
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $icon = null;

    /**
     * Une ressource appartient à un seul type, mais un type peut avoir plusieurs ressources.
     * mappedBy = 'resourceType' fait référence à la propriété $resourceType dans Resource.
     */
    #[ORM\OneToMany(mappedBy: 'resourceType', targetEntity: Resource::class)]
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

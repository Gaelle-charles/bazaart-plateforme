<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResourceFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ResourceFavorite représente le fait qu'un utilisateur a mis une ressource en favori.
 *
 * C'est une table de jointure enrichie : elle stocke non seulement la relation
 * User × Resource, mais aussi la date d'ajout en favori.
 *
 * Contrainte d'unicité : un utilisateur ne peut mettre une ressource en favori
 * qu'une seule fois (enforced both côté PHP et côté BDD).
 *
 * onDelete: CASCADE — si l'utilisateur ou la ressource est supprimé(e),
 * tous ses favoris disparaissent automatiquement. C'est le comportement naturel :
 * pas de favori orphelin.
 */
#[ORM\Entity(repositoryClass: ResourceFavoriteRepository::class)]
#[ORM\Table(name: 'resource_favorites')]
// Contrainte d'unicité BDD : interdit deux lignes identiques (user_id, resource_id)
// Cela garantit qu'un double-clic sur "favori" ne crée pas deux entrées en base.
#[ORM\UniqueConstraint(name: 'unique_user_resource_favorite', columns: ['user_id', 'resource_id'])]
#[ORM\HasLifecycleCallbacks]
class ResourceFavorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * L'utilisateur qui a mis la ressource en favori.
     *
     * nullable: false — un favori sans propriétaire n'a pas de sens.
     * onDelete: CASCADE — quand l'utilisateur est supprimé, ses favoris le sont aussi.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * La ressource mise en favori.
     *
     * nullable: false — un favori sans ressource n'a pas de sens.
     * onDelete: CASCADE — quand une ressource est supprimée, ses favoris disparaissent.
     */
    #[ORM\ManyToOne(targetEntity: Resource::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Resource $resource;

    /**
     * Date à laquelle l'utilisateur a ajouté la ressource en favori.
     * Remplie automatiquement au moment du premier INSERT (PrePersist).
     * Non modifiable ensuite (pas de setter public).
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // ─── Lifecycle Callback ────────────────────────────────────────────────

    /**
     * Initialise `createdAt` juste avant le premier INSERT SQL.
     * Cette méthode est appelée automatiquement par Doctrine grâce à
     * l'attribut #[ORM\PrePersist] et à #[ORM\HasLifecycleCallbacks] sur la classe.
     */
    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTime();
    }

    // ─── Getters / Setters ─────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getResource(): Resource
    {
        return $this->resource;
    }

    public function setResource(Resource $resource): static
    {
        $this->resource = $resource;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
    // Pas de setCreatedAt() : ce champ est géré uniquement par le callback PrePersist.
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BazaartAssociation;
use App\Enum\OpportunityReviewStatus;
use App\Repository\ProjectOpportunityReviewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectOpportunityReview — décision de l'équipe sur une opportunité (ADR-0038).
 *
 * Onglet « Opportunités » de l'Espace projets : une opportunité (Resource) qui
 * correspond à nos associations est « à étudier » tant qu'elle n'a pas de ligne ici.
 * Dès que l'équipe la retient, l'écarte ou y candidate, on enregistre la décision
 * (une seule par opportunité : c'est une décision d'ÉQUIPE, partagée par les 3 membres).
 *
 * Candidater crée un projet (modèle « Candidature ») : il est relié ici, pour
 * retrouver le projet depuis l'opportunité et éviter de le créer deux fois.
 */
#[ORM\Entity(repositoryClass: ProjectOpportunityReviewRepository::class)]
#[ORM\Table(name: 'project_opportunity_reviews')]
class ProjectOpportunityReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** L'opportunité. CASCADE : si elle est supprimée du catalogue, la décision disparaît aussi. */
    #[ORM\OneToOne(targetEntity: Resource::class)]
    #[ORM\JoinColumn(name: 'resource_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Resource $resource;

    #[ORM\Column(type: 'string', length: 20, enumType: OpportunityReviewStatus::class)]
    private OpportunityReviewStatus $status = OpportunityReviewStatus::Shortlisted;

    /** Association qui candidate (renseignée au moment de « Candidater »). */
    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: BazaartAssociation::class)]
    private ?BazaartAssociation $association = null;

    /** Projet de candidature créé. SET NULL : supprimer le projet ne supprime pas la décision. */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Resource $resource)
    {
        $this->resource  = $resource;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResource(): Resource
    {
        return $this->resource;
    }

    public function getStatus(): OpportunityReviewStatus
    {
        return $this->status;
    }

    /** Change la décision et mémorise qui l'a prise, et quand. */
    public function decide(OpportunityReviewStatus $status, User $by): static
    {
        $this->status    = $status;
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAssociation(): ?BazaartAssociation
    {
        return $this->association;
    }

    public function setAssociation(?BazaartAssociation $association): static
    {
        $this->association = $association;

        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

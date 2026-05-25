<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LiveStatus;
use App\Repository\LiveRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Live — entité représentant un live planifié sur la plateforme Bazaart.
 *
 * En V1, un live est une annonce avec un lien vers un stream externe
 * (Twitch, Jitsi Meet, Google Meet, YouTube Live, etc.).
 * Le streaming natif intégré est prévu pour la V2.
 *
 * Cycle de vie d'un live :
 *   1. Créé par un admin → statut SCHEDULED
 *   2. L'hôte démarre le stream sur la plateforme externe
 *   3. L'admin passe le statut à LIVE manuellement
 *   4. Après le live, l'admin passe à ENDED et ajoute éventuellement un replayUrl
 *
 * Relations :
 *   - hostedBy → ManyToOne vers User (l'animateur du live)
 *   - attendees → OneToMany vers LiveAttendee (les inscrits)
 *
 * Timestamps :
 *   - createdAt : initialisé en @PrePersist (lecture seule)
 *   - updatedAt : mis à jour en @PrePersist et @PreUpdate via lifecycle callbacks
 */
#[ORM\Entity(repositoryClass: LiveRepository::class)]
#[ORM\Table(name: 'lives')]
#[ORM\HasLifecycleCallbacks]
class Live
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Titre du live — affiché dans le calendrier et les emails de rappel.
     * Obligatoire, 5 à 255 caractères.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(
        min: 5,
        max: 255,
        minMessage: 'Le titre doit faire au moins {{ limit }} caractères.',
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.',
    )]
    private string $title;

    /**
     * Description longue du live — présentation du sujet, de l'intervenant, etc.
     * Optionnelle en V1 (certains lives sont très courts).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Date et heure prévues du live.
     * Obligatoire — c'est l'information centrale du calendrier.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    #[Assert\NotNull(message: 'La date et heure du live sont obligatoires.')]
    private \DateTimeInterface $scheduledAt;

    /**
     * Lien vers la plateforme de stream externe (Twitch, Jitsi, Meet, YouTube).
     * Obligatoire — sans ce lien, les inscrits ne peuvent pas rejoindre le live.
     *
     * Contrainte Url : vérifie que c'est bien une URL valide (https://...).
     * Length max 500 pour les URLs Google Meet qui peuvent être longues.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: false)]
    #[Assert\NotBlank(message: 'Le lien externe vers le stream est obligatoire.')]
    #[Assert\Url(message: 'Veuillez renseigner une URL valide (ex : https://twitch.tv/...).')]
    #[Assert\Length(max: 500, maxMessage: 'L\'URL ne peut pas dépasser {{ limit }} caractères.')]
    private string $externalUrl;

    /**
     * Lien vers le replay du live — renseigné APRÈS la fin du live par l'admin ou l'hôte.
     * En V1 : URL externe (YouTube, Twitch VOD, Google Drive, etc.)
     * En V2 : pourra être un upload direct sur Bunny Stream.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    #[Assert\Url(message: 'Le lien replay doit être une URL valide.')]
    #[Assert\Length(max: 500, maxMessage: 'L\'URL replay ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $replayUrl = null;

    /**
     * Image de couverture du live — affichée dans le calendrier et les emails.
     * En V1 : URL externe. Optionnelle.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    #[Assert\Url(message: 'L\'image de couverture doit être une URL valide.')]
    #[Assert\Length(max: 500, maxMessage: 'L\'URL de l\'image ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $coverImageUrl = null;

    /**
     * Statut du live — géré par l'admin via le dashboard.
     *
     * On stocke le nom (value) de l'enum en base : 'scheduled', 'live', 'ended', 'cancelled'.
     * Doctrine sait désérialiser la string vers l'enum grâce au type 'string' + enumType.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: false, enumType: LiveStatus::class)]
    private LiveStatus $status = LiveStatus::SCHEDULED;

    /**
     * Hôte / animateur du live.
     *
     * ManyToOne : un utilisateur peut animer plusieurs lives.
     * nullable: false : tout live doit avoir un hôte.
     * cascade: [] : on ne cascade rien (un User existe indépendamment des lives).
     *
     * Pourquoi pas nullable ?
     * Un live sans hôte identifié n'a pas de sens sur la plateforme V1.
     * L'admin assigne toujours un hôte à la création.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    // onDelete: 'RESTRICT' → PostgreSQL refuse de supprimer un User tant qu'il anime un live.
    // C'est la sémantique correcte : un hôte de live ne peut pas être supprimé sans traiter ses lives.
    // (La migration Version20260525212745 ne définissait pas de clause ON DELETE, ce qui laisse
    // PostgreSQL utiliser la valeur implicite NO ACTION ≈ RESTRICT, mais sans l'exprimer
    // explicitement dans le schéma. Cette migration corrige ça pour la lisibilité et la cohérence.)
    #[ORM\JoinColumn(name: 'host_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $hostedBy;

    /**
     * Liste des inscrits au live.
     *
     * OneToMany vers LiveAttendee.
     * mappedBy: 'live' → la propriété $live dans LiveAttendee est le côté propriétaire.
     * cascade: ['persist', 'remove'] → si on persiste/supprime un Live, ses LiveAttendee suivent.
     * orphanRemoval: true → si on retire un LiveAttendee de la collection, il est supprimé en BDD.
     *
     * @var Collection<int, LiveAttendee>
     */
    #[ORM\OneToMany(
        mappedBy: 'live',
        targetEntity: LiveAttendee::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $attendees;

    /**
     * Date de création — initialisée une seule fois en @PrePersist.
     * Lecture seule après la première persistance.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $createdAt;

    /**
     * Date de dernière modification — mise à jour à chaque @PrePersist et @PreUpdate.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        // Initialise la collection vide — requis par Doctrine pour les relations OneToMany
        $this->attendees = new ArrayCollection();
    }

    // ─── Lifecycle Callbacks ──────────────────────────────────────────────────

    /**
     * Appelé AVANT la première insertion en BDD.
     * Initialise createdAt et updatedAt.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Appelé AVANT chaque mise à jour en BDD.
     * Met à jour uniquement updatedAt (createdAt ne change jamais après la création).
     */
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getScheduledAt(): \DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTimeInterface $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getExternalUrl(): string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(string $externalUrl): static
    {
        $this->externalUrl = $externalUrl;
        return $this;
    }

    public function getReplayUrl(): ?string
    {
        return $this->replayUrl;
    }

    public function setReplayUrl(?string $replayUrl): static
    {
        $this->replayUrl = $replayUrl;
        return $this;
    }

    public function getCoverImageUrl(): ?string
    {
        return $this->coverImageUrl;
    }

    public function setCoverImageUrl(?string $coverImageUrl): static
    {
        $this->coverImageUrl = $coverImageUrl;
        return $this;
    }

    public function getStatus(): LiveStatus
    {
        return $this->status;
    }

    public function setStatus(LiveStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Raccourci pratique pour tester si le live peut encore recevoir des inscrits.
     * Un live SCHEDULED est le seul où l'inscription a du sens.
     */
    public function isScheduled(): bool
    {
        return $this->status === LiveStatus::SCHEDULED;
    }

    public function getHostedBy(): User
    {
        return $this->hostedBy;
    }

    /**
     * Alias getHost() — utilisé par le LiveVoter via method_exists().
     * Le voter vérifie $subject->getHost() === $user pour les autorisations.
     */
    public function getHost(): User
    {
        return $this->hostedBy;
    }

    public function setHostedBy(User $hostedBy): static
    {
        $this->hostedBy = $hostedBy;
        return $this;
    }

    /**
     * @return Collection<int, LiveAttendee>
     */
    public function getAttendees(): Collection
    {
        return $this->attendees;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}

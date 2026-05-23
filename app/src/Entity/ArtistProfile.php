<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtistProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ArtistProfile représente le profil public d'un artiste.
 * Chaque utilisateur peut avoir au plus UN profil artiste (relation OneToOne).
 */
#[ORM\Entity(repositoryClass: ArtistProfileRepository::class)]
#[ORM\Table(name: 'artist_profiles')]
#[ORM\HasLifecycleCallbacks] // Permet d'utiliser PrePersist et PreUpdate pour les timestamps
class ArtistProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Lien vers l'utilisateur propriétaire du profil.
     * inversedBy = 'artistProfile' signifie que User aura une propriété $artistProfile.
     * cascade: ['persist', 'remove'] = si on supprime l'utilisateur, le profil est supprimé aussi.
     */
    #[ORM\OneToOne(inversedBy: 'artistProfile', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /**
     * Nom d'affichage public de l'artiste (différent de l'email).
     * Ex: "Marie Dupont" ou "DJ Kévin"
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $displayName;

    /**
     * Biographie libre, texte long. nullable car optionnelle.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    /**
     * Ville / pays de l'artiste.
     */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $location = null;

    /**
     * URL du site web personnel.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $websiteUrl = null;

    /**
     * URL du portfolio en ligne (Behance, Artstation, etc.)
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $portfolioUrl = null;

    /**
     * Liens réseaux sociaux stockés en JSON.
     * Format attendu : { "instagram": "https://...", "linkedin": "https://..." }
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $socialLinks = null;

    /**
     * Chemin relatif vers l'avatar (ex: "uploads/avatars/xyz.jpg").
     * Stocké en base, le fichier est sur le serveur.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $avatarPath = null;

    /**
     * Date de création du profil — remplie automatiquement via PrePersist.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Date de dernière modification — mise à jour automatiquement via PreUpdate.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    // --- Lifecycle Callbacks ---

    /**
     * Appelé automatiquement par Doctrine juste avant le premier INSERT.
     * Initialise les deux timestamps.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    /**
     * Appelé automatiquement par Doctrine juste avant chaque UPDATE.
     * Met à jour updatedAt pour tracer la dernière modification.
     */
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // --- Getters / Setters ---

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

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;
        return $this;
    }

    public function getPortfolioUrl(): ?string
    {
        return $this->portfolioUrl;
    }

    public function setPortfolioUrl(?string $portfolioUrl): static
    {
        $this->portfolioUrl = $portfolioUrl;
        return $this;
    }

    public function getSocialLinks(): ?array
    {
        return $this->socialLinks;
    }

    public function setSocialLinks(?array $socialLinks): static
    {
        $this->socialLinks = $socialLinks;
        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): static
    {
        $this->avatarPath = $avatarPath;
        return $this;
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

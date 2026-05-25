<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true, nullable: false)]
    private string $email;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $password;

    #[ORM\Column(type: 'json', nullable: false)]
    private array $roles = [];

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    /**
     * Date d'anonymisation RGPD de ce compte.
     *
     * Null → compte actif normal.
     * Non null → compte anonymisé (email remplacé, mot de passe invalidé).
     *
     * On préfère l'anonymisation à la suppression pour :
     *   1. Préserver l'intégrité référentielle (les posts/ressources restent en BDD)
     *   2. Conserver une trace minimale (date de suppression) pour les obligations légales
     *
     * Après anonymisation :
     *   - email → anonymise_{id}@bazaart-deleted.fr
     *   - password → hash aléatoire inutilisable
     *   - roles → ["ROLE_USER"]
     *   - isVerified → false
     *   - anonymizedAt → datetime de l'opération
     *
     * Convention Doctrine : nullable: true (null = non anonymisé, état par défaut)
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $anonymizedAt = null;

    /**
     * Relation inverse vers ArtistProfile.
     * mappedBy = 'user' fait référence à la propriété $user dans ArtistProfile.
     * Ici orphanRemoval = true : si on retire le profil de l'utilisateur, il est supprimé en BDD.
     */
    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?ArtistProfile $artistProfile = null;

    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // Garantit que chaque utilisateur a au moins ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Si tu stockes des données sensibles temporaires, efface-les ici
    }

    /**
     * Retourne la date d'anonymisation RGPD, ou null si le compte est actif.
     */
    public function getAnonymizedAt(): ?\DateTimeInterface
    {
        return $this->anonymizedAt;
    }

    /**
     * Définit la date d'anonymisation RGPD.
     * Appelé uniquement par RgpdService::anonymizeUser().
     */
    public function setAnonymizedAt(?\DateTimeInterface $anonymizedAt): static
    {
        $this->anonymizedAt = $anonymizedAt;

        return $this;
    }

    /**
     * Raccourci : indique si ce compte a été anonymisé.
     * Utilisé dans les templates Twig et les voters pour bloquer l'accès
     * aux comptes supprimés qui auraient des sessions résiduelles.
     */
    public function isAnonymized(): bool
    {
        return $this->anonymizedAt !== null;
    }

    public function getArtistProfile(): ?ArtistProfile
    {
        return $this->artistProfile;
    }

    public function setArtistProfile(?ArtistProfile $artistProfile): static
    {
        // Synchronise le côté propriétaire de la relation (ArtistProfile.$user)
        if ($artistProfile !== null && $artistProfile->getUser() !== $this) {
            $artistProfile->setUser($this);
        }
        $this->artistProfile = $artistProfile;
        return $this;
    }

    /**
     * Relation inverse vers OrganizationProfile.
     * Un même utilisateur peut avoir un profil artiste ET un profil organisation.
     */
    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?OrganizationProfile $organizationProfile = null;

    public function getOrganizationProfile(): ?OrganizationProfile
    {
        return $this->organizationProfile;
    }

    public function setOrganizationProfile(?OrganizationProfile $organizationProfile): static
    {
        if ($organizationProfile !== null && $organizationProfile->getUser() !== $this) {
            $organizationProfile->setUser($this);
        }
        $this->organizationProfile = $organizationProfile;
        return $this;
    }
}

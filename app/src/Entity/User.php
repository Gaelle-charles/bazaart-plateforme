<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
// Index sur le hash du token de réinitialisation : la recherche par token
// (findByResetTokenHash) s'exécute à chaque clic sur un lien de reset. Déclaré ici
// pour que doctrine:schema:validate reste cohérent avec la migration qui le crée.
#[ORM\Index(name: 'idx_users_reset_token_hash', columns: ['reset_token_hash'])]
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
     * Hash SHA-256 du token de réinitialisation de mot de passe.
     *
     * SÉCURITÉ : on ne stocke JAMAIS le token en clair en BDD.
     * Le token en clair est envoyé par email, son hash SHA-256 est stocké ici.
     * Ainsi, même si la BDD est compromise, les tokens ne peuvent pas être utilisés
     * directement (il faudrait connaître la valeur originale pour la retrouver).
     *
     * Cycle de vie :
     *   - null   : pas de demande de réinitialisation en cours
     *   - string : hash actif jusqu'à resetTokenExpiresAt
     *
     * Taille : SHA-256 produit 64 caractères hexadécimaux (bin2hex sur 32 bytes → 64 chars,
     * puis hash('sha256', ...) → 64 chars hexa). VARCHAR(64) est donc suffisant.
     *
     * Index sur cette colonne pour que la recherche par token soit rapide
     * (cf. UserRepository::findByResetTokenHash()).
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $resetTokenHash = null;
    // Note : l'index idx_users_reset_token_hash est créé dans la migration
    // Version20260611000000 via CREATE INDEX — Doctrine ne supporte pas #[ORM\Index]
    // sur les propriétés (uniquement via #[ORM\Table(indexes: [...])] sur la classe).
    // L'index est donc géré manuellement dans la migration pour éviter toute confusion.

    /**
     * Date d'expiration du token de réinitialisation de mot de passe.
     *
     * Durée de validité : 1 heure après la demande (définie dans PasswordResetService).
     *
     * Null → pas de token actif (jamais demandé ou déjà utilisé/expiré).
     * Non null → le token est valide jusqu'à cette date.
     *
     * Convention : on utilise DateTimeInterface (pas DateTimeImmutable) pour
     * cohérence avec les autres champs datetime de cette entité (createdAt, anonymizedAt).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetTokenExpiresAt = null;

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

    // ─── Getters / Setters — Réinitialisation de mot de passe ───────────────

    /**
     * Retourne le hash SHA-256 du token de réinitialisation, ou null si absent.
     * Appelé par UserRepository::findByResetTokenHash() pour retrouver l'utilisateur.
     */
    public function getResetTokenHash(): ?string
    {
        return $this->resetTokenHash;
    }

    /**
     * Enregistre le hash du token de réinitialisation.
     *
     * @param string|null $resetTokenHash Hash SHA-256 (64 chars) ou null pour invalider
     */
    public function setResetTokenHash(?string $resetTokenHash): static
    {
        $this->resetTokenHash = $resetTokenHash;
        return $this;
    }

    /**
     * Retourne la date d'expiration du token de réinitialisation, ou null.
     * PasswordResetService::validateToken() vérifie que cette date n'est pas dépassée.
     */
    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->resetTokenExpiresAt;
    }

    /**
     * Enregistre la date d'expiration du token.
     *
     * @param \DateTimeInterface|null $resetTokenExpiresAt Expiration (1h après la demande) ou null
     */
    public function setResetTokenExpiresAt(?\DateTimeInterface $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }
}

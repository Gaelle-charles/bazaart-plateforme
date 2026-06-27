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
     * Date de fin de l'essai gratuit d'un mois (ADR-0028).
     *
     * Initialisée à createdAt + 1 mois dans le cycle de vie PrePersist.
     * Pendant que maintenant < trialEndsAt, l'utilisateur a accès à tout le premium
     * exactement comme s'il était abonné — sans aucune action de sa part.
     *
     * Passé cette date (ou si null pour les anciens comptes non backfillés),
     * l'accès bascule vers le mode gratuit (3 matchings/jour).
     *
     * Null → compte créé avant l'introduction de l'essai ET non backfillé.
     *         Un backfill SQL est prévu en production au moment du déploiement
     *         (cf. ADR-0028 §Conséquences) : UPDATE users SET trial_ends_at = NOW() + INTERVAL '1 month'
     *         WHERE trial_ends_at IS NULL. Cette migration ne fait que le schéma.
     *
     * Convention Doctrine : nullable: true (les anciens comptes n'ont pas de trialEndsAt
     * tant que le backfill n'est pas fait ; côté PHP on traite null comme "essai absent/expiré").
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $trialEndsAt = null;

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

    // ─── Identité de l'utilisateur ───────────────────────────────────────────
    //
    // firstName et lastName sont nullable en BDD pour deux raisons :
    //   1. Les 22 comptes existants n'ont pas ces données (pas de migration destructive).
    //   2. Les futures inscriptions les auront toujours (champs required dans le formulaire
    //      ET dans RegisterDTO::fromArray()).
    //
    // Convention Doctrine : VARCHAR(100) NULL → choisi car les noms complets peuvent
    // dépasser 50 caractères (ex : noms composés africains ou hispanophones).

    /**
     * Prénom de l'utilisateur, saisi à l'inscription.
     *
     * Nullable en BDD pour la rétrocompatibilité avec les comptes existants.
     * Pour les nouvelles inscriptions, ce champ est toujours renseigné
     * (le formulaire et RegisterDTO exigent une valeur non vide).
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstName = null;

    /**
     * Nom de famille de l'utilisateur, saisi à l'inscription.
     *
     * Même politique que firstName : nullable en BDD, obligatoire à l'inscription.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastName = null;

    // ─── Champs onboarding (Lot 2) ───────────────────────────────────────────

    /**
     * Indique si l'utilisateur a complété son parcours d'onboarding.
     *
     * false → l'utilisateur sera redirigé vers /onboarding à chaque requête
     *         (via OnboardingGatingListener).
     * true  → accès libre à l'application.
     *
     * Les comptes existants (avant ce champ) sont mis à true via la migration SQL
     * UPDATE pour ne pas les bloquer. Seuls les NOUVEAUX comptes partent à false.
     *
     * Convention Doctrine : NOT NULL, default false (les nouveaux comptes démarrent
     * avec onboarding non complété).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $onboardingCompleted = false;

    /**
     * Liste des objectifs de l'artiste sur la plateforme.
     *
     * Stocké en JSON : tableau de valeurs correspondant aux cases de ArtistLookingFor.
     * Exemple : ["formations", "ressources_appels"]
     *
     * nullable: true → non renseigné avant l'onboarding (et pour les comptes admin/structure).
     *
     * @var array<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lookingFor = null;

    /**
     * Texte libre "autre chose que je recherche" (case AUTRE cochée à l'étape 3).
     *
     * Non null uniquement si ArtistLookingFor::AUTRE est dans $lookingFor.
     * Stocké tel quel, sans formatage (l'utilisateur s'exprime librement).
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $lookingForOther = null;

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
        // Initialise la date de création au moment où l'entité est d'abord persistée.
        // \DateTime (mutable) est utilisé pour cohérence avec les autres champs datetime de cette entité.
        $this->createdAt = new \DateTime();

        // ADR-0028 : tout nouveau compte bénéficie d'1 mois d'essai gratuit dès l'inscription.
        // On clone createdAt pour ne PAS le muter (DateTime est mutable en PHP —
        // un simple ->modify('+1 month') sur $this->createdAt aurait décalé la date d'inscription).
        // Résultat : trialEndsAt = date d'inscription + exactement 1 mois calendaire.
        $this->trialEndsAt = (clone $this->createdAt)->modify('+1 month');
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
        // Synchronise le côté propriétaire de la relation (ArtistProfile.$user).
        // IMPORTANT : on appelle directement setUser() SANS lire getUser() d'abord.
        // Sur un ArtistProfile fraîchement créé (new ArtistProfile()), la propriété
        // typée non-nullable $user n'est pas encore initialisée -> getUser() lèverait
        // "Typed property ArtistProfile::$user must not be accessed before initialization".
        // setUser() est idempotent et ne rappelle pas setArtistProfile() -> pas de récursion.
        if ($artistProfile !== null) {
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

    // ─── Getters / Setters — Onboarding ────────────────────────────────────

    /**
     * Indique si l'utilisateur a complété son onboarding.
     * Utilisé par OnboardingGatingListener pour décider d'autoriser ou non l'accès.
     */
    public function isOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }

    /**
     * Marque l'onboarding comme complété (true) ou non (false).
     * Appelé par OnboardingService::completeOnboarding() à la dernière étape.
     */
    public function setOnboardingCompleted(bool $onboardingCompleted): static
    {
        $this->onboardingCompleted = $onboardingCompleted;
        return $this;
    }

    /**
     * Retourne la liste des objectifs de l'artiste (valeurs de ArtistLookingFor).
     * Null si non encore renseigné (avant onboarding ou compte non artiste).
     *
     * @return array<string>|null
     */
    public function getLookingFor(): ?array
    {
        return $this->lookingFor;
    }

    /**
     * Enregistre les objectifs de l'artiste.
     * Appelé à l'étape 3 de l'onboarding par OnboardingService.
     *
     * @param array<string>|null $lookingFor Tableau de valeurs ArtistLookingFor::value
     */
    public function setLookingFor(?array $lookingFor): static
    {
        $this->lookingFor = $lookingFor;
        return $this;
    }

    /**
     * Retourne le texte libre "autre" si la case AUTRE était cochée.
     * Null si l'utilisateur n'a pas sélectionné AUTRE, ou avant l'onboarding.
     */
    public function getLookingForOther(): ?string
    {
        return $this->lookingForOther;
    }

    /**
     * Enregistre le texte libre "autre chose que je recherche".
     * Appelé par OnboardingService si ArtistLookingFor::AUTRE est sélectionné.
     */
    public function setLookingForOther(?string $lookingForOther): static
    {
        $this->lookingForOther = $lookingForOther;
        return $this;
    }

    // ─── Getters / Setters — Essai gratuit (ADR-0028) ───────────────────────

    /**
     * Retourne la date de fin de l'essai gratuit d'un mois.
     *
     * Null → compte antérieur au déploiement de l'ADR-0028 et non encore backfillé.
     *        Dans ce cas, isInTrial() retourne false (on traite null comme "pas d'essai").
     *
     * Non null → date limite au-delà de laquelle le compte revient au mode gratuit.
     */
    public function getTrialEndsAt(): ?\DateTimeInterface
    {
        return $this->trialEndsAt;
    }

    /**
     * Définit la date de fin de l'essai gratuit.
     *
     * Usage normal : initialisé automatiquement dans initCreatedAt() (PrePersist).
     * Usage admin : permet de prolonger ou révoquer l'essai manuellement si besoin.
     *
     * @param \DateTimeInterface|null $trialEndsAt Date de fin, ou null pour désactiver l'essai.
     */
    public function setTrialEndsAt(?\DateTimeInterface $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;
        return $this;
    }

    /**
     * Indique si le compte est actuellement dans sa période d'essai gratuit.
     *
     * Retourne true si et seulement si :
     *   - trialEndsAt n'est pas null (compte avec essai)
     *   - ET la date de fin est dans le futur (essai pas encore expiré)
     *
     * On compare avec \DateTimeImmutable (instance fraîche = "maintenant") pour éviter
     * toute dérive liée à une instance de DateTime mise en cache.
     *
     * Utilisé par SubscriptionChecker::isSubscribed() pour déverrouiller le premium.
     * Aucune interaction avec Stripe — c'est un accès "offert" côté BDD.
     *
     * ADR-0028 : l'essai débloque l'intégralité des fonctionnalités premium pendant 1 mois.
     */
    public function isInTrial(): bool
    {
        // Null = pas d'essai défini (compte antérieur au backfill ou essai révoqué)
        if ($this->trialEndsAt === null) {
            return false;
        }

        // Compare la fin d'essai avec l'instant présent.
        // \DateTimeImmutable est préféré à new \DateTime() ici :
        // il est immuable (aucun risque de le modifier par accident) et recommandé
        // dans les comparaisons temporelles ponctuelles.
        return $this->trialEndsAt > new \DateTimeImmutable();
    }

    // ─── Getters / Setters — Identité ────────────────────────────────────────

    /**
     * Retourne le prénom de l'utilisateur, ou null pour les anciens comptes.
     */
    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    /**
     * Définit le prénom de l'utilisateur.
     * Appelé par AuthService::register() lors de la création du compte.
     */
    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    /**
     * Retourne le nom de famille de l'utilisateur, ou null pour les anciens comptes.
     */
    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    /**
     * Définit le nom de famille de l'utilisateur.
     * Appelé par AuthService::register() lors de la création du compte.
     */
    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    /**
     * Retourne le nom complet (prénom + nom), pratique pour l'affichage.
     *
     * Exemples :
     *   - getFirstName() = 'Kemi',    getLastName() = 'Adéola'  → 'Kemi Adéola'
     *   - getFirstName() = 'Kemi',    getLastName() = null       → 'Kemi'
     *   - getFirstName() = null,      getLastName() = null       → ''  (ancien compte)
     *
     * trim() évite l'espace superflu si l'un des deux champs est null (concaténation
     * de null + espace = ' ' en PHP avant le trim).
     *
     * Utilisé dans : base.html.twig (À améliorer), emails transactionnels.
     */
    public function getFullName(): string
    {
        // On coalesce null → '' pour éviter les warnings de concaténation
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }
}

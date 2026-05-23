<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * OrganizationProfile représente le profil public d'une organisation
 * (association, galerie, institution culturelle, entreprise...).
 *
 * Une organisation peut soumettre des ressources et organiser des événements.
 * Elle doit être vérifiée par un admin avant d'avoir accès à toutes les fonctionnalités.
 */
#[ORM\Entity(repositoryClass: OrganizationProfileRepository::class)]
#[ORM\Table(name: 'organization_profiles')]
#[ORM\HasLifecycleCallbacks]
class OrganizationProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Lien vers l'utilisateur propriétaire.
     * Un utilisateur ne peut avoir qu'une seule organisation (OneToOne).
     */
    #[ORM\OneToOne(inversedBy: 'organizationProfile', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /**
     * Nom officiel de l'organisation.
     * Ex: "Association Les Arts Vivants", "Galerie Moderna"
     */
    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    /**
     * Numéro SIRET : identifiant unique d'une entreprise française.
     * Format : 14 chiffres (ex: 12345678901234).
     * Nullable car les organisations étrangères n'en ont pas.
     */
    #[ORM\Column(type: 'string', length: 14, nullable: true)]
    private ?string $siret = null;

    /**
     * Description de l'organisation : mission, activités, historique...
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Site web officiel.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $websiteUrl = null;

    /**
     * Email de contact public (différent de l'email de connexion).
     */
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $contactEmail = null;

    /**
     * Localisation du siège / lieu principal.
     */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $location = null;

    /**
     * Chemin vers le logo uploadé (ex: "uploads/logos/xyz.png").
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logoPath = null;

    /**
     * Statut de vérification par un administrateur.
     * false = en attente, true = vérifiée et de confiance.
     * Seul un admin peut passer ce champ à true (pas accessible via le formulaire public).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    /**
     * Date de création du profil — remplie automatiquement via PrePersist.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Date de dernière modification du profil.
     *
     * Pourquoi non-nullable + default SQL `CURRENT_TIMESTAMP` ?
     * La colonne est ajoutée à une table qui contient déjà des lignes (les
     * profils d'organisation existants). Sans valeur par défaut, PostgreSQL
     * refuserait d'ajouter une colonne NOT NULL sur ces lignes. Le DEFAULT
     * SQL fournit une valeur initiale automatique côté base — la valeur PHP
     * est ensuite gérée par les lifecycle callbacks ci-dessous.
     *
     * Cohérence avec `createdAt` : à la création, `updatedAt = createdAt`.
     * Puis à chaque UPDATE Doctrine, le callback PreUpdate la rafraîchit.
     */
    #[ORM\Column(type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeInterface $updatedAt;

    // ─── Compte Structure (cf. CDC V3 §5.8) ──────────────────────────────────
    //
    // Une OrganizationProfile peut devenir un "compte Structure partenaire".
    // C'est un statut spécial qui autorise l'organisation à publier des
    // opportunités sans validation admin (auto-publication).
    //
    // Workflow :
    //   1. L'org s'inscrit via /structure/register
    //      → OrganizationProfile créée avec isStructurePartner = false
    //   2. Un admin valide depuis /admin/structures/pending
    //      → isStructurePartner = true
    //      → structureActivatedAt = NOW()
    //      → structureActivationValidatedBy = l'admin
    //      → l'User reçoit en plus le rôle ROLE_STRUCTURE
    //   3. La structure peut désormais publier en auto-published

    /**
     * true = compte Structure activé (peut publier en auto-publication).
     * false = en attente d'activation par un admin (default à l'inscription).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isStructurePartner = false;

    /**
     * Date d'activation du compte Structure par un admin.
     * Null tant que le compte n'a pas été activé.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $structureActivatedAt = null;

    /**
     * Admin qui a activé le compte Structure. Null si jamais activé.
     *
     * onDelete: SET NULL — si l'admin disparaît, on conserve l'historique
     * du compte Structure activé "par un admin maintenant supprimé".
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $structureActivationValidatedBy = null;

    /**
     * Date à laquelle l'organisation a soumis sa candidature au statut Structure.
     *
     * Trois états possibles :
     *   - null                          → n'a jamais candidaté
     *   - non-null + isStructurePartner = false → candidature en attente de traitement
     *   - non-null + isStructurePartner = true  → candidature acceptée
     *
     * Ce champ est renseigné par StructureService::applyAsStructure() lors de
     * la soumission du formulaire /structure/register.
     * Il est remis à null par StructureService::rejectStructureApplication()
     * pour permettre à l'organisation de re-candidater.
     *
     * C'est un simple timestamp (pas de FK), donc pas de comportement onDelete.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $structureApplicationAt = null;

    // ─────────────────────────────────────────────────────────────────────────

    // --- Lifecycle Callbacks ---

    /**
     * Initialise les deux timestamps juste avant le premier INSERT.
     * À la création, `updatedAt` vaut la même chose que `createdAt`.
     *
     * Méthode renommée depuis `initCreatedAt` pour cohérence avec les autres
     * entités du projet (ArtistProfile, Post, Article) qui utilisent déjà
     * ce nom `initTimestamps`.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now             = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Rafraîchit `updatedAt` juste avant tout UPDATE SQL généré par Doctrine.
     * Aucun setter public n'est exposé : la date de modification est
     * automatique, jamais saisie par l'utilisateur.
     */
    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        // On ne stocke que les chiffres (supprime espaces et tirets éventuels)
        $this->siret = $siret !== null ? preg_replace('/\D/', '', $siret) : null;
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

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;
        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;
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

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;
        return $this;
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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * Pas de setter public : `updatedAt` est entièrement gérée par les
     * lifecycle callbacks (PrePersist et PreUpdate). Cela évite qu'un
     * morceau de code oublie de la mettre à jour, ou la falsifie.
     */
    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    // ─── Getters / Setters compte Structure (CDC §5.8) ───────────────────────

    public function isStructurePartner(): bool
    {
        return $this->isStructurePartner;
    }

    public function setIsStructurePartner(bool $isStructurePartner): static
    {
        $this->isStructurePartner = $isStructurePartner;
        return $this;
    }

    public function getStructureActivatedAt(): ?\DateTimeInterface
    {
        return $this->structureActivatedAt;
    }

    public function setStructureActivatedAt(?\DateTimeInterface $structureActivatedAt): static
    {
        $this->structureActivatedAt = $structureActivatedAt;
        return $this;
    }

    public function getStructureActivationValidatedBy(): ?User
    {
        return $this->structureActivationValidatedBy;
    }

    public function setStructureActivationValidatedBy(?User $validator): static
    {
        $this->structureActivationValidatedBy = $validator;
        return $this;
    }

    // ─── Getter / Setter candidature Structure ────────────────────────────────

    /**
     * Retourne la date de soumission de la candidature Structure.
     * Null = jamais candidaté ou candidature rejetée et annulée.
     */
    public function getStructureApplicationAt(): ?\DateTimeInterface
    {
        return $this->structureApplicationAt;
    }

    /**
     * Renseigne la date de candidature.
     * Appelé par StructureService::applyAsStructure() (mise à now())
     * et par StructureService::rejectStructureApplication() (mise à null).
     */
    public function setStructureApplicationAt(?\DateTimeInterface $structureApplicationAt): static
    {
        $this->structureApplicationAt = $structureApplicationAt;
        return $this;
    }

    // ─── Méthodes utilitaires (helpers) ──────────────────────────────────────

    /**
     * Retourne true si l'organisation a une candidature en attente de traitement.
     * Critère : structureApplicationAt IS NOT NULL ET isStructurePartner = false.
     *
     * Utilisé dans les vues Twig et les tests pour éviter de dupliquer la logique.
     */
    public function hasPendingStructureApplication(): bool
    {
        return $this->structureApplicationAt !== null && !$this->isStructurePartner;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CreatorVerificationStatus;
use App\Repository\CreatorPayoutProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * CreatorPayoutProfile — profil de versement d'un créateur (ADR-0027, Lot 1).
 *
 * Cette entité stocke les coordonnées bancaires et la pièce d'identité d'un membre
 * souhaitant être rémunéré pour ses ateliers/formations déposés sur Bazaart.
 *
 * MODÈLE DE PAIEMENT (ADR-0027) :
 *   - Stripe simple encaisse pour Bazaart (pas de Stripe Connect).
 *   - Bazaart reverse 90 % au créateur par VIREMENT BANCAIRE MANUEL après l'atelier.
 *   - Ce profil fournit les coordonnées nécessaires à ce virement.
 *
 * SÉCURITÉ & RGPD :
 *   - L'IBAN, le SIRET et la pièce d'identité sont des données sensibles.
 *   - La pièce d'identité est stockée HORS du dossier web public (var/secure_uploads/).
 *   - $identityDocumentPath ne contient PAS une URL publique, mais un chemin RELATIF
 *     dans le dossier sécurisé (ex : "creator-docs/a3f8b2c1.pdf").
 *   - L'accès au fichier se fait uniquement via AdminCreatorPayoutController#document()
 *     qui vérifie ROLE_ADMIN avant de streamer le fichier (BinaryFileResponse).
 *   - Voir CreatorDocumentStorage pour la gestion des fichiers.
 *
 * CYCLE DE VIE DU STATUT :
 *   Pending (soumission) → Verified (admin OK) OU Rejected (admin KO).
 *   Toute modification des données repasse le statut à Pending (re-vérification admin).
 */
#[ORM\Entity(repositoryClass: CreatorPayoutProfileRepository::class)]
#[ORM\Table(name: 'creator_payout_profiles')]
#[ORM\HasLifecycleCallbacks]
class CreatorPayoutProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relation avec l'utilisateur ──────────────────────────────────────────

    /**
     * Utilisateur propriétaire de ce profil de versement.
     *
     * OneToOne : un seul profil de paiement par membre.
     * unique: true → contrainte BDD garantissant l'unicité.
     * onDelete: CASCADE → si le compte est supprimé (RGPD), le profil l'est aussi,
     * ce qui déclenche la suppression du fichier via un event listener (à ajouter si besoin).
     */
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    // ─── Coordonnées bancaires ────────────────────────────────────────────────

    /**
     * IBAN (International Bank Account Number) du créateur.
     *
     * Format validé côté DTO via la contrainte Symfony Assert\Iban.
     * Stocké tel quel (avec ou sans espaces) — la validation supprime les espaces
     * avant de tester la somme de contrôle.
     * Longueur max : 34 caractères (IBAN le plus long, ex : Malte).
     */
    #[ORM\Column(type: 'string', length: 34)]
    private string $iban;

    /**
     * BIC/SWIFT du compte bancaire (optionnel — souvent déductible de l'IBAN).
     *
     * 8 à 11 caractères. nullable: true car non obligatoire en V1
     * (les virements SEPA intra-zone euro n'en ont pas besoin depuis 2016).
     */
    #[ORM\Column(type: 'string', length: 11, nullable: true)]
    private ?string $bic = null;

    /**
     * SIRET de l'entreprise ou de l'auto-entrepreneur (14 chiffres).
     *
     * Obligatoire pour les virements soumis à déclaration fiscale.
     * Format : 14 chiffres (SIREN 9 + NIC 5), sans espaces en BDD.
     * Validation : regex /^\d{14}$/ dans le DTO.
     */
    #[ORM\Column(type: 'string', length: 14)]
    private string $siret;

    /**
     * Nom du titulaire du compte bancaire (tel qu'indiqué sur le RIB).
     *
     * Peut différer du nom de l'utilisateur Bazaart (structure tierce, etc.).
     * Obligatoire pour le rapprochement bancaire lors du virement.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $accountHolderName;

    // ─── Pièce d'identité ─────────────────────────────────────────────────────

    /**
     * Chemin RELATIF vers la pièce d'identité dans le dossier sécurisé.
     *
     * IMPORTANT : ce champ NE contient PAS une URL web. C'est un chemin de fichier
     * relatif à "%kernel.project_dir%/var/secure_uploads/" (ex : "creator-docs/a3f8b2c1.pdf").
     * Pour obtenir le chemin absolu : CreatorDocumentStorage::getAbsolutePath($this->identityDocumentPath).
     * Pour accéder au fichier : AdminCreatorPayoutController#document() (contrôle ROLE_ADMIN).
     *
     * nullable: true → le membre peut sauvegarder ses données bancaires sans pièce d'identité
     * (ex : brouillon), mais le statut ne passe en Verified que si le fichier est présent.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $identityDocumentPath = null;

    /**
     * Nom original du fichier uploadé (ex : "CNI_dupont.pdf").
     *
     * Stocké séparément du chemin car le nom de fichier sur disque est un hash
     * non devinable pour des raisons de sécurité. Ce champ permet à l'admin
     * de voir le nom original pour l'identifier dans le formulaire de revue.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $identityDocumentOriginalName = null;

    // ─── Statut de vérification ───────────────────────────────────────────────

    /**
     * Statut de vérification admin du profil (Pending / Verified / Rejected).
     *
     * Repasse à Pending à chaque modification des données (re-vérification nécessaire).
     * Voir CreatorVerificationStatus pour les transitions autorisées.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: CreatorVerificationStatus::class)]
    private CreatorVerificationStatus $status = CreatorVerificationStatus::Pending;

    /**
     * Motif de refus fourni par l'admin (null si Pending ou Verified).
     *
     * Affiché au membre dans son espace pour lui permettre de corriger et re-soumettre.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    // ─── Traçabilité ──────────────────────────────────────────────────────────

    /**
     * Admin ayant validé ou refusé le profil.
     *
     * onDelete: SET NULL → on conserve l'historique si le compte admin est supprimé.
     * null si le profil est encore en attente de revue.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $verifiedBy = null;

    /**
     * Date de la décision de validation/refus (null si Pending).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date de première soumission du profil.
     *
     * Initialisé automatiquement par PrePersist et ne change jamais.
     * Convention du projet : pas de DateTimeImmutable pour cohérence avec les autres entités.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $submittedAt;

    /**
     * Date de dernière modification du profil.
     *
     * Mis à jour automatiquement à chaque flush (PrePersist + PreUpdate).
     * Utile pour l'admin pour voir si le membre a récemment re-soumis ses données.
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    // ─── Lifecycle callbacks ───────────────────────────────────────────────────

    /**
     * Appelé UNE SEULE FOIS lors de la première persistance en base.
     *
     * PrePersist → déclenché juste avant le premier INSERT SQL.
     * On initialise les deux timestamps ici car la méthode PreUpdate
     * n'est appelée que sur les UPDATE (pas le premier INSERT).
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        // isset() sur une propriété typée non-initialisée retourne false
        // → on évite de réinitialiser accidentellement si c'est déjà fixé.
        if (!isset($this->submittedAt)) {
            $this->submittedAt = new \DateTime();
        }
        if (!isset($this->updatedAt)) {
            $this->updatedAt = new \DateTime();
        }
    }

    /**
     * Appelé à chaque mise à jour (UPDATE SQL).
     *
     * PreUpdate → déclenché juste avant chaque UPDATE SQL.
     * On ne met à jour QUE updatedAt, jamais submittedAt.
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getIban(): string
    {
        return $this->iban;
    }

    public function setIban(string $iban): static
    {
        $this->iban = $iban;
        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $bic;
        return $this;
    }

    public function getSiret(): string
    {
        return $this->siret;
    }

    public function setSiret(string $siret): static
    {
        $this->siret = $siret;
        return $this;
    }

    public function getAccountHolderName(): string
    {
        return $this->accountHolderName;
    }

    public function setAccountHolderName(string $accountHolderName): static
    {
        $this->accountHolderName = $accountHolderName;
        return $this;
    }

    public function getIdentityDocumentPath(): ?string
    {
        return $this->identityDocumentPath;
    }

    public function setIdentityDocumentPath(?string $identityDocumentPath): static
    {
        $this->identityDocumentPath = $identityDocumentPath;
        return $this;
    }

    public function getIdentityDocumentOriginalName(): ?string
    {
        return $this->identityDocumentOriginalName;
    }

    public function setIdentityDocumentOriginalName(?string $identityDocumentOriginalName): static
    {
        $this->identityDocumentOriginalName = $identityDocumentOriginalName;
        return $this;
    }

    public function getStatus(): CreatorVerificationStatus
    {
        return $this->status;
    }

    public function setStatus(CreatorVerificationStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?User $verifiedBy): static
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function getSubmittedAt(): \DateTimeInterface
    {
        return $this->submittedAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    // ─── Méthodes métier ──────────────────────────────────────────────────────

    /**
     * Indique si le profil est complet (documents fournis + statut Verified).
     *
     * Utilisé dans les Lots 2 et 3 pour décider si le créateur peut publier/être payé.
     */
    public function isVerified(): bool
    {
        return $this->status === CreatorVerificationStatus::Verified;
    }

    /**
     * Indique si une pièce d'identité a été uploadée.
     *
     * Utile dans les templates pour afficher « document fourni » vs « manquant ».
     */
    public function hasIdentityDocument(): bool
    {
        return $this->identityDocumentPath !== null;
    }
}

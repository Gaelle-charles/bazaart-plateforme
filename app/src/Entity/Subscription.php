<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Subscription — Abonnement actif (ou historique) d'un utilisateur à Bazaart.
 *
 * Cycle de vie (piloté par les webhooks Stripe) :
 *   1. checkout.session.completed (mode=subscription) → création de l'entité (status='active')
 *   2. customer.subscription.updated → mise à jour du status et de currentPeriodEnd
 *   3. customer.subscription.deleted → status='canceled', canceledAt renseigné
 *
 * Règle métier importante :
 *   Un utilisateur ne peut avoir qu'UN SEUL abonnement ACTIF à la fois.
 *   Les anciens abonnements sont conservés en BDD pour l'historique
 *   (ils ont simplement un status != 'active').
 *
 * Le champ stripeSubscriptionId est UNIQUE en base pour éviter les doublons
 * en cas de webhook rejoué (idempotence).
 *
 * Lien avec les fonctionnalités premium :
 *   isActive() = true → l'utilisateur peut accéder à la Ressourcerie premium
 *   et à la Communauté. Les formations restent toujours payées séparément.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
// Index sur user_id pour accélérer la requête "abonnement actif de l'utilisateur X"
#[ORM\Index(name: 'idx_subscriptions_user', columns: ['user_id'])]
// Index sur stripe_subscription_id pour les lookups par ID Stripe (webhooks)
#[ORM\Index(name: 'idx_subscriptions_stripe_id', columns: ['stripe_subscription_id'])]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relation utilisateur ──────────────────────────────────────────────────

    /**
     * L'utilisateur abonné.
     *
     * nullable: false → un abonnement est toujours lié à un utilisateur réel.
     * onDelete: 'CASCADE' → si le compte est supprimé (RGPD), ses abonnements le sont aussi.
     *
     * Pas de relation inverse sur User (évite de surcharger l'entité User).
     * On passera par SubscriptionRepository::findActiveByUser($user).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // ─── Données Stripe ────────────────────────────────────────────────────────

    /**
     * Identifiant de l'abonnement côté Stripe (ex : "sub_1Abc2DefGhi3Jkl").
     *
     * Unique en base pour garantir l'idempotence des webhooks :
     *   si Stripe renvoie le même événement checkout.session.completed deux fois,
     *   la tentative d'INSERT du deuxième déclenche une UniqueConstraintViolation
     *   qu'on peut attraper proprement au lieu de créer un doublon.
     *
     * length: 255 = marge confortable (les IDs Stripe font ~30 chars aujourd'hui).
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false, unique: true)]
    private string $stripeSubscriptionId;

    /**
     * Identifiant du Customer Stripe associé (ex : "cus_1Abc2DefGhi3Jkl").
     *
     * Utile pour retrouver le client Stripe sans refaire une recherche par email.
     * Stocké ici pour les futures opérations (création de portail client, remboursement…).
     *
     * length: 255 = même marge que stripeSubscriptionId.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $stripeCustomerId;

    // ─── Données de l'abonnement ───────────────────────────────────────────────

    /**
     * Plan souscrit : 'monthly' (mensuel 9,90€) ou 'annual' (annuel 79€).
     *
     * Valeur provenant des metadata Stripe définies lors de la création de la session :
     *   metadata['plan'] = 'monthly' | 'annual'
     *
     * length: 20 suffit largement ('monthly' = 7, 'annual' = 6).
     */
    #[ORM\Column(type: 'string', length: 20, nullable: false)]
    private string $plan;

    /**
     * Statut de l'abonnement, copié depuis Stripe.
     *
     * Valeurs possibles (dictées par l'API Stripe) :
     *   - 'active'        → abonnement actif, paiement réussi
     *   - 'trialing'      → en période d'essai gratuite (non utilisé en V1)
     *   - 'past_due'      → paiement échoué, tentatives en cours
     *   - 'canceled'      → abonnement annulé (définitivement)
     *   - 'unpaid'        → paiements trop anciens, abonnement suspendu
     *   - 'incomplete'    → paiement initial incomplet
     *   - 'incomplete_expired' → session de paiement expirée
     *   - 'paused'        → mis en pause (Stripe Billing feature)
     *
     * On copie le statut Stripe tel quel pour rester cohérent avec l'état réel.
     * length: 50 couvre toutes les valeurs actuelles et futures probables.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: false)]
    private string $status;

    /**
     * Date de fin de la période en cours (timestamp Unix → DateTime).
     *
     * Exemple : si l'abonnement mensuel a été souscrit le 1er juin 2026,
     * currentPeriodEnd = 1er juillet 2026.
     *
     * Utilisé par isActive() pour vérifier que l'abonnement n'a pas expiré côté
     * BDD même si le webhook de suppression n'a pas encore été reçu
     * (filet de sécurité supplémentaire, car les webhooks peuvent être retardés).
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $currentPeriodEnd;

    /**
     * Date d'annulation de l'abonnement.
     *
     * null  → abonnement non annulé.
     * Valeur → date à laquelle l'abonnement a été annulé (webhook customer.subscription.deleted).
     *
     * Note : cancel_at_period_end=true = l'abonnement reste actif jusqu'à la fin de la
     * période, puis canceled_at est renseigné. On met à jour ce champ à la réception
     * du webhook deleted, pas à la demande d'annulation de l'utilisateur.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $canceledAt = null;

    // ─── Timestamps ───────────────────────────────────────────────────────────

    /**
     * Date/heure de création de l'enregistrement en BDD.
     * Initialisée par #[ORM\PrePersist] — ne change jamais ensuite.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $createdAt;

    /**
     * Date/heure de la dernière modification de cet enregistrement.
     * Mise à jour à chaque synchronisation avec Stripe (webhook).
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $updatedAt;

    // ─── Callbacks de cycle de vie ────────────────────────────────────────────

    /**
     * Initialise createdAt et updatedAt lors de l'INSERT initial.
     * Appelé automatiquement par Doctrine juste avant le premier INSERT.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Met à jour updatedAt à chaque modification de l'entité.
     * Appelé automatiquement par Doctrine juste avant chaque UPDATE.
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

    public function getStripeSubscriptionId(): string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        return $this;
    }

    public function getStripeCustomerId(): string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function setPlan(string $plan): static
    {
        $this->plan = $plan;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCurrentPeriodEnd(): \DateTimeInterface
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(\DateTimeInterface $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
        return $this;
    }

    public function getCanceledAt(): ?\DateTimeInterface
    {
        return $this->canceledAt;
    }

    public function setCanceledAt(?\DateTimeInterface $canceledAt): static
    {
        $this->canceledAt = $canceledAt;
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

    // ─── Méthodes utilitaires ─────────────────────────────────────────────────

    /**
     * Retourne true si cet abonnement est actif et donne accès aux fonctionnalités premium.
     *
     * Double vérification :
     *   1. Le status doit être 'active' ou 'trialing' (pas 'canceled', 'past_due', etc.)
     *   2. La période en cours ne doit pas être expirée
     *
     * La condition sur currentPeriodEnd est un filet de sécurité :
     *   Si le webhook Stripe de suppression est retardé (réseau, indisponibilité),
     *   cela empêche un abonnement expiré de continuer à accorder l'accès.
     *
     * Utilisé dans les templates Twig, les voters, et le SubscriptionController.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true)
            && $this->currentPeriodEnd > new \DateTimeImmutable();
    }
}

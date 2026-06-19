<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CoursePaymentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * CoursePayment — Trace un paiement unique pour l'accès à une formation payante.
 *
 * Cycle de vie :
 *   1. StripeWebhookController reçoit checkout.session.completed (mode=payment)
 *      → création d'un CoursePayment avec status='completed'
 *   2. (V2) Si remboursement Stripe → status passe à 'refunded'
 *
 * Rôle de "preuve d'achat" :
 *   Si un CoursePayment avec status='completed' existe pour (user + course),
 *   l'utilisateur a accès à la formation, indépendamment de tout abonnement.
 *   Les formations sont TOUJOURS payées séparément, même pour les abonnés.
 *
 * Idempotence :
 *   stripePaymentIntentId est UNIQUE en base → un paiement Stripe ne peut
 *   générer qu'un seul CoursePayment (protection contre les webhooks rejoués).
 */
#[ORM\Entity(repositoryClass: CoursePaymentRepository::class)]
#[ORM\Table(name: 'course_payments')]
// Index sur user_id + course_id pour vérifier rapidement si un utilisateur a acheté une formation
#[ORM\Index(name: 'idx_course_payments_user_course', columns: ['user_id', 'course_id'])]
#[ORM\HasLifecycleCallbacks]
class CoursePayment
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * L'utilisateur qui a effectué le paiement.
     *
     * nullable: false → un paiement est toujours lié à un utilisateur identifié.
     * onDelete: 'CASCADE' → si le compte est supprimé (RGPD), les paiements le sont aussi.
     *
     * Note RGPD : en production, une anonymisation serait préférable à une suppression
     * pour conserver les traces comptables. À gérer en V2.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * La formation achetée.
     *
     * nullable: false → un paiement est toujours lié à une formation précise.
     * onDelete: 'CASCADE' → si la formation est supprimée, ses paiements le sont aussi.
     *
     * Note : en production, il vaudrait mieux garder les paiements même si la formation
     * est dépubliée (conserver les preuves d'achat). La suppression physique d'une
     * formation devrait être très rare — à sécuriser en V2 (soft-delete).
     */
    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    // ─── Données Stripe ────────────────────────────────────────────────────────

    /**
     * ID du PaymentIntent Stripe (ex : "pi_xxx").
     *
     * Null possible si la session Checkout n'a pas encore généré de PaymentIntent
     * (rare, mais peut arriver si le webhook arrive avant la finalisation du paiement).
     *
     * UNIQUE en base pour l'idempotence des webhooks (voir commentaire de classe).
     * On utilise nullable: true car unique: true avec nullable n'est pas supporté
     * par tous les SGBD — PostgreSQL autorise plusieurs NULL dans un index unique.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, unique: true)]
    private ?string $stripePaymentIntentId = null;

    /**
     * ID de la session Checkout Stripe (ex : "cs_xxx").
     *
     * Utile pour le débogage et pour relier le paiement à la session d'achat.
     * Pas UNIQUE car une session peut techniquement être associée à plusieurs
     * tentatives de paiement (même si rare en pratique).
     *
     * Nullable : renseigné au moment du webhook, peut être null pendant le traitement.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    // ─── Données de paiement ──────────────────────────────────────────────────

    /**
     * Montant réellement payé, en centimes d'euro.
     *
     * Copié depuis l'événement Stripe au moment du paiement.
     * Peut différer de Course::priceInCents si un coupon de réduction a été appliqué
     * (gestion des coupons Stripe = V2).
     *
     * nullable: false → on doit toujours connaître le montant d'un paiement.
     */
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $amountInCents;

    /**
     * Statut du paiement.
     *
     * Valeurs possibles :
     *   - 'pending'   → paiement initié mais pas encore confirmé (cas rare)
     *   - 'completed' → paiement confirmé, accès à la formation accordé
     *   - 'refunded'  → remboursé (gestion V2 via webhook charge.refunded)
     *
     * length: 50 couvre les valeurs actuelles et futures probables.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: false)]
    private string $status;

    // ─── Données de remboursement ─────────────────────────────────────────────

    /**
     * Date et heure du remboursement Stripe.
     *
     * null tant que le paiement n'est pas remboursé.
     * Renseigné par EventCancellationService::processRefund() ou par le webhook
     * charge.refunded lors d'un remboursement initié depuis le dashboard Stripe.
     *
     * Permet de calculer le délai entre achat et remboursement pour l'audit.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $refundedAt = null;

    /**
     * ID du remboursement Stripe (ex : "re_xxx").
     *
     * Retourné par l'API Stripe Refund create.
     * Utile pour les litiges et le suivi côté dashboard Stripe.
     * Null si le remboursement n'a pas encore été effectué.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeRefundId = null;

    // ─── Timestamp ────────────────────────────────────────────────────────────

    /**
     * Date/heure du paiement (= moment où le webhook a été reçu et traité).
     * Initialisée par #[ORM\PrePersist] — ne change jamais ensuite.
     *
     * Important pour la comptabilité et les obligations légales de conservation.
     */
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $createdAt;

    // ─── Callback de cycle de vie ─────────────────────────────────────────────

    /**
     * Initialise createdAt à l'instant de la persistance initiale.
     * Appelé automatiquement par Doctrine avant le premier INSERT.
     */
    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTime();
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

    public function getCourse(): Course
    {
        return $this->course;
    }

    public function setCourse(Course $course): static
    {
        $this->course = $course;
        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;
        return $this;
    }

    public function getStripeCheckoutSessionId(): ?string
    {
        return $this->stripeCheckoutSessionId;
    }

    public function setStripeCheckoutSessionId(?string $stripeCheckoutSessionId): static
    {
        $this->stripeCheckoutSessionId = $stripeCheckoutSessionId;
        return $this;
    }

    public function getAmountInCents(): int
    {
        return $this->amountInCents;
    }

    public function setAmountInCents(int $amountInCents): static
    {
        $this->amountInCents = $amountInCents;
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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getRefundedAt(): ?\DateTimeInterface
    {
        return $this->refundedAt;
    }

    public function setRefundedAt(?\DateTimeInterface $refundedAt): static
    {
        $this->refundedAt = $refundedAt;
        return $this;
    }

    public function getStripeRefundId(): ?string
    {
        return $this->stripeRefundId;
    }

    public function setStripeRefundId(?string $stripeRefundId): static
    {
        $this->stripeRefundId = $stripeRefundId;
        return $this;
    }

    // ─── Méthodes utilitaires ─────────────────────────────────────────────────

    /**
     * Retourne true si ce paiement est confirmé (accès à la formation accordé).
     *
     * Utilisé pour la vérification d'accès dans le CourseController :
     *   if (!$payment || !$payment->isCompleted()) → accès refusé
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Retourne true si ce paiement a déjà été remboursé.
     *
     * CRITIQUE pour l'idempotence : EventCancellationService::processRefund()
     * vérifie isRefunded() AVANT d'appeler l'API Stripe Refund.
     * Sans cette vérification, un double appel rembourserait deux fois.
     */
    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    /**
     * Retourne le montant formaté en euros pour l'affichage.
     *
     * Exemple : 2900 → "29,00 €"
     * Utilisé dans les emails de confirmation de paiement et les pages de succès.
     */
    public function getFormattedAmount(): string
    {
        return number_format($this->amountInCents / 100, 2, ',', ' ') . ' €';
    }
}

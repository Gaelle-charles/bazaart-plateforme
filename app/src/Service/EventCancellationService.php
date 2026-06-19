<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Course;
use App\Entity\CourseEnrollment;
use App\Entity\CoursePayment;
use App\Enum\EnrollmentStatus;
use App\Exception\RefundNotEligibleException;
use App\Repository\CourseEnrollmentRepository;
use App\Repository\CoursePaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Refund as StripeRefund;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * EventCancellationService — Logique d'annulation et de remboursement des événements.
 *
 * Ce service centralise TOUTE la logique métier liée à l'annulation d'une inscription
 * à un événement, qu'elle soit initiée par le membre ou par l'admin.
 *
 * RÈGLE MÉTIER (ADR-0014 Phase 3) :
 *
 *   Annulation par le MEMBRE :
 *     → Remboursement intégral autorisé UNIQUEMENT si :
 *         (a) l'annulation a lieu dans les $refundWithdrawalDays jours suivant l'achat
 *         (b) ET avant la date de début de l'événement (eventStartAt)
 *     → Si l'une des deux conditions n'est pas remplie : inscription annulée mais
 *        SANS remboursement (ou blocage selon le mode choisi).
 *     → Événement GRATUIT : annulation simple sans remboursement.
 *
 *   Annulation par l'ADMIN :
 *     → Peut rembourser à TOUT moment, sans condition de délai.
 *     → Peut annuler un événement entier (boucle sur tous les inscrits payants actifs)
 *        ou une inscription individuelle.
 *
 * IDEMPOTENCE (critique — c'est de l'argent) :
 *   processRefund() vérifie TOUJOURS $payment->isRefunded() avant d'appeler Stripe.
 *   Un paiement déjà 'refunded' n'est JAMAIS remboursé deux fois.
 *
 * STRIPE EN TEST :
 *   Les appels à l'API Stripe Refund utilisent les clés TEST configurées dans .env.local.
 *   En dev, les remboursements apparaissent dans le dashboard Stripe TEST, pas en production.
 *   Voir : https://dashboard.stripe.com/test/refunds
 */
class EventCancellationService
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly CourseEnrollmentRepository  $enrollmentRepository,
        private readonly CoursePaymentRepository     $paymentRepository,
        private readonly MailerInterface             $mailer,
        private readonly UrlGeneratorInterface       $urlGenerator,
        // Injecté via binding global dans services.yaml ($adminEmail → ADMIN_EMAIL)
        private readonly string                      $adminEmail,
        // Fenêtre de remboursement en jours (binding $refundWithdrawalDays → REFUND_WITHDRAWAL_DAYS)
        // Valeur par défaut : 14 jours (configurable dans .env ou .env.local)
        private readonly int                         $refundWithdrawalDays,
        private readonly LoggerInterface             $logger,
    ) {}

    // ─── Annulation par le MEMBRE ─────────────────────────────────────────────

    /**
     * Annule une inscription à la demande du membre.
     *
     * Vérifie que la fenêtre de remboursement est respectée :
     *   - Le paiement a été effectué il y a moins de $refundWithdrawalDays jours
     *   - La date de début de l'événement n'est pas encore dépassée
     *
     * Si les deux conditions sont remplies ET que l'événement est payant :
     *   → Remboursement Stripe + marquage 'refunded' + annulation + email
     *
     * Si l'une des conditions n'est pas remplie ET que l'événement est payant :
     *   → Lève une exception RefundNotEligibleException (affichage d'un message clair)
     *   → L'annulation elle-même n'est pas effectuée (blocage UX)
     *
     * Si l'événement est gratuit :
     *   → Annulation simple (pas de remboursement) + email
     *
     * @param CourseEnrollment $enrollment L'inscription à annuler
     *
     * @throws \LogicException                          Si l'inscription n'est pas ACTIVE
     * @throws \App\Exception\RefundNotEligibleException Si hors fenêtre de remboursement (payant seulement)
     * @throws \RuntimeException                        En cas d'erreur Stripe inattendue
     */
    public function cancelByMember(CourseEnrollment $enrollment): void
    {
        // ── Garde : l'inscription doit être active ────────────────────────────
        if (!$enrollment->isActive()) {
            throw new \LogicException(
                'Cette inscription a déjà été annulée.'
            );
        }

        $course = $enrollment->getCourse();
        $user   = $enrollment->getUser();

        // ── Détermination du type d'annulation ───────────────────────────────
        // On cherche s'il existe un paiement COMPLÉTÉ pour cette inscription.
        // Si oui → événement payant. Sinon → gratuit.
        $payment = $this->paymentRepository->findCompletedPaymentForRefund($user, $course);

        if ($payment !== null) {
            // ── Événement PAYANT : vérification de la fenêtre de remboursement ─
            $isEligible = $this->isEligibleForRefund($payment, $course);

            if (!$isEligible) {
                // Hors fenêtre → on refuse l'annulation (blocage) et on lève une exception.
                // Le controller catchera cette exception pour afficher un message clair.
                //
                // Décision de design : on BLOQUE plutôt que d'annuler sans rembourser.
                // Raison : annuler sans rembourser crée une frustration forte et des litiges.
                // L'utilisateur doit contacter l'équipe pour les cas hors fenêtre → suivi humain.
                throw new RefundNotEligibleException(
                    $this->buildRefundNotEligibleMessage($payment, $course)
                );
            }

            // ── Dans la fenêtre → remboursement Stripe ───────────────────────
            $this->processRefund($payment);
        }

        // ── Annulation de l'inscription ───────────────────────────────────────
        // Que l'événement soit gratuit ou qu'il ait été remboursé, on passe
        // l'inscription à CANCELLED et on renseigne cancelledAt.
        $enrollment->setStatus(EnrollmentStatus::CANCELLED);
        $enrollment->setCancelledAt(new \DateTime());

        // On n'a pas besoin de persist() car l'entité est déjà managée par Doctrine.
        // Elle a été chargée via le repository, donc Doctrine la suit automatiquement.
        $this->em->flush();

        $this->logger->info('Inscription annulée par le membre.', [
            'enrollment_id' => $enrollment->getId(),
            'user_id'       => $user->getId(),
            'course_id'     => $course->getId(),
            'was_paid'      => $payment !== null,
        ]);

        // ── Email de confirmation d'annulation ────────────────────────────────
        try {
            $this->sendCancellationEmailToUser($enrollment, refunded: $payment !== null);
        } catch (\Throwable $e) {
            // L'email est secondaire — l'annulation est déjà en base.
            // On log sans faire échouer l'opération.
            $this->logger->warning('Envoi email annulation membre échoué.', [
                'enrollment_id' => $enrollment->getId(),
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // ─── Annulation par l'ADMIN (inscription individuelle) ───────────────────

    /**
     * Annule une inscription individuelle à la demande d'un admin.
     *
     * L'admin peut rembourser À TOUT MOMENT, sans condition de délai.
     * Si $forceRefund = true et que le paiement est complété → remboursement Stripe.
     * Si $forceRefund = false → annulation simple sans remboursement (utile pour les
     * événements gratuits ou si l'admin veut juste libérer la place).
     *
     * @param CourseEnrollment $enrollment  L'inscription à annuler
     * @param bool             $forceRefund Rembourser le paiement Stripe si présent
     *
     * @throws \LogicException   Si l'inscription n'est pas ACTIVE
     * @throws \RuntimeException En cas d'erreur Stripe
     */
    public function cancelByAdmin(CourseEnrollment $enrollment, bool $forceRefund = true): void
    {
        // ── Garde : l'inscription doit être active ────────────────────────────
        if (!$enrollment->isActive()) {
            throw new \LogicException('Cette inscription a déjà été annulée.');
        }

        $course = $enrollment->getCourse();
        $user   = $enrollment->getUser();

        if ($forceRefund) {
            // Recherche du paiement complété pour cet inscrit
            $payment = $this->paymentRepository->findCompletedPaymentForRefund($user, $course);

            if ($payment !== null) {
                // Remboursement sans condition de délai (admin)
                $this->processRefund($payment);
            }
        }

        // ── Annulation de l'inscription ───────────────────────────────────────
        $enrollment->setStatus(EnrollmentStatus::CANCELLED);
        $enrollment->setCancelledAt(new \DateTime());
        $this->em->flush();

        $this->logger->info('Inscription annulée par un admin.', [
            'enrollment_id' => $enrollment->getId(),
            'user_id'       => $user->getId(),
            'course_id'     => $course->getId(),
            'force_refund'  => $forceRefund,
        ]);

        // Email de notification à l'inscrit
        try {
            $this->sendCancellationEmailToUser($enrollment, refunded: $forceRefund);
        } catch (\Throwable $e) {
            $this->logger->warning('Envoi email annulation admin (individuelle) échoué.', [
                'enrollment_id' => $enrollment->getId(),
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // ─── Annulation d'un événement ENTIER par l'admin ────────────────────────

    /**
     * Annule un événement entier et rembourse tous les inscrits payants actifs.
     *
     * Opération de masse — à n'utiliser que pour l'annulation d'un événement complet
     * (ex : l'intervenant annule, force majeure, etc.).
     *
     * Processus :
     *   1. Chargement de toutes les inscriptions ACTIVES du cours
     *   2. Pour chaque inscription : remboursement Stripe si payant + CANCELLED
     *   3. flush() global après la boucle (un seul flush pour la performance)
     *   4. Email individuel à chaque inscrit
     *
     * Idempotence : les inscriptions déjà CANCELLED et les paiements déjà 'refunded'
     * sont ignorés (aucun double remboursement possible).
     *
     * @param Course $course L'événement à annuler
     * @return array{cancelled: int, refunded: int} Compteurs pour le résumé admin
     */
    public function cancelEventByAdmin(Course $course): array
    {
        // Compteurs pour le résumé affiché à l'admin
        $cancelledCount = 0;
        $refundedCount  = 0;

        // ── Chargement des inscriptions actives ───────────────────────────────
        // findActiveEnrollmentsByCourse() fait un FETCH JOIN sur User pour éviter le N+1.
        $enrollments = $this->enrollmentRepository->findActiveEnrollmentsByCourse($course);

        $this->logger->info('Annulation d\'événement entier démarrée.', [
            'course_id'         => $course->getId(),
            'course_title'      => $course->getTitle(),
            'active_enrollments' => count($enrollments),
        ]);

        foreach ($enrollments as $enrollment) {
            $user = $enrollment->getUser();

            // ── Recherche du paiement pour cet inscrit ────────────────────────
            // On cherche dans les paiements complétés du cours (pas de l'inscrit en particulier
            // car findCompletedPaymentsByCourse() charge tous les paiements en une requête).
            // Ici on passe par findCompletedPaymentForRefund() pour chaque inscrit,
            // ce qui est N requêtes. Pour des événements avec des centaines d'inscrits,
            // il faudrait optimiser en V2. Pour V1 (<50 inscrits max), c'est acceptable.
            $payment        = $this->paymentRepository->findCompletedPaymentForRefund($user, $course);
            // Flag de sécurité : si le remboursement Stripe échoue, on ne doit PAS
            // annuler l'inscription. L'inscrit aurait sinon perdu sa place ET son argent.
            $paymentFailed  = false;

            if ($payment !== null) {
                try {
                    // Remboursement Stripe — processRefund() est idempotent
                    $this->processRefund($payment);
                    $refundedCount++;
                } catch (\Throwable $e) {
                    // Un remboursement échoué ne bloque PAS les autres inscrits (on continue),
                    // MAIS on ne doit PAS annuler CETTE inscription : l'état (inscription ACTIVE,
                    // argent non remboursé) est cohérent — l'admin devra traiter ce cas manuellement.
                    $this->logger->error('Remboursement Stripe échoué — inscription NON annulée.', [
                        'enrollment_id' => $enrollment->getId(),
                        'user_id'       => $user->getId(),
                        'course_id'     => $course->getId(),
                        'error'         => $e->getMessage(),
                    ]);
                    $paymentFailed = true;
                }
            }

            // ── Annulation de l'inscription ───────────────────────────────────
            // On n'annule QUE si le remboursement a réussi (ou s'il n'y avait pas de paiement).
            // Si $paymentFailed = true, l'inscription reste ACTIVE → état cohérent.
            if (!$paymentFailed) {
                $enrollment->setStatus(EnrollmentStatus::CANCELLED);
                $enrollment->setCancelledAt(new \DateTime());
                $cancelledCount++;

                // ── Email individuel ──────────────────────────────────────────
                // Envoyé dans la boucle (pas de batch email en V1 — volume faible).
                // En V2, utiliser Messenger pour l'envoi asynchrone.
                try {
                    $this->sendEventCancelledEmailToUser($enrollment, wasRefunded: $payment !== null);
                } catch (\Throwable $e) {
                    $this->logger->warning('Envoi email annulation événement échoué.', [
                        'enrollment_id' => $enrollment->getId(),
                        'user_id'       => $user->getId(),
                        'error'         => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── Persistance globale ───────────────────────────────────────────────
        // Un seul flush() après la boucle pour toutes les annulations.
        // Les processRefund() individuels ont déjà fait leur flush().
        // Doctrine est intelligent : le flush() global ne re-flush que les entités
        // réellement modifiées depuis le dernier flush().
        $this->em->flush();

        $this->logger->info('Annulation événement terminée.', [
            'course_id'     => $course->getId(),
            'cancelled'     => $cancelledCount,
            'refunded'      => $refundedCount,
        ]);

        return [
            'cancelled' => $cancelledCount,
            'refunded'  => $refundedCount,
        ];
    }

    // ─── Remboursement Stripe ─────────────────────────────────────────────────

    /**
     * Effectue un remboursement intégral via l'API Stripe Refund.
     *
     * IDEMPOTENCE : si $payment->isRefunded() est déjà true, la méthode retourne
     * immédiatement sans appeler Stripe. C'est la protection fondamentale contre
     * les doubles remboursements.
     *
     * Flux :
     *   1. Vérification idempotence ($payment->isRefunded())
     *   2. Vérification que le PaymentIntent est connu (nécessaire pour l'API)
     *   3. Appel \Stripe\Refund::create(['payment_intent' => 'pi_xxx'])
     *   4. Mise à jour en base : status='refunded', refundedAt, stripeRefundId
     *   5. flush() immédiat (ne pas attendre le flush() global pour fiabiliser la trace)
     *
     * Pourquoi flush() immédiatement dans cette méthode ?
     *   Si un crash survient après le remboursement Stripe et avant le flush(), on aura
     *   un remboursement en argent réel sans trace en base → problème comptable grave.
     *   Le flush() immédiat minimise ce risque (fenêtre de crash réduite à quelques ms).
     *
     * @param CoursePayment $payment Le paiement à rembourser
     *
     * @throws \LogicException   Si le paiement n'a pas de PaymentIntent ID (impossible de rembourser)
     * @throws \RuntimeException En cas d'erreur Stripe API
     */
    public function processRefund(CoursePayment $payment): void
    {
        // ── Garde idempotence ─────────────────────────────────────────────────
        // Si déjà remboursé (ex: webhook charge.refunded déjà traité, ou double appel),
        // on retourne sans rien faire. Pas d'exception : c'est le comportement attendu.
        if ($payment->isRefunded()) {
            $this->logger->info('Remboursement ignoré — paiement déjà marqué "refunded".', [
                'payment_id' => $payment->getId(),
            ]);
            return;
        }

        // ── Vérification du PaymentIntent ID ─────────────────────────────────
        $paymentIntentId = $payment->getStripePaymentIntentId();
        if ($paymentIntentId === null) {
            // Cas très rare : paiement créé sans PaymentIntent ID (survente partielle).
            // On ne peut pas rembourser via l'API sans cet identifiant.
            throw new \LogicException(
                sprintf(
                    'Impossible de rembourser le paiement #%d : PaymentIntent ID manquant. '
                    . 'Remboursez manuellement via le dashboard Stripe.',
                    $payment->getId() ?? 0,
                )
            );
        }

        // ── Appel Stripe Refund API ───────────────────────────────────────────
        // \Stripe\Refund::create() utilise la clé secrète configurée dans StripeService.
        // En mode TEST (STRIPE_SECRET_KEY=sk_test_xxx), aucun argent réel n'est débité.
        //
        // 'payment_intent' est l'identifiant préféré pour les remboursements car il
        // remboursera le dernier charge associé au PaymentIntent (comportement par défaut).
        // On pourrait aussi passer 'charge' (l'ID du charge spécifique), mais le PaymentIntent
        // est plus robuste (fonctionne même si le charge a été capturé en plusieurs étapes).
        try {
            $stripeRefund = StripeRefund::create([
                'payment_intent' => $paymentIntentId,
                // 'amount' absent → remboursement INTÉGRAL (comportement par défaut Stripe)
                // Pour un remboursement partiel, ajouter : 'amount' => $amountInCents
                'reason' => 'requested_by_customer',
                // Métadonnées utiles pour le suivi dans le dashboard Stripe.
                // Stripe exige des métadonnées de type array<string, string> → on caste en string.
                // Les ID sont des int|null dans l'entité, on les convertit explicitement.
                'metadata' => [
                    'course_id'     => (string) ($payment->getCourse()->getId() ?? 0),
                    'user_id'       => (string) ($payment->getUser()->getId() ?? 0),
                    'payment_id'    => (string) ($payment->getId() ?? 0),
                    'initiated_by'  => 'bazaart_platform',
                ],
            ]);

            $this->logger->info('Remboursement Stripe créé.', [
                'payment_id'       => $payment->getId(),
                'stripe_refund_id' => $stripeRefund->id,
                'amount'           => $stripeRefund->amount,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Erreur Stripe (ex : PaymentIntent déjà remboursé côté Stripe mais pas en base,
            // paiement trop ancien, etc.)
            $this->logger->error('Erreur API Stripe lors du remboursement.', [
                'payment_id'       => $payment->getId(),
                'payment_intent'   => $paymentIntentId,
                'stripe_error'     => $e->getMessage(),
                'stripe_error_code' => $e->getStripeCode(),
            ]);
            throw new \RuntimeException(
                sprintf('Erreur Stripe lors du remboursement : %s', $e->getMessage()),
                0,
                $e,
            );
        }

        // ── Mise à jour de l'entité CoursePayment ─────────────────────────────
        // On marque le paiement comme remboursé immédiatement après la confirmation Stripe.
        $payment->setStatus('refunded');
        $payment->setRefundedAt(new \DateTime());
        $payment->setStripeRefundId($stripeRefund->id);

        // ── Flush immédiat ────────────────────────────────────────────────────
        // Critique : on persiste IMMÉDIATEMENT après le remboursement Stripe pour
        // minimiser la fenêtre de crash (voir doc de la méthode).
        // Ce flush() ne touche que le CoursePayment (les autres entités éventuellement
        // modifiées dans la transaction parente sont flushées par leur propre flush()).
        $this->em->flush();

        $this->logger->info('Paiement marqué "refunded" en base.', [
            'payment_id'       => $payment->getId(),
            'stripe_refund_id' => $stripeRefund->id,
        ]);
    }

    // ─── Vérification d'éligibilité au remboursement ─────────────────────────

    /**
     * Vérifie si une inscription est éligible au remboursement selon la règle métier.
     *
     * Règle exacte (ADR-0014 Phase 3) :
     *   (a) L'annulation a lieu dans les $refundWithdrawalDays jours suivant l'achat
     *   (b) ET avant la date de début de l'événement (eventStartAt)
     *
     * Les deux conditions sont CONJONCTIVES (ET) : une seule suffit pour bloquer.
     *
     * @param CoursePayment $payment Le paiement dont on vérifie l'éligibilité
     * @param Course        $course  La formation-événement (pour vérifier eventStartAt)
     *
     * @return bool true si le remboursement est éligible, false sinon
     */
    public function isEligibleForRefund(CoursePayment $payment, Course $course): bool
    {
        $now = new \DateTime();

        // ── Condition (a) : fenêtre de rétractation ───────────────────────────
        // Date limite de remboursement = date d'achat + $refundWithdrawalDays jours
        // DateInterval('P14D') = "Period of 14 Days"
        // On utilise \DateTime::createFromInterface() pour obtenir un objet mutable,
        // car DateTimeInterface n'expose pas add() — seul DateTime/DateTimeImmutable le font.
        // \DateTime est mutable et supporte add() → on peut chaîner directement.
        $createdAtMutable = \DateTime::createFromInterface($payment->getCreatedAt());
        $refundDeadline   = $createdAtMutable->add(new \DateInterval("P{$this->refundWithdrawalDays}D"));

        $isWithinWithdrawalWindow = $now <= $refundDeadline;

        // ── Condition (b) : avant le début de l'événement ────────────────────
        $eventStartAt = $course->getEventStartAt();
        $isBeforeEventStart = $eventStartAt === null || $now < $eventStartAt;
        // Note : si eventStartAt est null (événement mal configuré), on considère
        // que l'événement n'a pas encore commencé → condition validée par précaution.

        $isEligible = $isWithinWithdrawalWindow && $isBeforeEventStart;

        // Log pour l'audit (utile pour déboguer les cas limites)
        $this->logger->debug('Vérification éligibilité remboursement.', [
            'payment_id'           => $payment->getId(),
            'created_at'           => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
            'refund_deadline'      => $refundDeadline->format('Y-m-d H:i:s'),
            'event_start_at'       => $eventStartAt?->format('Y-m-d H:i:s'),
            'now'                  => $now->format('Y-m-d H:i:s'),
            'within_window'        => $isWithinWithdrawalWindow,
            'before_event_start'   => $isBeforeEventStart,
            'eligible'             => $isEligible,
        ]);

        return $isEligible;
    }

    // ─── Emails ───────────────────────────────────────────────────────────────

    /**
     * Envoie un email de confirmation d'annulation à l'inscrit.
     *
     * Adapte le message selon que l'événement était payant (et donc remboursé)
     * ou gratuit (simple annulation sans remboursement).
     *
     * @param CourseEnrollment $enrollment L'inscription annulée
     * @param bool             $refunded   true si un remboursement Stripe a été effectué
     */
    private function sendCancellationEmailToUser(CourseEnrollment $enrollment, bool $refunded): void
    {
        $course = $enrollment->getCourse();
        $user   = $enrollment->getUser();

        $subject = sprintf('[Bazaart] Annulation de votre inscription — %s', $course->getTitle());

        if ($refunded) {
            $body = implode("\n\n", [
                'Bonjour,',
                sprintf(
                    'Votre inscription à l\'événement « %s » a bien été annulée et votre paiement vous sera remboursé intégralement.',
                    $course->getTitle(),
                ),
                'Le remboursement sera effectif sur votre moyen de paiement d\'origine sous 5 à 10 jours ouvrés, selon votre banque.',
                'Si vous avez des questions, répondez directement à cet email.',
                'À très bientôt,',
                'L\'équipe Bazaart',
            ]);
        } else {
            $body = implode("\n\n", [
                'Bonjour,',
                sprintf(
                    'Votre inscription à l\'événement « %s » a bien été annulée.',
                    $course->getTitle(),
                ),
                'Votre place a été libérée.',
                'Vous pouvez explorer d\'autres événements sur le catalogue :',
                $this->urlGenerator->generate('app_course_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'À très bientôt,',
                'L\'équipe Bazaart',
            ]);
        }

        $email = (new Email())
            ->from(new Address('noreply@bazaart.fr', 'Bazaart'))
            ->replyTo($this->adminEmail)
            ->to($user->getEmail())
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }

    /**
     * Envoie un email d'information lors de l'annulation d'un événement entier par l'admin.
     *
     * Message différent de sendCancellationEmailToUser() : on précise que c'est
     * l'ÉVÉNEMENT qui est annulé (pas seulement l'inscription du membre).
     *
     * @param CourseEnrollment $enrollment  L'inscription annulée
     * @param bool             $wasRefunded true si un remboursement a été effectué
     */
    private function sendEventCancelledEmailToUser(CourseEnrollment $enrollment, bool $wasRefunded): void
    {
        $course = $enrollment->getCourse();
        $user   = $enrollment->getUser();

        $subject = sprintf('[Bazaart] Événement annulé — %s', $course->getTitle());

        $refundInfo = $wasRefunded
            ? 'Votre paiement vous sera remboursé intégralement sous 5 à 10 jours ouvrés.'
            : '';

        $body = implode("\n\n", array_filter([
            'Bonjour,',
            sprintf(
                'Nous vous informons que l\'événement « %s » a dû être annulé.',
                $course->getTitle(),
            ),
            'Toutes les inscriptions ont été annulées.',
            $refundInfo ?: null,
            'Nous nous excusons pour la gêne occasionnée.',
            'Pour toute question, répondez directement à cet email.',
            'À très bientôt,',
            'L\'équipe Bazaart',
        ]));

        $email = (new Email())
            ->from(new Address('noreply@bazaart.fr', 'Bazaart'))
            ->replyTo($this->adminEmail)
            ->to($user->getEmail())
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }

    // ─── Message d'erreur ─────────────────────────────────────────────────────

    /**
     * Construit le message d'erreur pour le cas "hors fenêtre de remboursement".
     *
     * Utilisé par cancelByMember() pour construire le message de RefundNotEligibleException.
     */
    private function buildRefundNotEligibleMessage(CoursePayment $payment, Course $course): string
    {
        $createdAtMutable2 = \DateTime::createFromInterface($payment->getCreatedAt());
        $refundDeadline    = $createdAtMutable2->add(new \DateInterval("P{$this->refundWithdrawalDays}D"));

        $now          = new \DateTime();
        $eventStartAt = $course->getEventStartAt();

        // On identifie QUELLE condition a échoué pour un message précis
        $isWithinWindow = $now <= $refundDeadline;
        $isBeforeEvent  = $eventStartAt === null || $now < $eventStartAt;

        if (!$isWithinWindow && !$isBeforeEvent) {
            // Ici : $eventStartAt est forcément non-null (car !$isBeforeEvent implique $eventStartAt !== null).
            // PHPStan le sait via l'analyse de flux de contrôle. On cast pour la lisibilité.
            /** @var \DateTimeInterface $eventStartAt */
            return sprintf(
                'L\'annulation avec remboursement n\'est plus possible : la fenêtre de %d jours '
                . '(expirée le %s) et la date de l\'événement (%s) sont toutes deux dépassées. '
                . 'Pour toute demande exceptionnelle, contactez l\'équipe Bazaart.',
                $this->refundWithdrawalDays,
                $refundDeadline->format('d/m/Y'),
                $eventStartAt->format('d/m/Y'),
            );
        }

        if (!$isWithinWindow) {
            return sprintf(
                'L\'annulation avec remboursement n\'est plus possible : la fenêtre de %d jours '
                . 'suivant l\'achat a expiré le %s. '
                . 'Pour toute demande exceptionnelle, contactez l\'équipe Bazaart.',
                $this->refundWithdrawalDays,
                $refundDeadline->format('d/m/Y'),
            );
        }

        // Seule condition (b) qui a échoué : événement déjà commencé.
        // $isBeforeEvent = false → $eventStartAt !== null (sinon $isBeforeEvent serait true).
        /** @var \DateTimeInterface $eventStartAt */
        return sprintf(
            'L\'annulation avec remboursement n\'est plus possible : l\'événement a commencé le %s. '
            . 'Pour toute demande exceptionnelle, contactez l\'équipe Bazaart.',
            $eventStartAt->format('d/m/Y à H:i'),
        );
    }
}

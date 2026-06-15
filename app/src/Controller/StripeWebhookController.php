<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CourseEnrollment;
use App\Entity\CoursePayment;
use App\Entity\Subscription;
use App\Repository\CourseEnrollmentRepository;
use App\Repository\CoursePaymentRepository;
use App\Repository\CourseRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event as StripeEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * StripeWebhookController — Récepteur des événements Stripe (webhooks).
 *
 * Ce controller est le point d'entrée de toutes les notifications envoyées
 * par Stripe à la plateforme. Il reçoit des événements HTTP POST signés
 * par Stripe sur la route /stripe/webhook.
 *
 * Sécurité :
 *   - La route est PUBLIQUE (pas d'authentification Symfony) — Stripe n'est pas un utilisateur.
 *   - La vérification de la SIGNATURE Stripe (via StripeService::constructWebhookEvent)
 *     remplace le CSRF et l'authentification : seul Stripe connaît le webhook secret.
 *   - Cette vérification doit toujours être la PREMIÈRE opération dans le handler.
 *
 * Événements traités :
 *   - checkout.session.completed (mode=subscription) → crée/met à jour Subscription
 *   - checkout.session.completed (mode=payment)      → crée CoursePayment + CourseEnrollment
 *   - customer.subscription.updated                  → met à jour status et currentPeriodEnd
 *   - customer.subscription.deleted                  → annule l'abonnement
 *   - invoice.payment_failed                         → log de l'échec (notifications email en V2)
 *
 * Convention de réponse :
 *   - 200 {"received": true} → événement traité avec succès
 *   - 400 {"error": "..."}   → signature invalide ou données manquantes
 *   Stripe considère tout 2xx comme un acquittement. Un 4xx ou 5xx déclenchera
 *   des nouvelles tentatives de la part de Stripe (jusqu'à 72h).
 *
 * Cas particulier du CSRF :
 *   Ce controller n'utilise PAS de formulaire HTML, donc le CSRF Symfony
 *   ne s'applique pas. La sécurité est assurée par la vérification de signature.
 *   #[IsGranted('PUBLIC_ACCESS')] garantit que le firewall Symfony ne bloque pas
 *   les requêtes non authentifiées sur cette route.
 */
#[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeService              $stripeService,
        private readonly UserRepository             $userRepository,
        private readonly CourseRepository           $courseRepository,
        private readonly SubscriptionRepository     $subscriptionRepository,
        private readonly CoursePaymentRepository    $coursePaymentRepository,
        private readonly CourseEnrollmentRepository $enrollmentRepository,
        private readonly EntityManagerInterface     $em,
        private readonly LoggerInterface            $logger,
    ) {}

    /**
     * Point d'entrée unique pour tous les webhooks Stripe.
     *
     * On lit le payload BRUT (php://input) via $request->getContent() car
     * Symfony ne doit pas parser le JSON avant la vérification de signature :
     * tout re-sérialisation (même identique) invaliderait la signature HMAC.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // ── 1. Lecture du payload brut et du header de signature ──────────────
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        if ($sigHeader === '') {
            $this->logger->error('Webhook Stripe reçu sans header Stripe-Signature — requête rejetée.');
            return new JsonResponse(['error' => 'Header Stripe-Signature manquant.'], Response::HTTP_BAD_REQUEST);
        }

        // ── 2. Vérification de la signature (SÉCURITÉ CRITIQUE) ───────────────
        //
        // Si la signature est invalide, on retourne immédiatement un 400.
        // On ne traite RIEN avant cette vérification.
        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader);
        } catch (\UnexpectedValueException $e) {
            // Payload malformé (JSON invalide, etc.)
            $this->logger->error('Webhook Stripe : payload invalide.', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Signature incorrecte → requête frauduleuse ou mauvais secret webhook
            $this->logger->error('Webhook Stripe : signature invalide.', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Signature invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Webhook Stripe reçu et vérifié.', ['type' => $event->type, 'id' => $event->id]);

        // ── 3. Dispatch vers le handler approprié ─────────────────────────────
        //
        // On utilise un switch sur le type d'événement pour maintenir le code lisible.
        // Chaque handler est une méthode privée dédiée pour respecter le principe
        // de responsabilité unique (Single Responsibility Principle).
        try {
            match ($event->type) {
                'checkout.session.completed'       => $this->handleCheckoutSessionCompleted($event),
                'customer.subscription.updated'    => $this->handleSubscriptionUpdated($event),
                'customer.subscription.deleted'    => $this->handleSubscriptionDeleted($event),
                'invoice.payment_failed'           => $this->handleInvoicePaymentFailed($event),
                // Les événements non gérés sont ignorés silencieusement (comportement recommandé par Stripe)
                default                            => $this->logger->debug('Événement Stripe non géré.', ['type' => $event->type]),
            };
        } catch (\Throwable $e) {
            // Erreur inattendue pendant le traitement → log + réponse 500
            // Stripe va retenter l'envoi, ce qui est le comportement souhaité.
            $this->logger->error('Erreur lors du traitement du webhook Stripe.', [
                'type'  => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Erreur interne.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // ── 4. Acquittement de l'événement ────────────────────────────────────
        // Stripe considère le webhook comme traité si on répond avec un 2xx.
        return new JsonResponse(['received' => true], Response::HTTP_OK);
    }

    // ─── Handlers privés ──────────────────────────────────────────────────────

    /**
     * Traite l'événement checkout.session.completed.
     *
     * Cet événement est envoyé par Stripe dès qu'un utilisateur complète
     * une session Checkout (paiement réussi).
     *
     * Il se décline en deux modes :
     *   - mode=subscription → l'utilisateur vient de souscrire un abonnement
     *   - mode=payment      → l'utilisateur vient d'acheter une formation
     *
     * @param StripeEvent $event L'événement Stripe (déjà vérifié)
     */
    private function handleCheckoutSessionCompleted(StripeEvent $event): void
    {
        /** @var \Stripe\Checkout\Session $session */
        $session = $event->data->object;

        $mode = $session->mode;

        $this->logger->info('checkout.session.completed reçu', [
            'session_id' => $session->id,
            'mode'       => $mode,
        ]);

        if ($mode === 'subscription') {
            $this->handleSubscriptionCheckoutCompleted($session);
        } elseif ($mode === 'payment') {
            $this->handleCoursePaymentCheckoutCompleted($session);
        } else {
            $this->logger->warning('Mode de session Checkout inconnu.', ['mode' => $mode]);
        }
    }

    /**
     * Traite la complétion d'une session Checkout en mode "subscription".
     *
     * Crée ou met à jour l'entité Subscription en BDD.
     *
     * Idempotence : si une Subscription avec le même stripeSubscriptionId existe déjà
     * (webhook rejoué), on met à jour l'existante plutôt que d'en créer une nouvelle.
     *
     * @param \Stripe\Checkout\Session $session La session Checkout Stripe (mode=subscription)
     */
    private function handleSubscriptionCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        // Récupération du user_id depuis les metadata de la session
        // Ces metadata ont été définies dans StripeService::createSubscriptionCheckoutSession()
        $userId = $session->metadata['user_id'] ?? null;
        $plan   = $session->metadata['plan'] ?? 'monthly';

        if ($userId === null) {
            $this->logger->error('checkout.session.completed : user_id manquant dans les metadata.', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Chargement de l'utilisateur depuis la BDD
        $user = $this->userRepository->find((int) $userId);
        if ($user === null) {
            $this->logger->error('checkout.session.completed : utilisateur introuvable.', [
                'user_id'    => $userId,
                'session_id' => $session->id,
            ]);
            return;
        }

        // L'ID de l'abonnement Stripe est directement dans la session
        $stripeSubscriptionId = $session->subscription;
        if ($stripeSubscriptionId === null) {
            $this->logger->error('checkout.session.completed : pas de subscription ID dans la session.', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Idempotence : recherche d'un abonnement déjà existant avec cet ID Stripe
        $subscription = $this->subscriptionRepository->findByStripeId((string) $stripeSubscriptionId);

        if ($subscription === null) {
            // Premier traitement : création d'un nouvel enregistrement Subscription
            $subscription = new Subscription();
            $subscription->setUser($user);
            $subscription->setStripeSubscriptionId((string) $stripeSubscriptionId);

            $this->logger->info('Création d\'un nouvel abonnement en BDD.', [
                'user_id'                => $user->getId(),
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
        } else {
            $this->logger->info('Abonnement existant trouvé, mise à jour.', [
                'subscription_id'        => $subscription->getId(),
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
        }

        // Mise à jour des champs (que ce soit une création ou une mise à jour)
        $subscription->setStripeCustomerId((string) $session->customer);
        $subscription->setPlan($plan);
        $subscription->setStatus('active');

        // currentPeriodEnd : la session Checkout ne contient pas cette date.
        // On récupère l'objet Subscription Stripe complet via l'API pour lire la vraie
        // date de fin de période (current_period_end, timestamp Unix).
        // C'est important pour éviter les divergences en cas de coupon, essai gratuit,
        // ou démarrage en milieu de mois — isActive() dépend de cette valeur.
        // Note : le webhook customer.subscription.updated arrivera juste après et
        // confirmera également cette valeur, mais mieux vaut l'avoir dès la création.
        /** @var string $stripeSubscriptionId */
        $stripeSubscriptionId = $session->subscription;
        $stripeSubscription = \Stripe\Subscription::retrieve($stripeSubscriptionId);
        /** @var int $currentPeriodEndTimestamp */
        $currentPeriodEndTimestamp = $stripeSubscription->offsetGet('current_period_end');
        $subscription->setCurrentPeriodEnd(new \DateTime('@' . $currentPeriodEndTimestamp));

        $this->em->persist($subscription);
        $this->em->flush();

        $this->logger->info('Abonnement créé/mis à jour en BDD avec succès.', [
            'subscription_id' => $subscription->getId(),
            'user_id'         => $user->getId(),
            'plan'            => $plan,
        ]);
    }

    /**
     * Traite la complétion d'une session Checkout en mode "payment" (achat de formation).
     *
     * Crée :
     *   1. Un CoursePayment avec status='completed' (preuve d'achat)
     *   2. Un CourseEnrollment (accès à la formation) — si pas déjà inscrit
     *
     * @param \Stripe\Checkout\Session $session La session Checkout Stripe (mode=payment)
     */
    private function handleCoursePaymentCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        // Récupération des identifiants depuis les metadata
        $userId   = $session->metadata['user_id'] ?? null;
        $courseId = $session->metadata['course_id'] ?? null;

        if ($userId === null || $courseId === null) {
            $this->logger->error('checkout.session.completed (payment) : metadata user_id ou course_id manquantes.', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Chargement des entités depuis la BDD
        $user = $this->userRepository->find((int) $userId);
        if ($user === null) {
            $this->logger->error('checkout.session.completed (payment) : utilisateur introuvable.', [
                'user_id' => $userId,
            ]);
            return;
        }

        $course = $this->courseRepository->find((int) $courseId);
        if ($course === null) {
            $this->logger->error('checkout.session.completed (payment) : formation introuvable.', [
                'course_id' => $courseId,
            ]);
            return;
        }

        // Idempotence : vérification si un CoursePayment avec ce PaymentIntent existe déjà
        $paymentIntentId = $session->payment_intent !== null ? (string) $session->payment_intent : null;

        if ($paymentIntentId !== null) {
            $existingPayment = $this->coursePaymentRepository->findByStripePaymentIntentId($paymentIntentId);
            if ($existingPayment !== null) {
                $this->logger->info('CoursePayment déjà existant pour ce PaymentIntent — webhook ignoré.', [
                    'payment_intent_id' => $paymentIntentId,
                ]);
                return;
            }
        }

        // ── Création du CoursePayment ─────────────────────────────────────────
        $payment = new CoursePayment();
        $payment->setUser($user);
        $payment->setCourse($course);
        $payment->setStripePaymentIntentId($paymentIntentId);
        $payment->setStripeCheckoutSessionId($session->id);
        // amount_total est en centimes, comme amountInCents — cohérent
        $payment->setAmountInCents($session->amount_total ?? $course->getPriceInCents() ?? 0);
        $payment->setStatus('completed');

        $this->em->persist($payment);

        $this->logger->info('CoursePayment créé.', [
            'user_id'   => $user->getId(),
            'course_id' => $course->getId(),
            'amount'    => $payment->getFormattedAmount(),
        ]);

        // ── Création du CourseEnrollment si pas déjà inscrit ─────────────────
        //
        // Un utilisateur peut avoir acheté la formation plusieurs fois (remboursement
        // + rachat), on vérifie l'inscription existante avant d'en créer une nouvelle.
        $existingEnrollment = $this->enrollmentRepository->findByUserAndCourse($user, $course);

        if ($existingEnrollment === null) {
            // Pas encore inscrit : on crée l'inscription
            $enrollment = new CourseEnrollment();
            $enrollment->setUser($user);
            $enrollment->setCourse($course);
            // progressPercent démarre à 0 (défaut défini dans l'entité)

            $this->em->persist($enrollment);

            $this->logger->info('CourseEnrollment créé après paiement.', [
                'user_id'   => $user->getId(),
                'course_id' => $course->getId(),
            ]);
        } else {
            // Déjà inscrit (cas d'un achat en double) → on ne recrée pas l'inscription
            $this->logger->info('Utilisateur déjà inscrit à la formation — pas de doublon créé.', [
                'user_id'        => $user->getId(),
                'course_id'      => $course->getId(),
                'enrollment_id'  => $existingEnrollment->getId(),
            ]);
        }

        // Persistance en BDD de tous les nouveaux enregistrements
        $this->em->flush();

        $this->logger->info('Paiement formation traité avec succès.', [
            'user_id'   => $user->getId(),
            'course_id' => $course->getId(),
        ]);
    }

    /**
     * Traite l'événement customer.subscription.updated.
     *
     * Envoyé par Stripe à chaque modification d'un abonnement :
     *   - Renouvellement automatique (nouveau currentPeriodEnd)
     *   - Changement de plan (upgrade/downgrade)
     *   - Modification du statut (active → past_due si paiement échoué, etc.)
     *   - Mise à jour de la date d'annulation programmée (cancel_at_period_end)
     *
     * @param StripeEvent $event L'événement Stripe
     */
    private function handleSubscriptionUpdated(StripeEvent $event): void
    {
        /** @var \Stripe\Subscription $stripeSubscription */
        $stripeSubscription = $event->data->object;

        // Retrouver l'abonnement local par son ID Stripe
        $subscription = $this->subscriptionRepository->findByStripeId($stripeSubscription->id);

        if ($subscription === null) {
            // Peut arriver si l'abonnement a été créé hors de la plateforme
            $this->logger->warning('customer.subscription.updated : abonnement introuvable en BDD.', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
            return;
        }

        // Mise à jour du statut (ex : 'active' → 'past_due' si paiement échoué)
        $subscription->setStatus($stripeSubscription->status);

        // Mise à jour de la date de fin de période courante
        // current_period_end est un timestamp Unix → on le convertit en DateTime.
        // Le SDK Stripe expose les propriétés via __get() magique (StripeObject) ;
        // PHPStan ne peut pas les inférer statiquement — on utilise offsetGet() qui est typé.
        /** @var int $currentPeriodEnd */
        $currentPeriodEnd = $stripeSubscription->offsetGet('current_period_end');
        $subscription->setCurrentPeriodEnd(new \DateTime('@' . $currentPeriodEnd));

        $this->em->flush();

        $this->logger->info('Abonnement mis à jour depuis Stripe.', [
            'subscription_id' => $subscription->getId(),
            'new_status'      => $stripeSubscription->status,
        ]);
    }

    /**
     * Traite l'événement customer.subscription.deleted.
     *
     * Envoyé par Stripe lorsqu'un abonnement est définitivement annulé
     * (soit immédiatement, soit en fin de période si cancel_at_period_end=true).
     *
     * On passe le statut à 'canceled' et on renseigne canceledAt.
     * L'historique est conservé en BDD (pas de suppression physique).
     *
     * @param StripeEvent $event L'événement Stripe
     */
    private function handleSubscriptionDeleted(StripeEvent $event): void
    {
        /** @var \Stripe\Subscription $stripeSubscription */
        $stripeSubscription = $event->data->object;

        $subscription = $this->subscriptionRepository->findByStripeId($stripeSubscription->id);

        if ($subscription === null) {
            $this->logger->warning('customer.subscription.deleted : abonnement introuvable en BDD.', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
            return;
        }

        // Passage au statut 'canceled'
        $subscription->setStatus('canceled');

        // Renseignement de la date d'annulation
        // canceled_at peut être null si Stripe ne l'a pas renseigné — on utilise now() par défaut
        $canceledAt = $stripeSubscription->canceled_at !== null
            ? new \DateTime('@' . $stripeSubscription->canceled_at)
            : new \DateTime();
        $subscription->setCanceledAt($canceledAt);

        $this->em->flush();

        $this->logger->info('Abonnement annulé.', [
            'subscription_id' => $subscription->getId(),
            'canceled_at'     => $canceledAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Traite l'événement invoice.payment_failed.
     *
     * Envoyé par Stripe lorsqu'une tentative de prélèvement automatique échoue
     * (carte expirée, fonds insuffisants, etc.).
     *
     * En V1 : on loge simplement l'erreur pour alerter l'équipe Bazaart.
     * En V2 : envoyer un email à l'utilisateur pour lui demander de mettre à jour
     * ses informations de paiement.
     *
     * Note : Stripe tente automatiquement plusieurs fois le prélèvement (3 à 4 fois
     * sur plusieurs jours) avant de passer le statut à 'canceled'. Le statut
     * customer.subscription.updated avec status='past_due' arrive avant ce webhook.
     *
     * @param StripeEvent $event L'événement Stripe
     */
    private function handleInvoicePaymentFailed(StripeEvent $event): void
    {
        /** @var \Stripe\Invoice $invoice */
        $invoice = $event->data->object;

        // Les propriétés de StripeObject sont accessibles via __get() magique ;
        // offsetGet() est l'accès typé recommandé pour satisfaire PHPStan.
        $this->logger->error('Paiement Stripe échoué (invoice.payment_failed).', [
            'invoice_id'      => $invoice->id,
            'customer_id'     => $invoice->customer,
            'amount_due'      => $invoice->amount_due,
            // @phpstan-ignore-next-line (subscription est une propriété dynamique de Invoice)
            'subscription_id' => $invoice->subscription,
        ]);

        // TODO V2 : envoyer un email à l'utilisateur pour l'informer de l'échec
        // et lui donner un lien vers le portail client Stripe pour mettre à jour
        // ses informations de paiement.
    }
}

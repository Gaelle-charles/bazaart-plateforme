<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SubscriptionRepository;
use App\Service\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SubscriptionController — Gestion des abonnements récurrents Bazaart.
 *
 * Ce controller gère le parcours d'abonnement côté utilisateur :
 *   - Affichage de la page tarifaire publique (/tarifs)
 *   - Initiation du paiement via Stripe Checkout (/subscribe/{plan})
 *   - Pages de retour après paiement (succès / annulation)
 *   - Dashboard de l'abonnement actif (/subscription/manage)
 *
 * Les deux plans disponibles en V1 :
 *   - monthly : 9,90€/mois
 *   - annual  : 79€/an (soit 6,58€/mois)
 *
 * Ce controller ne touche PAS à la BDD directement : c'est le webhook Stripe
 * (StripeWebhookController) qui crée et met à jour les Subscription en BDD.
 * Ce controller se contente d'initier les sessions Checkout et d'afficher l'état.
 */
class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly StripeService         $stripeService,
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {}

    // ─── Page tarifaire publique ──────────────────────────────────────────────

    /**
     * GET /tarifs — Page de présentation des tarifs et des avantages de l'abonnement.
     *
     * Route PUBLIQUE : accessible sans authentification pour permettre aux visiteurs
     * de découvrir l'offre avant de s'inscrire.
     *
     * On passe à la vue :
     *   - hasActiveSubscription : bool — si vrai, on cache les boutons d'abonnement
     *   - activeSubscription    : Subscription|null — pour afficher le plan actif
     *   - monthlyPrice          : 9.90 — prix mensuel en euros (pour l'affichage)
     *   - annualPrice           : 79.00 — prix annuel en euros
     *   - annualMonthlyEquivalent : 6.58 — prix annuel ramené au mois (argument de vente)
     */
    #[Route('/tarifs', name: 'app_pricing', methods: ['GET'])]
    public function pricing(): Response
    {
        // On vérifie l'abonnement actif seulement si l'utilisateur est connecté
        $activeSubscription    = null;
        $hasActiveSubscription = false;

        if ($this->getUser() !== null) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $activeSubscription = $this->subscriptionRepository->findActiveByUser($user);
            $hasActiveSubscription = $activeSubscription !== null;
        }

        return $this->render('subscription/pricing.html.twig', [
            // État de l'abonnement de l'utilisateur connecté (null si non connecté)
            'hasActiveSubscription'    => $hasActiveSubscription,
            'activeSubscription'       => $activeSubscription,

            // Prix en euros (float) pour l'affichage dans le template Twig
            // Ces valeurs sont définitives en V1 — en V2, lire depuis la BDD ou le .env
            'monthlyPrice'             => 9.90,
            'annualPrice'              => 79.00,
            // 79€ / 12 mois = 6,5833... → arrondi à 6,58€ pour l'affichage
            'annualMonthlyEquivalent'  => 6.58,
        ]);
    }

    // ─── Initiation du paiement ───────────────────────────────────────────────

    /**
     * POST /subscribe/{plan} — Lance le processus d'abonnement via Stripe Checkout.
     *
     * Route réservée aux utilisateurs connectés (ROLE_USER).
     * Le paramètre {plan} doit valoir 'monthly' ou 'annual' (validé par le requirements).
     *
     * Flow :
     *   1. Vérification : l'utilisateur n'a pas déjà un abonnement actif
     *   2. Création d'une session Checkout Stripe
     *   3. Redirection vers l'URL Stripe (page de paiement hébergée sur stripe.com)
     *
     * Sécurité CSRF : on utilise un token CSRF via le formulaire HTML (hidden input _token).
     * Le formulaire POST est généré côté Twig (bouton dans pricing.html.twig).
     *
     * @param string $plan 'monthly' ou 'annual' (contrainte de route)
     */
    #[Route('/subscribe/{plan}', name: 'app_subscribe', requirements: ['plan' => 'monthly|annual'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function subscribe(string $plan, Request $request): Response
    {
        // ── Validation CSRF ───────────────────────────────────────────────────
        // Le token est généré dans pricing.html.twig : {{ csrf_token('subscribe_' ~ plan) }}
        if (!$this->isCsrfTokenValid('subscribe_' . $plan, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_pricing');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // ── Vérification : pas de doublon d'abonnement actif ─────────────────
        // Si l'utilisateur a déjà un abonnement actif, on le redirige vers la
        // page de gestion au lieu de lui permettre d'en créer un second.
        $existingSubscription = $this->subscriptionRepository->findActiveByUser($user);
        if ($existingSubscription !== null) {
            $this->addFlash('info', 'Vous avez déjà un abonnement actif. Gérez-le depuis votre espace membre.');
            return $this->redirectToRoute('app_subscription_manage');
        }

        // ── Création de la session Checkout Stripe ────────────────────────────
        try {
            // Les URLs de retour sont des URLs absolues que Stripe doit pouvoir atteindre
            // generateUrl() avec ReferenceType::ABSOLUTE_URL génère une URL complète avec le domaine
            $successUrl = $this->generateUrl('app_subscription_success', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
            $cancelUrl  = $this->generateUrl('app_subscription_cancel', [],  \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

            $checkoutUrl = $this->stripeService->createSubscriptionCheckoutSession(
                user:       $user,
                plan:       $plan,
                successUrl: $successUrl,
                cancelUrl:  $cancelUrl,
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Erreur API Stripe (réseau, clé invalide, etc.) → message d'erreur utilisateur
            $this->addFlash('error', 'Une erreur est survenue lors de la connexion à notre système de paiement. Veuillez réessayer dans quelques instants.');
            return $this->redirectToRoute('app_pricing');
        }

        // ── Redirection vers la page Stripe ──────────────────────────────────
        // RedirectResponse avec le code HTTP 303 (See Other) est le standard
        // pour les redirections après POST (évite la resoumission du formulaire).
        return new RedirectResponse($checkoutUrl, Response::HTTP_SEE_OTHER);
    }

    // ─── Pages de retour après paiement ──────────────────────────────────────

    /**
     * GET /subscription/success — Page de confirmation après un abonnement réussi.
     *
     * L'utilisateur est redirigé ici par Stripe après un paiement réussi.
     * Le paramètre ?session_id= est présent dans l'URL mais on ne l'utilise pas en V1.
     *
     * IMPORTANT : cette page ne confirme PAS l'abonnement — c'est le webhook
     * checkout.session.completed qui crée l'abonnement en BDD. Il peut y avoir
     * un délai de quelques secondes entre l'arrivée sur cette page et la création
     * effective de l'abonnement (webhook asynchrone).
     *
     * On affiche donc un message d'attente et on invite l'utilisateur à rafraîchir.
     */
    #[Route('/subscription/success', name: 'app_subscription_success', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function success(): Response
    {
        return $this->render('subscription/success.html.twig');
    }

    /**
     * GET /subscription/cancel — Page affichée si l'utilisateur annule sur la page Stripe.
     *
     * L'utilisateur est redirigé ici s'il clique "Retour" ou ferme la page Stripe
     * sans compléter le paiement. Aucun abonnement n'a été créé.
     *
     * On affiche un message rassurant et un lien pour revenir à la page des tarifs.
     */
    #[Route('/subscription/cancel', name: 'app_subscription_cancel', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(): Response
    {
        return $this->render('subscription/cancel.html.twig');
    }

    // ─── Gestion de l'abonnement actif ───────────────────────────────────────

    /**
     * GET /subscription/manage — Page de gestion de l'abonnement actif.
     *
     * Affiche :
     *   - Le plan actif (mensuel ou annuel)
     *   - La date de fin de la période en cours (prochain renouvellement)
     *   - Le statut de l'abonnement
     *   - Un lien pour annuler (en V2 : portail client Stripe)
     *
     * Si l'utilisateur n'a pas d'abonnement actif, on le redirige vers /tarifs.
     */
    #[Route('/subscription/manage', name: 'app_subscription_manage', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function manage(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $activeSubscription = $this->subscriptionRepository->findActiveByUser($user);

        // Redirection vers la page des tarifs si pas d'abonnement actif
        if ($activeSubscription === null) {
            $this->addFlash('info', 'Vous n\'avez pas d\'abonnement actif. Découvrez nos offres ci-dessous.');
            return $this->redirectToRoute('app_pricing');
        }

        return $this->render('subscription/manage.html.twig', [
            'activeSubscription' => $activeSubscription,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Course;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event as StripeEvent;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * StripeService — Point d'entrée unique pour toutes les interactions avec l'API Stripe.
 *
 * Ce service est le seul endroit du projet qui appelle le SDK stripe/stripe-php.
 * Regrouper tout ici facilite les tests (un seul service à mocker) et garantit
 * qu'on n'oublie jamais d'initialiser la clé API.
 *
 * Responsabilités :
 *   - Initialiser le SDK Stripe avec la clé secrète
 *   - Créer des sessions Checkout pour les abonnements et les formations
 *   - Créer des produits/prix Stripe pour les nouvelles formations
 *   - Vérifier la signature des webhooks entrants
 *   - Retrouver ou créer un Customer Stripe pour un utilisateur
 *
 * Ce que ce service NE fait PAS :
 *   - Écrire en BDD → responsabilité du contrôleur webhook ou des autres services
 *   - Gérer la logique métier (inscriptions, accès) → autres services
 *
 * Utilisation de l'API statique Stripe (Stripe::setApiKey) plutôt que StripeClient
 * instancié car c'est l'approche la plus simple et la plus documentée pour une
 * application Symfony avec un seul compte Stripe.
 */
class StripeService
{
    /**
     * Constructeur avec injection des variables d'environnement Stripe.
     *
     * Symfony lit ces valeurs depuis .env.local via les paramètres du container.
     * On utilise #[Autowire] en V2 — pour l'instant, on utilise le binding classique
     * via services.yaml ou l'autowiring par type + nom de paramètre.
     *
     * @param string          $stripeSecretKey    Clé secrète Stripe (sk_live_xxx ou sk_test_xxx)
     * @param string          $stripeWebhookSecret Secret de vérification des webhooks (whsec_xxx)
     * @param string          $stripePriceMonthly  ID du Price Stripe pour l'abonnement mensuel
     * @param string          $stripePriceAnnual   ID du Price Stripe pour l'abonnement annuel
     * @param LoggerInterface $logger              Logger Symfony pour tracer les appels Stripe
     */
    public function __construct(
        private readonly string          $stripeSecretKey,
        private readonly string          $stripeWebhookSecret,
        private readonly string          $stripePriceMonthly,
        private readonly string          $stripePriceAnnual,
        private readonly LoggerInterface $logger,
    ) {
        // Initialisation du SDK Stripe avec la clé secrète.
        // Cette ligne est appelée une seule fois à l'instanciation du service
        // (Symfony instancie les services en singleton par défaut).
        Stripe::setApiKey($this->stripeSecretKey);
    }

    // ─── Sessions Checkout ────────────────────────────────────────────────────

    /**
     * Crée une session Checkout Stripe pour souscrire un abonnement.
     *
     * Flow complet :
     *   1. L'utilisateur clique "S'abonner" → POST /subscribe/{plan}
     *   2. Ce service crée une Session Checkout → retourne l'URL Stripe
     *   3. Le controller redirige l'utilisateur vers l'URL Stripe
     *   4. L'utilisateur paye sur stripe.com → Stripe envoie le webhook
     *   5. StripeWebhookController reçoit checkout.session.completed → crée la Subscription en BDD
     *
     * Les metadata sont cruciales : elles permettent au webhook de retrouver
     * l'utilisateur (user_id) et le plan (plan) sans stocker d'état en session.
     *
     * @param User   $user       L'utilisateur qui souscrit l'abonnement
     * @param string $plan       'monthly' ou 'annual'
     * @param string $successUrl URL de retour après paiement réussi (avec {CHECKOUT_SESSION_ID})
     * @param string $cancelUrl  URL de retour si l'utilisateur clique "Annuler" sur Stripe
     *
     * @return string URL de la page Checkout Stripe (ex: https://checkout.stripe.com/pay/cs_xxx)
     *
     * @throws ApiErrorException Si l'API Stripe retourne une erreur (clé invalide, réseau, etc.)
     */
    public function createSubscriptionCheckoutSession(
        User $user,
        string $plan,
        string $successUrl,
        string $cancelUrl,
    ): string {
        // Validation du plan (sécurité défensive — ne devrait pas arriver avec les routes)
        if (!in_array($plan, ['monthly', 'annual'], true)) {
            throw new \InvalidArgumentException(sprintf('Plan invalide : "%s". Valeurs acceptées : monthly, annual.', $plan));
        }

        // Sélection du Price Stripe selon le plan choisi
        $priceId = $plan === 'monthly' ? $this->stripePriceMonthly : $this->stripePriceAnnual;

        $this->logger->info('Création session Checkout abonnement', [
            'user_id' => $user->getId(),
            'plan'    => $plan,
            'price_id' => $priceId,
        ]);

        // Création de la session Checkout Stripe
        // Stripe redirige l'utilisateur vers cette URL de paiement hébergée sur stripe.com
        $session = CheckoutSession::create([
            // mode=subscription = paiement récurrent (≠ payment = paiement unique)
            'mode'          => 'subscription',

            // On pré-remplit l'email pour éviter à l'utilisateur de le saisir
            'customer_email' => $user->getEmail(),

            // Les produits à acheter (ici un seul : le plan d'abonnement)
            'line_items' => [
                [
                    'price'    => $priceId,
                    'quantity' => 1,
                ],
            ],

            // URL de retour après paiement réussi
            // {CHECKOUT_SESSION_ID} est remplacé par Stripe par l'ID de la session
            // → utilisé pour identifier le paiement en cas de vérification côté serveur
            'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',

            // URL de retour si l'utilisateur annule le paiement
            'cancel_url' => $cancelUrl,

            // Metadata de la session : transmises à l'événement webhook
            // checkout.session.completed pour retrouver l'utilisateur et le plan
            'metadata' => [
                'user_id' => (string) $user->getId(),
                'plan'    => $plan,
            ],

            // Metadata de l'abonnement : transmises aussi dans les événements
            // customer.subscription.* pour les webhooks de mise à jour/annulation
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $user->getId(),
                    'plan'    => $plan,
                ],
            ],

            // Activation des codes promotionnels sur la page Checkout Stripe.
            // Quand ce paramètre est à true, un champ "Code promo" apparaît
            // automatiquement sur la page de paiement hébergée par Stripe.
            // Les codes se créent dans le dashboard Stripe :
            //   Billing → Coupons → Codes de promotion (ex: LAUNCH20)
            // IMPORTANT : activé uniquement sur les abonnements (pas sur les formations).
            'allow_promotion_codes' => true,

            // Case à cocher "Accepter les CGU/CGV" — conformité DGCCRF
            //
            // Affiche une case à cocher obligatoire sur la page Checkout Stripe :
            //   "J'accepte les conditions d'utilisation"
            // Le lien pointe vers l'URL configurée dans le dashboard Stripe :
            //   Paramètres → Checkout → "URL des conditions d'utilisation"
            //   → à régler sur https://app.bazaart.fr/cgu
            //
            // Fondement légal : Directive 2011/83/UE (art. L.221-28 12° CdC) —
            // pour les services numériques à exécution immédiate, l'utilisateur
            // doit consentir EXPLICITEMENT à la renonciation de son droit de
            // rétractation de 14 jours. Cette case matérialise ce consentement exprès.
            'consent_collection' => [
                'terms_of_service' => 'required',
            ],
        ]);

        $this->logger->info('Session Checkout abonnement créée', [
            'session_id' => $session->id,
            'user_id'    => $user->getId(),
        ]);

        // L'URL est directement utilisable pour une redirection HTTP 302
        return $session->url;
    }

    /**
     * Crée une session Checkout Stripe pour l'achat d'une formation.
     *
     * Flow complet :
     *   1. L'utilisateur clique "Acheter cette formation"
     *   2. Ce service crée une Session Checkout → retourne l'URL Stripe
     *   3. Le controller redirige vers Stripe
     *   4. Stripe envoie le webhook checkout.session.completed (mode=payment)
     *   5. StripeWebhookController crée CoursePayment + CourseEnrollment en BDD
     *
     * Prérequis : la formation doit avoir un stripePriceId défini.
     * Si ce n'est pas le cas, on tente de créer le produit Stripe via
     * createOrUpdateCourseProduct() — mais normalement l'admin l'a fait avant.
     *
     * @param User   $user       L'utilisateur qui achète la formation
     * @param Course $course     La formation achetée
     * @param string $successUrl URL de retour après paiement réussi
     * @param string $cancelUrl  URL de retour si l'utilisateur annule
     *
     * @return string URL de la page Checkout Stripe
     *
     * @throws \RuntimeException Si la formation n'a pas de prix défini
     * @throws ApiErrorException Si l'API Stripe retourne une erreur
     */
    public function createCourseCheckoutSession(
        User $user,
        Course $course,
        string $successUrl,
        string $cancelUrl,
    ): string {
        // Vérification que la formation a bien un prix Stripe défini
        if ($course->getStripePriceId() === null) {
            // Tentative de création du produit Stripe si le prix en centimes est défini
            // (cas où l'admin a défini le prix mais oublié de créer le produit Stripe)
            if ($course->getPriceInCents() !== null && $course->getPriceInCents() > 0) {
                $this->createOrUpdateCourseProduct($course);
                // Attention : createOrUpdateCourseProduct() ne fait pas flush()
                // — l'appelant (le controller) doit le faire si nécessaire
            }

            // Si toujours pas de Price ID après la tentative, on lève une exception
            if ($course->getStripePriceId() === null) {
                throw new \RuntimeException(sprintf(
                    'La formation "%s" (id: %d) n\'a pas de prix Stripe configuré. '
                    . 'Configurez le prix dans l\'administration avant de permettre l\'achat.',
                    $course->getTitle(),
                    (int) $course->getId(),
                ));
            }
        }

        $this->logger->info('Création session Checkout formation', [
            'user_id'   => $user->getId(),
            'course_id' => $course->getId(),
            'price_id'  => $course->getStripePriceId(),
        ]);

        $session = CheckoutSession::create([
            // mode=payment = paiement unique (≠ subscription = paiement récurrent)
            'mode'          => 'payment',
            'customer_email' => $user->getEmail(),

            'line_items' => [
                [
                    'price'    => $course->getStripePriceId(),
                    'quantity' => 1,
                ],
            ],

            'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $cancelUrl,

            // Metadata transmises au webhook ET aux pages de retour (succès/annulation)
            // pour retrouver l'utilisateur, la formation, et afficher le bon titre.
            'metadata' => [
                'user_id'    => (string) $user->getId(),
                'course_id'  => (string) $course->getId(),
                'course_slug' => $course->getSlug(), // utilisé par paymentSuccess/paymentCancel
            ],

            // Case à cocher "Accepter les CGU/CGV" — même obligation légale que pour
            // les abonnements : la formation est un contenu numérique à exécution
            // immédiate, soumis à l'art. L.221-28 12° du Code de la consommation.
            // L'URL des CGU doit être configurée dans Stripe :
            //   Paramètres → Checkout → "URL des conditions d'utilisation"
            //   → https://app.bazaart.fr/cgu
            'consent_collection' => [
                'terms_of_service' => 'required',
            ],
        ]);

        $this->logger->info('Session Checkout formation créée', [
            'session_id' => $session->id,
            'user_id'    => $user->getId(),
            'course_id'  => $course->getId(),
        ]);

        return $session->url;
    }

    // ─── Gestion des produits et prix Stripe ──────────────────────────────────

    /**
     * Crée ou met à jour le Product + Price Stripe pour une formation.
     *
     * Appelé par l'admin lorsqu'il définit ou modifie le prix d'une formation.
     *
     * Logique :
     *   - Si la formation n'a pas encore de stripeProductId → crée un nouveau Product
     *   - Toujours crée un nouveau Price (les Price Stripe sont immuables une fois créés)
     *   - Met à jour les champs stripeProductId et stripePriceId sur l'entité Course
     *
     * IMPORTANT : cette méthode ne fait PAS de flush() en base.
     * L'appelant (AdminCourseController) est responsable du flush()
     * pour rester cohérent avec le pattern "thin controller / service sans effet de bord".
     *
     * @param Course $course La formation à configurer côté Stripe
     *
     * @throws \InvalidArgumentException Si le prix n'est pas défini sur la formation
     * @throws ApiErrorException         Si l'API Stripe retourne une erreur
     */
    public function createOrUpdateCourseProduct(Course $course): void
    {
        // Validation : le prix doit être défini avant de créer un produit Stripe
        if ($course->getPriceInCents() === null || $course->getPriceInCents() <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'La formation "%s" n\'a pas de prix défini (priceInCents est null ou 0). '
                . 'Définissez le prix avant de créer le produit Stripe.',
                $course->getTitle(),
            ));
        }

        // ── Création ou récupération du Product Stripe ──────────────────────
        if ($course->getStripeProductId() === null) {
            // Première configuration : on crée un nouveau Product Stripe
            $this->logger->info('Création d\'un nouveau Product Stripe pour la formation', [
                'course_id'    => $course->getId(),
                'course_title' => $course->getTitle(),
            ]);

            $product = Product::create([
                // Le nom du produit affiché sur la facture Stripe et dans le dashboard Stripe
                'name'        => $course->getTitle(),
                // Description optionnelle (affichée sur la facture)
                'description' => $course->getSubtitle() ?? $course->getTitle(),
                // Metadata pour retrouver facilement la formation depuis le dashboard Stripe
                'metadata'    => [
                    'course_id'    => (string) $course->getId(),
                    'course_slug'  => $course->getSlug(),
                ],
            ]);

            // Mise à jour de l'entité avec l'ID du Product Stripe créé
            $course->setStripeProductId($product->id);

            $this->logger->info('Product Stripe créé', ['product_id' => $product->id]);
        } else {
            // Le Product existe déjà : on le met à jour si le titre a changé
            // (par exemple si l'admin a renommé la formation)
            $this->logger->info('Mise à jour du Product Stripe existant', [
                'product_id'   => $course->getStripeProductId(),
                'course_title' => $course->getTitle(),
            ]);

            Product::update($course->getStripeProductId(), [
                'name'        => $course->getTitle(),
                'description' => $course->getSubtitle() ?? $course->getTitle(),
            ]);
        }

        // ── Création d'un nouveau Price Stripe ──────────────────────────────
        // Un Price Stripe est IMMUABLE une fois créé : on ne peut pas modifier
        // le montant ou la devise. Si le prix change, on crée un nouveau Price.
        // L'ancien Price est archivé (active=false) mais jamais supprimé.
        $this->logger->info('Création d\'un nouveau Price Stripe', [
            'product_id'     => $course->getStripeProductId(),
            'amount_in_cents' => $course->getPriceInCents(),
        ]);

        $price = Price::create([
            // Le produit auquel ce prix est rattaché
            'product'     => $course->getStripeProductId(),
            // Le montant en centimes (Stripe travaille toujours en entiers)
            'unit_amount' => $course->getPriceInCents(),
            // La devise (EUR pour Bazaart)
            'currency'    => 'eur',
        ]);

        // Mise à jour de l'entité avec le nouvel ID de Price Stripe
        $course->setStripePriceId($price->id);

        $this->logger->info('Price Stripe créé', [
            'price_id'   => $price->id,
            'amount'     => $course->getFormattedPrice(),
        ]);
    }

    // ─── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Vérifie la signature du webhook Stripe et retourne l'événement parsé.
     *
     * SÉCURITÉ CRITIQUE : cette vérification doit être faite AVANT tout traitement.
     * Elle garantit que la requête vient bien de Stripe et non d'un attaquant
     * qui enverrait de faux événements (ex : faux paiement réussi).
     *
     * Mécanisme :
     *   Stripe calcule un HMAC-SHA256 du payload avec le secret webhook.
     *   On recalcule ce hash et on le compare — si ça ne correspond pas,
     *   Webhook::constructEvent() lève une \UnexpectedValueException.
     *
     * IMPORTANT : le payload doit être lu BRUT (php://input), pas parsé en JSON.
     * Symfony ne doit PAS decoder le body avant qu'on vérifie la signature.
     * Le controller utilise $request->getContent() pour obtenir le payload brut.
     *
     * @param string $payload   Corps brut de la requête HTTP (json)
     * @param string $sigHeader Valeur du header HTTP "Stripe-Signature"
     *
     * @return StripeEvent L'événement Stripe parsé et vérifié
     *
     * @throws \UnexpectedValueException Si le payload est malformé
     * @throws \Stripe\Exception\SignatureVerificationException Si la signature est invalide
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): StripeEvent
    {
        // Webhook::constructEvent() fait la vérification HMAC et le parsing JSON
        // Lève \UnexpectedValueException si le payload est invalide
        // Lève SignatureVerificationException si la signature ne correspond pas
        return Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret);
    }

}

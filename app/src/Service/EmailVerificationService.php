<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * EmailVerificationService — Logique métier de la vérification d'adresse email.
 *
 * Ce service est le pendant de PasswordResetService pour la vérification d'email.
 * Il encapsule deux responsabilités :
 *
 *   1. sendVerificationEmail() — Génère une URL signée et envoie l'email de confirmation
 *   2. handleVerification()    — Valide la signature lors du clic sur le lien
 *
 * ─── Comment fonctionne symfonycasts/verify-email-bundle ? ─────────────────────
 *
 * Le bundle VerifyEmailHelper génère une URL "signée" avec :
 *   - L'id de l'utilisateur (userId)
 *   - L'email de l'utilisateur (pour détecter un changement d'email entre l'envoi et la vérification)
 *   - Une date d'expiration (configurable)
 *   - Une signature HMAC (basée sur APP_SECRET) qui garantit que l'URL n'a pas été falsifiée
 *
 * Aucun token n'est stocké en base de données : toute l'information est dans l'URL elle-même.
 * Avantage : simplicité, pas de colonne supplémentaire, pas de nettoyage à faire.
 *
 * ─── Expiration ────────────────────────────────────────────────────────────────
 *
 * La durée de validité est configurable dans config/packages/verify_email.yaml.
 * Nous la laissons à la valeur par défaut du bundle (1 heure) pour rester cohérent
 * avec PasswordResetService (aussi 1 heure).
 *
 * ─── Sécurité ──────────────────────────────────────────────────────────────────
 *
 * - L'URL signée devient invalide si l'email de l'utilisateur change.
 * - La signature utilise APP_SECRET : impossible à forger sans accès au serveur.
 * - Un lien déjà utilisé (isVerified=true) est ignoré gracieusement.
 */
class EmailVerificationService
{
    /** Adresse expéditrice — cohérente avec PasswordResetService et les autres services email */
    private const FROM_EMAIL = 'noreply@bazaart.fr';
    private const FROM_NAME  = 'Bazaart';

    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface            $mailer,
        private readonly EntityManagerInterface     $em,
        private readonly LoggerInterface            $logger,
    ) {}

    // ─── Méthode 1 : Envoi de l'email de confirmation ────────────────────────

    /**
     * Génère une URL signée et envoie l'email de confirmation à l'utilisateur.
     *
     * L'URL signée est générée par VerifyEmailHelper et pointe vers la route
     * app_verify_email. Elle contient une signature HMAC qui expire après 1 heure.
     *
     * En cas d'échec SMTP, l'erreur est loguée sans remonter d'exception
     * (même comportement que PasswordResetService::sendResetEmail()).
     *
     * @param User $user L'utilisateur qui vient de s'inscrire
     */
    public function sendVerificationEmail(User $user): void
    {
        // ── Étape 1 : Générer la signature ───────────────────────────────────
        //
        // generateSignature() prend 3 paramètres :
        //   - Le nom de la route de vérification (doit correspondre à une route existante)
        //   - L'id de l'utilisateur (inclus dans la signature pour l'identifier)
        //   - Son email (inclus pour invalider le lien si l'email change)
        //
        // Le résultat est un objet SignatureComponents qui contient l'URL signée
        // avec les paramètres d'expiration et la signature HMAC ajoutés en query string.
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            $user->getEmail(),
            // 4e paramètre : paramètres ajoutés EN CLAIR dans l'URL (et inclus dans la signature).
            // Indispensable ici : au moment du clic, l'utilisateur n'est PAS connecté, donc le
            // contrôleur a besoin de l'id présent dans l'URL (?id=...) pour charger le bon User
            // AVANT de valider la signature. Sans ce paramètre, l'URL n'avait pas de ?id= et la
            // vérification échouait systématiquement (redirection vers /register).
            ['id' => (string) $user->getId()],
        );

        // L'URL complète avec signature, valide pour 1 heure
        $verifyUrl = $signatureComponents->getSignedUrl();

        // ── Étape 2 : Envoyer l'email ─────────────────────────────────────────
        try {
            $email = (new TemplatedEmail())
                // Expéditeur avec nom d'affichage — cohérent avec les autres emails du projet
                ->from(new Address(self::FROM_EMAIL, self::FROM_NAME))
                ->to($user->getEmail())
                ->subject('[Bazaart] Confirme ton adresse email')
                // Template HTML principal (tables + CSS inline pour compatibilité email)
                ->htmlTemplate('emails/verify_email.html.twig')
                // Fallback texte brut pour clients qui bloquent le HTML
                ->textTemplate('emails/verify_email.txt.twig')
                // Variables injectées dans le template Twig
                ->context([
                    'user'         => $user,
                    'verifyUrl'    => $verifyUrl,
                    // Durée lisible pour l'utilisateur — cohérent avec le token d'expiration du bundle
                    'expiresIn'    => '1 heure',
                ]);

            $this->mailer->send($email);

            $this->logger->info(
                sprintf('Email de confirmation envoyé à %s', $user->getEmail()),
                ['user_id' => $user->getId()]
            );

        } catch (\Throwable $e) {
            // On attrape toute erreur (SMTP, rendu Twig...) pour ne pas bloquer
            // l'utilisateur. L'erreur est loguée pour le diagnostic.
            // L'utilisateur peut demander un renvoi via la page dédiée.
            $this->logger->error(
                sprintf('Échec envoi email de confirmation à %s : %s', $user->getEmail(), $e->getMessage()),
                [
                    'user_id'   => $user->getId(),
                    'exception' => $e,
                ]
            );
        }
    }

    // ─── Méthode 2 : Validation du lien de confirmation ──────────────────────

    /**
     * Valide la signature contenue dans la requête de vérification.
     *
     * Cette méthode est appelée quand l'utilisateur clique sur le lien reçu par email.
     * Elle vérifie :
     *   1. Que la signature HMAC est valide (non falsifiée)
     *   2. Que le lien n'a pas expiré
     *   3. Que l'email dans le lien correspond toujours à l'email de l'utilisateur
     *
     * Si tout est OK : marque l'utilisateur comme vérifié et persiste.
     * Sinon : lève une VerifyEmailExceptionInterface que le contrôleur doit attraper.
     *
     * @param Request $request La requête HTTP contenant l'URL signée (query string)
     * @param User    $user    L'utilisateur identifié par l'id dans l'URL
     *
     * @throws VerifyEmailExceptionInterface si la signature est invalide ou expirée
     */
    public function handleVerification(Request $request, User $user): void
    {
        // validateEmailConfirmation() vérifie la signature HMAC et l'expiration.
        // En cas de problème, elle lève une exception spécifique au bundle :
        //   - ExpiredSignatureException     → lien expiré
        //   - InvalidSignatureException     → URL falsifiée
        //   - WrongEmailVerifyException     → l'email a changé depuis l'envoi
        //
        // Ces exceptions implémentent toutes VerifyEmailExceptionInterface,
        // ce qui permet au contrôleur de les attraper en un seul catch.
        // validateEmailConfirmationFromRequest() remplace validateEmailConfirmation()
        // (dépréciée depuis verify-email-bundle v1.17). On lui passe directement la Request :
        // le bundle en extrait l'URI signée. Mêmes contrôles (signature HMAC, expiration, email).
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),     // L'id attendu (doit correspondre à celui dans l'URL)
            $user->getEmail(),           // L'email attendu (doit correspondre à celui dans l'URL)
        );

        // ── La signature est valide : marquer l'utilisateur comme vérifié ────
        $user->setIsVerified(true);

        // flush() sans persist() : l'entité est déjà gérée par Doctrine
        // (elle a été chargée depuis la BDD par le repository).
        $this->em->flush();

        $this->logger->info(
            sprintf('Email confirmé pour l\'utilisateur %s', $user->getEmail()),
            ['user_id' => $user->getId()]
        );
    }
}

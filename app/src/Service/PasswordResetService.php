<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * PasswordResetService — Logique métier de la réinitialisation de mot de passe.
 *
 * Ce service est le cœur du parcours "mot de passe oublié". Il encapsule trois
 * responsabilités distinctes :
 *
 *   1. requestReset()    — Génère un token, le hache, l'enregistre, envoie l'email
 *   2. validateToken()   — Vérifie qu'un token est valide et non expiré
 *   3. resetPassword()   — Applique le nouveau mot de passe et invalide le token
 *
 * ─── Sécurité ────────────────────────────────────────────────────────────────
 * - Le token est généré via bin2hex(random_bytes(32)) → 64 chars hexa, entropie 256 bits.
 * - Le token en clair est envoyé UNIQUEMENT par email (jamais stocké en BDD).
 * - En BDD : hash('sha256', $token) — 64 chars hexa.
 * - Anti-énumération : requestReset() retourne void et ne lève pas d'exception
 *   si l'email n'existe pas. Le contrôleur affiche le même message dans tous les cas.
 * - Expiration : 1 heure après la demande.
 * - Usage unique : le token est invalidé (remis à null) après un reset réussi.
 * - Comptes anonymisés (RGPD) : exclus du reset (isAnonymized() vérifié avant envoi).
 *
 * ─── Dépendances ─────────────────────────────────────────────────────────────
 * - UserRepository          : retrouver l'utilisateur par email ou par token hash
 * - EntityManagerInterface  : persister les modifications du token
 * - UserPasswordHasherInterface : hacher le nouveau mot de passe
 * - MailerInterface         : envoyer l'email de réinitialisation
 * - UrlGeneratorInterface   : générer l'URL absolue du lien de réinitialisation
 * - LoggerInterface         : tracer les erreurs d'envoi email (sans bloquer)
 */
class PasswordResetService
{
    // ─── Constantes de configuration ─────────────────────────────────────────

    /** Durée de validité du token en secondes (1 heure = 3600 secondes) */
    private const TOKEN_TTL_SECONDS = 3600;

    /** Adresse expéditrice — doit être authentifiée dans Brevo/Resend en production */
    private const FROM_EMAIL = 'noreply@bazaart.fr';
    private const FROM_NAME  = 'Bazaart';

    // ─────────────────────────────────────────────────────────────────────────

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    // ─── Méthode 1 : Demande de réinitialisation ─────────────────────────────

    /**
     * Initie le parcours de réinitialisation pour une adresse email.
     *
     * Comportement intentionnel :
     *   - Si l'email N'EXISTE PAS en BDD → ne fait rien silencieusement.
     *   - Si le compte est anonymisé (RGPD) → ne fait rien silencieusement.
     *   - Dans tous les autres cas → génère un token, le persiste, envoie l'email.
     *
     * Le contrôleur DOIT afficher le même message neutre quel que soit le résultat :
     * "Si un compte existe pour cette adresse, un email a été envoyé."
     * → Anti-énumération : un attaquant ne peut pas savoir si un email est inscrit.
     *
     * @param string $email Adresse email saisie dans le formulaire
     */
    public function requestReset(string $email): void
    {
        // ── 1. Chercher l'utilisateur par email ───────────────────────────────
        // Si l'email n'existe pas, on sort silencieusement (anti-énumération).
        // Le contrôleur affichera le même message que si l'email existe.
        $user = $this->userRepository->findOneBy(['email' => $email]);

        if ($user === null) {
            // Email inexistant — on ne lève pas d'exception pour ne pas révéler
            // si l'email est inscrit ou non (protection contre l'énumération d'emails)
            return;
        }

        // ── 2. Vérifier que le compte n'est pas anonymisé (RGPD) ─────────────
        // Un compte anonymisé n'a plus d'email valide et ne peut pas se connecter.
        // Proposer un reset serait inutile et potentiellement trompeur.
        if ($user->isAnonymized()) {
            return;
        }

        // ── 3. Générer un token cryptographiquement sûr ───────────────────────
        // bin2hex(random_bytes(32)) :
        //   - random_bytes(32) → 32 bytes d'entropie (256 bits, niveau cryptographique)
        //   - bin2hex()        → convertit les bytes en chaîne hexadécimale lisible (64 chars)
        // Ce token en clair sera transmis via le lien dans l'email.
        $tokenClair = bin2hex(random_bytes(32));

        // ── 4. Hacher le token avant de l'enregistrer en BDD ─────────────────
        // On ne stocke JAMAIS le token en clair.
        // hash('sha256', $tokenClair) → 64 caractères hexadécimaux
        // Avantage : même si la BDD est compromise, les tokens ne sont pas exploitables
        // sans connaître la valeur originale (qui n'est envoyée que par email).
        $tokenHash = hash('sha256', $tokenClair);

        // ── 5. Calculer la date d'expiration (maintenant + 1 heure) ──────────
        // On utilise DateTime (mutable) par cohérence avec les autres champs de l'entité.
        // modify('+1 hour') retourne la même instance modifiée.
        $expiresAt = (new \DateTime())->modify(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS));

        // ── 6. Persister le hash et l'expiration dans l'entité User ──────────
        $user->setResetTokenHash($tokenHash);
        $user->setResetTokenExpiresAt($expiresAt);
        $this->em->flush();
        // Note : pas de persist() car l'entité est déjà gérée (récupérée via findOneBy).
        // flush() suffit pour propager les modifications en BDD.

        // ── 7. Envoyer l'email avec le token EN CLAIR dans le lien ───────────
        // Le lien pointe vers la route app_reset_password avec le token en clair.
        // C'est l'utilisateur qui "hash" le token en le cliquant (le service retrouve
        // l'utilisateur en calculant hash('sha256', $token) depuis l'URL).
        $this->sendResetEmail($user, $tokenClair);
    }

    // ─── Méthode 2 : Validation d'un token ───────────────────────────────────

    /**
     * Vérifie qu'un token de réinitialisation est valide et non expiré.
     *
     * Retourne l'utilisateur correspondant si le token est valide,
     * null sinon (token inexistant, expiré, ou déjà utilisé).
     *
     * Utilisé par le contrôleur lors du GET /reinitialiser-mot-de-passe/{token}
     * pour afficher le formulaire ou le message d'erreur.
     *
     * @param string $token Token en clair extrait de l'URL
     * @return User|null L'utilisateur si le token est valide, null sinon
     */
    public function validateToken(string $token): ?User
    {
        // ── 1. Calculer le hash du token reçu ─────────────────────────────────
        // La BDD ne stocke que les hashs — on compare hash à hash.
        $tokenHash = hash('sha256', $token);

        // ── 2. Chercher l'utilisateur par ce hash ─────────────────────────────
        $user = $this->userRepository->findByResetTokenHash($tokenHash);

        // Si aucun utilisateur n'a ce hash → token inexistant ou déjà invalidé
        if ($user === null) {
            return null;
        }

        // ── 3. Vérifier l'expiration ──────────────────────────────────────────
        // Si resetTokenExpiresAt est null (ne devrait pas arriver) ou dépassé → invalide
        $expiresAt = $user->getResetTokenExpiresAt();

        if ($expiresAt === null || $expiresAt < new \DateTime()) {
            // Token expiré — on pourrait le nettoyer ici, mais on préfère ne pas
            // faire de flush implicite dans une méthode de lecture (principe CQRS léger).
            // Le nettoyage se fait dans resetPassword() si l'utilisateur réessaie.
            return null;
        }

        return $user;
    }

    // ─── Méthode 3 : Application du nouveau mot de passe ─────────────────────

    /**
     * Applique le nouveau mot de passe et invalide le token (usage unique).
     *
     * Retourne true si le reset a réussi, false si le token est invalide/expiré.
     * Le contrôleur utilise ce résultat pour afficher le bon message flash.
     *
     * Sécurité — usage unique :
     *   Après un reset réussi, resetTokenHash et resetTokenExpiresAt sont remis à null.
     *   Un attaquant qui capturerait le lien email ne pourrait pas l'utiliser une seconde fois.
     *
     * @param string $token       Token en clair extrait de l'URL
     * @param string $newPassword Nouveau mot de passe en clair (déjà validé par le DTO)
     * @return bool true si le reset a réussi, false si le token est invalide
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        // ── 1. Valider le token (réutilise validateToken) ────────────────────
        $user = $this->validateToken($token);

        if ($user === null) {
            // Token invalide ou expiré — le contrôleur affichera un message d'erreur
            return false;
        }

        // ── 2. Hacher et enregistrer le nouveau mot de passe ─────────────────
        // hashPassword() utilise l'algorithme configuré dans security.yaml (bcrypt en V1).
        // Le mot de passe en clair n'est jamais persisté.
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        // ── 3. Invalider le token (usage unique) ─────────────────────────────
        // On remet les deux champs à null pour empêcher toute réutilisation.
        // C'est la garantie que chaque lien de reset ne fonctionne qu'une seule fois.
        $user->setResetTokenHash(null);
        $user->setResetTokenExpiresAt(null);

        // ── 4. Persister les modifications ───────────────────────────────────
        // Un seul flush pour le nouveau mot de passe + invalidation du token.
        $this->em->flush();

        $this->logger->info(
            sprintf('Mot de passe réinitialisé avec succès pour l\'utilisateur %s', $user->getEmail()),
            ['user_id' => $user->getId()]
        );

        return true;
    }

    // ─── Méthode privée : envoi de l'email ───────────────────────────────────

    /**
     * Envoie l'email de réinitialisation de mot de passe.
     *
     * Utilise TemplatedEmail (Symfony Bridge Twig) pour rendre le template Twig.
     * L'URL dans l'email est ABSOLUE (url() via UrlGeneratorInterface) pour
     * qu'elle soit cliquable depuis n'importe quel client email.
     *
     * Gestion d'erreur : les TransportException sont catchées et loguées.
     * On ne relève pas l'exception car l'utilisateur verra le message neutre
     * côté contrôleur — il pourra réessayer dans 1 heure si l'email n'arrive pas.
     *
     * @param User   $user        L'utilisateur destinataire
     * @param string $tokenClair  Le token en clair à inclure dans le lien
     */
    private function sendResetEmail(User $user, string $tokenClair): void
    {
        // Génère l'URL absolue du lien de réinitialisation.
        // REFERENCE_ABSOLUTE = URL complète avec protocole + domaine.
        // Le domaine est configuré via DEFAULT_URI dans .env.local
        // (ex: https://app.bazaart.fr — cf. config/packages/routing.yaml ou framework.yaml).
        // C'est ce qui permet à Symfony de connaître le domaine même dans un contexte
        // sans requête HTTP (ex : commande console ou job async).
        $resetUrl = $this->urlGenerator->generate(
            'app_reset_password',
            ['token' => $tokenClair],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            $email = (new TemplatedEmail())
                ->from(sprintf('%s <%s>', self::FROM_NAME, self::FROM_EMAIL))
                ->to($user->getEmail())
                ->subject('[Bazaart] Réinitialisation de votre mot de passe')
                // Template HTML principal (compatibilité email : tables + CSS inline)
                ->htmlTemplate('emails/password_reset.html.twig')
                // Fallback texte brut pour clients qui bloquent le HTML
                ->textTemplate('emails/password_reset.txt.twig')
                // Variables injectées dans les deux templates
                ->context([
                    'user'      => $user,
                    'resetUrl'  => $resetUrl,
                    'expiresIn' => '1 heure',  // Durée lisible pour l'utilisateur
                ]);

            $this->mailer->send($email);

            $this->logger->info(
                sprintf('Email de réinitialisation envoyé à %s', $user->getEmail()),
                ['user_id' => $user->getId()]
            );

        } catch (\Throwable $e) {
            // On attrape TOUTE erreur (pas seulement les erreurs SMTP) : un échec
            // de rendu du template email, par exemple, ne doit jamais provoquer une
            // 500 visible par l'utilisateur ni révéler que l'email existe.
            // L'utilisateur verra le message neutre côté contrôleur ; l'erreur réelle
            // est tracée ici pour le diagnostic.
            $this->logger->error(
                sprintf('Échec envoi email de réinitialisation à %s : %s', $user->getEmail(), $e->getMessage()),
                [
                    'user_id'   => $user->getId(),
                    'exception' => $e,
                ]
            );
        }
    }
}

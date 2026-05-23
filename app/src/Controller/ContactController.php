<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gère l'envoi du formulaire de contact de la vitrine.
 *
 * Le formulaire est soumis en AJAX (fetch) depuis vitrine.js.
 * La réponse est un JSON { success: true } ou { error: '...' }.
 */
class ContactController extends AbstractController
{
    /**
     * Route POST /contact
     * Valide le token CSRF, puis envoie un email à l'équipe BazaArt.
     */
    #[Route('/contact', name: 'app_contact', methods: ['POST'])]
    public function send(Request $request, MailerInterface $mailer): JsonResponse
    {
        // ── 1. Vérification du token CSRF ──────────────────────────
        // Le token est généré dans le template Twig avec {{ csrf_token('contact') }}
        // Cela protège contre les attaques CSRF (formulaires soumis depuis d'autres sites)
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('contact', $token)) {
            return new JsonResponse(['error' => 'Token de sécurité invalide.'], 400);
        }

        // ── 2. Récupération et nettoyage des champs ────────────────
        $name    = trim($request->request->get('name', ''));
        $email   = trim($request->request->get('email', ''));
        $subject = trim($request->request->get('subject', ''));
        $message = trim($request->request->get('message', ''));

        // ── 3. Validation basique ──────────────────────────────────
        if (empty($name) || empty($email) || empty($message)) {
            return new JsonResponse(['error' => 'Veuillez remplir tous les champs obligatoires.'], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse email invalide.'], 422);
        }

        // ── 4. Envoi de l'email ────────────────────────────────────
        $emailContent = sprintf(
            "Nouveau message reçu depuis bazaart.fr\n\n".
            "Nom     : %s\n".
            "Email   : %s\n".
            "Sujet   : %s\n\n".
            "Message :\n%s",
            $name,
            $email,
            $subject ?: '(non précisé)',
            $message
        );

        $mail = (new Email())
            ->from('noreply@bazaart.fr')          // Expéditeur (domaine de ton serveur)
            ->to('bonjourbazaart@gmail.com')        // Destinataire : l'équipe BazaArt
            ->replyTo($email)                        // Répondre directement à l'expéditeur
            ->subject(sprintf('[BazaArt Contact] %s — %s', $name, $subject ?: 'Nouveau message'))
            ->text($emailContent);

        try {
            $mailer->send($mail);
        } catch (\Exception $e) {
            // Si l'envoi échoue (mailer mal configuré), on retourne quand même
            // un JSON d'erreur au lieu de laisser Symfony générer une page HTML
            return new JsonResponse(['error' => 'Impossible d\'envoyer l\'email. Contactez-nous directement à bonjourbazaart@gmail.com'], 500);
        }

        // ── 5. Réponse succès ──────────────────────────────────────
        return new JsonResponse(['success' => true]);
    }
}

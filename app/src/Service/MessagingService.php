<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MessagingService — logique métier du module messagerie privée.
 *
 * Ce service centralise toutes les opérations sur les conversations et messages :
 * création de conversation, envoi de message, marquage comme lu.
 *
 * Principe de séparation des responsabilités :
 *   - MessagingController : gère HTTP (request, response, CSRF, redirects)
 *   - MessagingVoter      : gère les autorisations
 *   - MessagingService    : gère la logique métier (validation, persistance)
 *
 * Le service ne connaît pas Symfony\Component\HttpFoundation\Request — il travaille
 * uniquement avec des entités et des scalaires PHP.
 */
class MessagingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversationRepository,
        // Injecté pour créer des notifications lors de l'envoi de messages
        private readonly NotificationService $notificationService,
    ) {}

    // ─── Gestion des conversations ────────────────────────────────────────────

    /**
     * Initie ou récupère la conversation entre deux utilisateurs.
     *
     * Règle V1 : une seule conversation possible entre deux utilisateurs.
     * Si elle existe déjà, on la retourne sans créer de doublon.
     *
     * Processus :
     *   1. Vérifie si une conversation existe déjà entre currentUser et otherUser
     *      (via ConversationRepository::findBetweenUsers)
     *   2. Si oui → retourne la conversation existante
     *   3. Si non → crée une nouvelle Conversation avec deux ConversationParticipant
     *
     * Les participants sont créés avec persist() implicite grâce à
     * cascade: ['persist'] configuré sur Conversation::$participants.
     * Un seul flush() suffit pour tout persister en une transaction.
     *
     * @param User $currentUser L'utilisateur qui initie la conversation
     * @param User $otherUser   L'interlocuteur
     * @return Conversation La conversation (existante ou nouvellement créée)
     */
    public function initiateConversation(User $currentUser, User $otherUser): Conversation
    {
        // Vérification d'unicité : cherche une conversation existante entre ces deux users
        $existing = $this->conversationRepository->findBetweenUsers($currentUser, $otherUser);

        if ($existing !== null) {
            // Conversation déjà existante → on la retourne directement
            return $existing;
        }

        // ── Création d'une nouvelle conversation ──────────────────────────────

        $conversation = new Conversation();

        // Création du participant 1 : l'initiateur
        $participantA = new ConversationParticipant();
        $participantA->setUser($currentUser);
        // setConversation sera appelé par Conversation::addParticipant()
        $conversation->addParticipant($participantA);

        // Création du participant 2 : l'interlocuteur
        $participantB = new ConversationParticipant();
        $participantB->setUser($otherUser);
        $conversation->addParticipant($participantB);

        // persist() sur la Conversation suffit grâce à cascade: ['persist'] sur $participants.
        // Doctrine persiste automatiquement les ConversationParticipant liés.
        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    // ─── Envoi de messages ────────────────────────────────────────────────────

    /**
     * Envoie un message dans une conversation.
     *
     * Validation du contenu :
     *   - Non vide (après trim)
     *   - Minimum 1 caractère
     *   - Maximum 5000 caractères (limite V1 — arbitraire mais raisonnable)
     *
     * Effets de bord après l'envoi :
     *   - Conversation::lastMessageAt est mis à jour (pour le tri dans la liste)
     *   - Le lastReadAt de l'expéditeur est mis à jour (il a "lu" sa propre conversation)
     *
     * @param User         $author       L'utilisateur qui envoie le message
     * @param Conversation $conversation La conversation cible
     * @param string       $content      Le texte du message (brut, sans HTML)
     * @return Message|string  Le Message créé en cas de succès, ou une string d'erreur
     */
    public function sendMessage(User $author, Conversation $conversation, string $content): Message|string
    {
        // ── Validation ────────────────────────────────────────────────────────

        // trim() supprime les espaces et retours à la ligne en début/fin
        $content = trim($content);

        if ($content === '') {
            return 'Le message ne peut pas être vide.';
        }

        if (mb_strlen($content) > 5000) {
            return 'Le message ne doit pas dépasser 5000 caractères.';
        }

        // ── Création du message ───────────────────────────────────────────────

        $message = new Message();
        $message->setAuthor($author);
        $message->setConversation($conversation);
        $message->setContent($content);

        // ── Mise à jour de la conversation ────────────────────────────────────

        // Met à jour la date du dernier message pour que cette conversation
        // remonte en tête de liste lors du prochain affichage.
        $conversation->setLastMessageAt(new \DateTime());

        // ── Marquage comme lu pour l'expéditeur ──────────────────────────────

        // L'expéditeur "lit" automatiquement sa propre conversation au moment d'envoyer.
        // Sans ça, son propre message compterait dans ses non-lus (incohérent).
        $senderParticipant = $this->getParticipant($author, $conversation);
        if ($senderParticipant !== null) {
            $senderParticipant->markAsRead();
        }

        // ── Persistance ───────────────────────────────────────────────────────

        $this->em->persist($message);
        // Un seul flush() sauvegarde le message + les modifications sur la conversation
        // + le lastReadAt du participant en une seule transaction SQL.
        $this->em->flush();

        // ── Notification à l'autre participant ────────────────────────────────

        // Après le flush (message bien sauvegardé), on notifie l'autre participant.
        // On récupère l'autre utilisateur via getOtherParticipant() défini sur Conversation.
        // Règle : on ne notifie PAS l'expéditeur lui-même (NotificationService le vérifie aussi).
        $otherUser = $conversation->getOtherParticipant($author);
        if ($otherUser !== null) {
            // Crée une notification de type "new_message" pour l'autre participant.
            // On passe $sender=$author pour que NotificationService bloque l'auto-notification.
            // relatedEntityType='conversation' + relatedEntityId → lien vers la conversation.
            // data['senderEmail'] → affiché dans le texte de la notification.
            $this->notificationService->create(
                recipient: $otherUser,
                type: NotificationType::NewMessage,
                relatedEntityType: 'conversation',
                relatedEntityId: $conversation->getId(),
                // On ne stocke que la partie locale de l'email (avant @) — RGPD :
                // l'email complet est une donnée personnelle ; la partie locale suffit
                // pour afficher "Nouveau message de marie" dans la notification.
                data: ['senderEmail' => explode('@', $author->getEmail())[0]],
                sender: $author,
            );
        }

        return $message;
    }

    // ─── Gestion de la lecture ────────────────────────────────────────────────

    /**
     * Marque une conversation comme "lue maintenant" pour un utilisateur.
     *
     * Appelé par MessagingController::show() à chaque ouverture du fil de conversation.
     * Met à jour ConversationParticipant::lastReadAt = DateTime::now.
     *
     * Après cet appel, les messages existants (createdAt <= now) ne seront plus
     * comptabilisés comme "non lus" pour cet utilisateur.
     *
     * Si l'utilisateur n'est pas participant (cas anormal), la méthode ne fait rien
     * (fail silently — la sécurité est gérée par MessagingVoter en amont).
     */
    public function markAsRead(User $user, Conversation $conversation): void
    {
        $participant = $this->getParticipant($user, $conversation);

        if ($participant === null) {
            // L'utilisateur n'est pas participant — situation anormale.
            // On ne lève pas d'exception car cette méthode est appelée après
            // denyAccessUnlessGranted() dans le controller, qui devrait déjà
            // avoir bloqué les non-participants. On fail silently par sécurité.
            return;
        }

        $participant->markAsRead();
        $this->em->flush();
    }

    // ─── Utilitaires ─────────────────────────────────────────────────────────

    /**
     * Retourne le ConversationParticipant d'un utilisateur dans une conversation.
     *
     * Utilisé en interne par sendMessage() et markAsRead() pour accéder au lastReadAt.
     * Exposé en public pour être utilisable dans MessagingController si nécessaire.
     *
     * On itère sur la collection de participants (déjà chargée en mémoire grâce
     * aux JOIN FETCH dans ConversationRepository) — pas de requête SQL supplémentaire.
     *
     * @return ConversationParticipant|null null si l'user n'est pas participant
     */
    public function getParticipant(User $user, Conversation $conversation): ?ConversationParticipant
    {
        foreach ($conversation->getParticipants() as $participant) {
            // Comparaison par identité d'objet (===) — garanti par le Unit of Work Doctrine
            if ($participant->getUser() === $user) {
                return $participant;
            }
        }
        return null;
    }
}

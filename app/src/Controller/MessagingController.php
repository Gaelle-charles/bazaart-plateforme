<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Security\Voter\MessagingVoter;
use App\Service\MessagingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * MessagingController — pages et actions de la messagerie privée.
 *
 * Convention du projet : controller "fin".
 *   - Gère HTTP : request, response, CSRF, redirects, flash messages
 *   - Délègue la logique métier à MessagingService
 *   - Délègue les autorisations à MessagingVoter (via denyAccessUnlessGranted)
 *   - Délègue les requêtes BDD aux repositories
 *
 * Préfixe de route : /messages (déclaré sur la classe)
 * Noms de routes : app_messaging_ + nom de l'action
 *
 * ⚠️ Ordre des routes dans ce controller :
 *   La route /messages/new/{userId} DOIT être déclarée AVANT /messages/{id}
 *   car Symfony matche les routes dans l'ordre de déclaration.
 *   Si /{id} est déclaré en premier, "new" serait interprété comme un ID entier
 *   → erreur 404 ou mauvais comportement.
 */
#[IsGranted('ROLE_USER')]
#[Route('/messages', name: 'app_messaging_')]
class MessagingController extends AbstractController
{
    public function __construct(
        private readonly ConversationRepository $conversationRepository,
        private readonly MessageRepository $messageRepository,
        private readonly UserRepository $userRepository,
        private readonly MessagingService $messagingService,
    ) {}

    // ─── Liste des conversations ──────────────────────────────────────────────

    /**
     * Affiche la liste de toutes les conversations de l'utilisateur connecté.
     *
     * Pour chaque conversation, on calcule le nombre de messages non lus
     * (badge rouge affiché à droite de chaque ligne).
     *
     * Les conversations sont triées par dernière activité (plus récente en premier)
     * grâce à ConversationRepository::findByUser().
     *
     * Route : GET /messages
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Récupère toutes les conversations de l'utilisateur, triées par activité
        $conversations = $this->conversationRepository->findByUser($currentUser);

        // Calcule le nombre de messages non lus par conversation en UNE SEULE requête SQL.
        // Avant ce refactor, on faisait une requête COUNT par conversation (N+1).
        // countUnreadGroupedByConversation() retourne [conversationId => count]
        // pour les conversations ayant AU MOINS 1 message non lu.
        // Les conversations absentes du tableau ont 0 non lus.
        $unreadByConv = $this->messageRepository->countUnreadGroupedByConversation($currentUser);

        // On construit le tableau complet [conversationId => count] avec 0 comme défaut
        $unreadCounts = [];
        foreach ($conversations as $conversation) {
            $unreadCounts[$conversation->getId()] = $unreadByConv[$conversation->getId()] ?? 0;
        }

        return $this->render('messaging/index.html.twig', [
            'conversations' => $conversations,
            'unreadCounts'  => $unreadCounts,
            'currentUser'   => $currentUser,
        ]);
    }

    // ─── Initiation d'une nouvelle conversation ───────────────────────────────

    /**
     * Initie une conversation avec un autre utilisateur.
     *
     * ⚠️ Route déclarée EN PREMIER pour éviter le conflit avec /{id}.
     *   Si /{id} était déclaré avant, Symfony essaierait de convertir "new" en entier.
     *
     * GET  → si une conversation existe déjà → redirige vers app_messaging_show
     *      → sinon → affiche le formulaire du premier message
     * POST → valide CSRF → crée la conversation + envoie le premier message → redirige
     *
     * Cas d'erreur :
     *   - userId introuvable en BDD → 404
     *   - L'utilisateur essaie de s'écrire à lui-même → 400 Bad Request
     *
     * Route : GET/POST /messages/new/{userId}
     */
    #[Route('/new/{userId}', name: 'new', methods: ['GET', 'POST'])]
    public function new(int $userId, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Récupère l'interlocuteur cible
        $otherUser = $this->userRepository->find($userId);
        if ($otherUser === null) {
            // L'utilisateur demandé n'existe pas en BDD
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        // Un utilisateur ne peut pas s'écrire à lui-même
        // On compare les IDs (plus sûr que === sur les entités dans ce contexte)
        if ($otherUser->getId() === $currentUser->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas vous envoyer un message à vous-même.');
        }

        // GET : vérifie si une conversation existe déjà entre les deux users
        // Si oui → redirige directement vers le fil existant (pas de doublon)
        if ($request->isMethod('GET')) {
            $existing = $this->conversationRepository->findBetweenUsers($currentUser, $otherUser);
            if ($existing !== null) {
                // Une conversation existe déjà — on redirige vers le fil
                return $this->redirectToRoute('app_messaging_show', ['id' => $existing->getId()]);
            }

            // Pas de conversation existante → on affiche le formulaire du premier message
            return $this->render('messaging/new.html.twig', [
                'otherUser' => $otherUser,
            ]);
        }

        // ── POST : traitement du formulaire ──────────────────────────────────

        // Vérification CSRF en PREMIER (avant toute vérification d'autorisation).
        // Convention du projet : CSRF avant autorisation pour éviter les oracles de rôle.
        // (un oracle de rôle = une réponse différente selon les droits révèle de l'information)
        $csrfToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('messaging_new', $csrfToken)) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_messaging_new', ['userId' => $userId]);
        }

        // Vérification de l'autorisation d'initier une conversation
        $this->denyAccessUnlessGranted(MessagingVoter::CONVERSATION_INITIATE);

        // Récupère ou crée la conversation
        $conversation = $this->messagingService->initiateConversation($currentUser, $otherUser);

        // Envoie le premier message
        $content = $request->request->getString('content', '');
        $result = $this->messagingService->sendMessage($currentUser, $conversation, $content);

        if (is_string($result)) {
            // sendMessage() retourne une string en cas d'erreur de validation
            $this->addFlash('error', $result);
            return $this->render('messaging/new.html.twig', [
                'otherUser' => $otherUser,
            ]);
        }

        // Message envoyé avec succès → redirige vers le fil
        return $this->redirectToRoute('app_messaging_show', ['id' => $conversation->getId()]);
    }

    // ─── Fil d'une conversation ───────────────────────────────────────────────

    /**
     * Affiche le fil de messages d'une conversation.
     *
     * Actions :
     *   1. Vérifie que l'utilisateur est bien participant (via MessagingVoter)
     *   2. Marque la conversation comme lue (met à jour lastReadAt)
     *   3. Charge les messages triés chronologiquement
     *   4. Passe au template : conversation, messages, otherUser
     *
     * Route : GET /messages/{id}
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Récupère la conversation ou renvoie 404 si elle n'existe pas
        $conversation = $this->conversationRepository->find($id);
        if ($conversation === null) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        // Vérifie que l'utilisateur est bien participant à cette conversation.
        // MessagingVoter::CONVERSATION_VIEW appelle conversation->hasParticipant($user).
        // Si l'utilisateur n'est pas participant → AccessDeniedException → 403.
        $this->denyAccessUnlessGranted(MessagingVoter::CONVERSATION_VIEW, $conversation);

        // Marque la conversation comme lue pour l'utilisateur courant.
        // Ceci met à jour ConversationParticipant::lastReadAt = now.
        // Après cet appel, les messages visibles ne seront plus comptés comme "non lus".
        $this->messagingService->markAsRead($currentUser, $conversation);

        // Charge tous les messages du fil en ordre chronologique (ASC)
        $messages = $this->messageRepository->findByConversation($conversation);

        // Récupère l'autre participant pour l'entête "Conversation avec X"
        $otherUser = $conversation->getOtherParticipant($currentUser);

        return $this->render('messaging/show.html.twig', [
            'conversation' => $conversation,
            'messages'     => $messages,
            'otherUser'    => $otherUser,
            'currentUser'  => $currentUser,
        ]);
    }

    // ─── Envoi d'un message ───────────────────────────────────────────────────

    /**
     * Envoie un message dans une conversation existante.
     *
     * Ordre des vérifications (convention projet : CSRF d'abord) :
     *   1. CSRF token (évite les oracles de rôle)
     *   2. Autorisation via MessagingVoter::CONVERSATION_SEND
     *   3. Validation du contenu via MessagingService::sendMessage()
     *   4. Redirect vers le fil avec ancre #last-message (scroll automatique)
     *
     * Route : POST /messages/{id}/send
     */
    #[Route('/{id}/send', name: 'send', methods: ['POST'])]
    public function send(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Récupère la conversation ou 404
        $conversation = $this->conversationRepository->find($id);
        if ($conversation === null) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        // ── 1. Vérification CSRF (EN PREMIER — convention projet) ─────────────
        // Le token est nommé dynamiquement avec l'ID de la conversation
        // pour éviter qu'un token valide d'une conversation ne soit réutilisé sur une autre.
        $csrfToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('messaging_send_' . $id, $csrfToken)) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_messaging_show', ['id' => $id]);
        }

        // ── 2. Autorisation ───────────────────────────────────────────────────
        $this->denyAccessUnlessGranted(MessagingVoter::CONVERSATION_SEND, $conversation);

        // ── 3. Envoi du message via le service ────────────────────────────────
        $content = $request->request->getString('content', '');
        $result = $this->messagingService->sendMessage($currentUser, $conversation, $content);

        if (is_string($result)) {
            // Erreur de validation → flash error + retour au fil
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_messaging_show', ['id' => $id]);
        }

        // ── 4. Redirection vers le fil avec scroll vers le dernier message ────
        // redirectToRoute() ne supporte pas les ancres (#last-message).
        // On génère l'URL manuellement et on y concatène le fragment.
        // L'ancre #last-message est définie dans messaging/show.html.twig
        // sur le dernier message du fil (id="last-message").
        return $this->redirect(
            $this->generateUrl('app_messaging_show', ['id' => $id]) . '#last-message',
            Response::HTTP_SEE_OTHER
        );
    }
}

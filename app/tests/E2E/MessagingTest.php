<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Repository\ConversationRepository;

/**
 * MessagingTest — Test E2E #4 : Messagerie privée.
 *
 * Ce test couvre le parcours "Envoyer un message privé" du CDC V3 §10.
 *
 * Scénarios testés :
 *   1. La page /messages est accessible pour un utilisateur connecté (200)
 *   2. La page /messages/new/{userId} s'affiche correctement (200)
 *   3. L'envoi d'un premier message crée une conversation en BDD
 *   4. Un utilisateur non connecté est redirigé vers /login
 *
 * Architecture :
 *   MessagingController est protégé par #[IsGranted('ROLE_USER')] au niveau de la classe.
 *
 * Particularités :
 *   - La route /messages/new/{userId} prend l'ID du destinataire en paramètre.
 *   - Le premier message crée la Conversation + le ConversationParticipant pour les deux.
 *   - On ne peut pas s'envoyer un message à soi-même (vérification dans le controller).
 */
class MessagingTest extends AbstractE2ETestCase
{
    /**
     * L'expéditeur du message (utilisateur connecté dans les tests).
     */
    private ?User $sender = null;

    /**
     * Le destinataire du message (autre utilisateur en BDD).
     */
    private ?User $recipient = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Purge pour repartir d'un état propre
        $this->purgeDatabase();

        // Crée deux utilisateurs : l'expéditeur et le destinataire
        $this->sender    = $this->createRegularUser('expediteur@test.fr');
        $this->recipient = $this->createRegularUser('destinataire@test.fr');
    }

    // ─── Test 4.1 : Page /messages accessible ────────────────────────────────

    /**
     * Vérifie que la liste des conversations s'affiche pour un utilisateur connecté.
     *
     * MessagingController::index() charge les conversations de l'utilisateur.
     * Pour un nouvel utilisateur, la liste est vide mais la page s'affiche quand même.
     */
    public function testMessagingIndexIsAccessibleForAuthenticatedUser(): void
    {
        $this->loginAs($this->sender);

        $this->client->request('GET', '/messages');

        // 200 OK : la liste des conversations s'affiche (même si vide)
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 4.2 : Non connecté → redirect /login ───────────────────────────

    /**
     * Vérifie qu'un utilisateur non authentifié est redirigé vers /login.
     *
     * La sécurité doit protéger la messagerie privée en toutes circonstances.
     */
    public function testMessagingIndexRedirectsToLoginWhenNotAuthenticated(): void
    {
        $this->client->request('GET', '/messages');

        // Doit rediriger (302)
        $this->assertResponseRedirects();

        // La destination finale doit être la page de login
        $this->client->followRedirect();
        $this->assertRouteSame('app_login');
    }

    // ─── Test 4.3 : Page /messages/new/{userId} s'affiche ───────────────────

    /**
     * Vérifie que la page d'initiation d'une conversation s'affiche correctement.
     *
     * GET /messages/new/{userId} :
     *   - Si aucune conversation n'existe entre les deux users → affiche le formulaire
     *   - Si une conversation existe déjà → redirige vers elle
     *
     * Ici, pas de conversation existante → formulaire.
     */
    public function testNewConversationPageIsAccessible(): void
    {
        $this->loginAs($this->sender);

        // La route prend l'ID du destinataire en paramètre
        $this->client->request('GET', '/messages/new/' . $this->recipient->getId());

        // 200 OK : le formulaire d'initiation s'affiche
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 4.4 : Envoyer un message crée une conversation ────────────────

    /**
     * Vérifie qu'envoyer un premier message crée bien une conversation en BDD.
     *
     * Flux attendu :
     *   POST /messages/new/{userId} → créé Conversation + Message → redirect vers la conversation
     *
     * MessagingService::initiateConversation() crée la Conversation et les
     * ConversationParticipants pour les deux utilisateurs.
     * MessagingService::sendMessage() crée le Message dans la Conversation.
     */
    public function testSendFirstMessageCreatesConversation(): void
    {
        $this->loginAs($this->sender);

        // ── Étape 1 : vérifie que la page GET s'affiche et récupère le token CSRF ─
        // GET /messages/new/{userId} affiche le formulaire de premier message.
        // On en profite pour extraire le token CSRF du formulaire.
        $this->client->request('GET', '/messages/new/' . $this->recipient->getId());
        $this->assertResponseIsSuccessful();

        // Extrait le token CSRF depuis le champ caché "_token" du formulaire.
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_token"]');

        // ── Étape 2 : envoie le premier message ──────────────────────────────
        $this->client->request('POST', '/messages/new/' . $this->recipient->getId(), [
            'content' => 'Bonjour ! Ceci est un message de test E2E depuis les tests automatisés.',
            '_token'  => $csrfToken,
        ]);

        // ── Étape 3 : vérifie la redirection vers la conversation ─────────────
        // MessagingController::new() redirige vers app_messaging_show après succès
        $this->assertResponseRedirects();
        $this->assertResponseStatusCodeSame(302);

        // ── Étape 4 : vérifie qu'une conversation existe en BDD ──────────────
        /** @var ConversationRepository $repo */
        $repo = static::getContainer()->get(ConversationRepository::class);

        // Recharge les entités depuis la BDD pour éviter les problèmes de cache Doctrine
        $this->em->clear();

        $senderFresh    = $this->em->find(\App\Entity\User::class, $this->sender->getId());
        $recipientFresh = $this->em->find(\App\Entity\User::class, $this->recipient->getId());

        $conversation = $repo->findBetweenUsers($senderFresh, $recipientFresh);

        $this->assertNotNull(
            $conversation,
            'Une conversation doit avoir été créée entre les deux utilisateurs'
        );

        // Vérifie que la conversation a bien 2 participants en comptant directement en BDD.
        // Note : on n'utilise PAS hasParticipant() car elle compare les instances d'objets
        // avec ===, ce qui échoue après em->clear() (les objets rechargés ne sont pas identiques
        // en mémoire aux participants déjà chargés dans la collection).
        // On vérifie plutôt via le count des participants dans la conversation.
        $participantCount = $conversation->getParticipants()->count();
        $this->assertSame(
            2,
            $participantCount,
            'La conversation doit avoir exactement 2 participants (expéditeur + destinataire)'
        );

        // Vérifie les IDs des participants (ordre non garanti → on utilise un set d'IDs)
        $participantUserIds = [];
        foreach ($conversation->getParticipants() as $participant) {
            $participantUserIds[] = $participant->getUser()->getId();
        }
        sort($participantUserIds);

        $expectedIds = [$this->sender->getId(), $this->recipient->getId()];
        sort($expectedIds);

        $this->assertSame(
            $expectedIds,
            $participantUserIds,
            'Les participants doivent être exactement l\'expéditeur et le destinataire'
        );
    }

    // ─── Test 4.5 : On ne peut pas s'écrire à soi-même ──────────────────────

    /**
     * Vérifie qu'un utilisateur ne peut pas initier une conversation avec lui-même.
     *
     * MessagingController::new() lève AccessDeniedException (→ 403) si
     * $otherUser->getId() === $currentUser->getId().
     */
    public function testCannotSendMessageToSelf(): void
    {
        $this->loginAs($this->sender);

        // Tente d'accéder à la page de conversation avec soi-même
        $this->client->request('GET', '/messages/new/' . $this->sender->getId());

        // Doit retourner 403 Forbidden (ou rediriger selon la config d'erreur)
        $this->assertResponseStatusCodeSame(403);
    }
}

<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Conversation;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * MessagingVoter — gère toutes les autorisations liées à la messagerie privée.
 *
 * Convention Symfony : toute décision d'autorisation passe par un Voter.
 * Dans les controllers, on écrit :
 *   $this->denyAccessUnlessGranted(MessagingVoter::CONVERSATION_VIEW, $conversation)
 *
 * et NON :
 *   if ($conversation->hasParticipant($user)) { ... }  // ← à bannir dans les controllers
 *
 * Attributs gérés :
 *   - CONVERSATION_VIEW    : lire les messages d'une conversation
 *   - CONVERSATION_SEND    : envoyer un message dans une conversation
 *   - CONVERSATION_INITIATE : initier une nouvelle conversation (sans sujet, juste le rôle)
 *
 * @extends Voter<string, Conversation|null>
 */
class MessagingVoter extends Voter
{
    // ─── Constantes des attributs gérés ──────────────────────────────────────

    /** Lire les messages d'une conversation */
    public const string CONVERSATION_VIEW = 'CONVERSATION_VIEW';

    /** Envoyer un message dans une conversation */
    public const string CONVERSATION_SEND = 'CONVERSATION_SEND';

    /**
     * Initier une nouvelle conversation.
     * Sujet : null (pas encore de conversation à vérifier)
     * Requis : être authentifié avec au moins ROLE_USER
     */
    public const string CONVERSATION_INITIATE = 'CONVERSATION_INITIATE';

    /**
     * Le service Security est injecté pour utiliser isGranted() au lieu de getRoles().
     *
     * Raison : isGranted() propage la role_hierarchy de security.yaml.
     * Exemple : un ROLE_ADMIN qui hérite de ROLE_USER sera autorisé à initier
     * une conversation grâce à isGranted('ROLE_USER'), sans avoir à lister
     * explicitement tous les rôles dans le code.
     */
    public function __construct(private readonly Security $security) {}

    /** Liste de tous les attributs que ce voter prend en charge */
    private const array SUPPORTED_ATTRIBUTES = [
        self::CONVERSATION_VIEW,
        self::CONVERSATION_SEND,
        self::CONVERSATION_INITIATE,
    ];

    /**
     * Détermine si ce voter doit traiter la demande d'autorisation.
     *
     * Symfony appelle supports() en premier. Si on retourne false,
     * Symfony passe au voter suivant (chaîne de responsabilité).
     *
     * Règles :
     *   - CONVERSATION_INITIATE : sujet null (pas encore de conversation)
     *   - CONVERSATION_VIEW / SEND : sujet doit être une Conversation
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        // On ne traite que les attributs déclarés dans ce voter
        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)) {
            return false;
        }

        // CONVERSATION_INITIATE n'a pas de sujet (on initie une nouvelle conversation)
        if ($attribute === self::CONVERSATION_INITIATE) {
            return true;
        }

        // Pour VIEW et SEND, le sujet doit être une Conversation existante
        return $subject instanceof Conversation;
    }

    /**
     * Logique de décision principale.
     *
     * Appelée uniquement si supports() retourne true.
     *
     * @param Conversation|null $subject La conversation concernée (null pour INITIATE)
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Récupère l'utilisateur depuis le token de sécurité
        $user = $token->getUser();

        // Rejet immédiat si l'utilisateur n'est pas authentifié
        // (les utilisateurs anonymes n'ont pas accès à la messagerie)
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::CONVERSATION_INITIATE => $this->canInitiate(),
            self::CONVERSATION_VIEW     => $this->canView($user, $subject),
            self::CONVERSATION_SEND     => $this->canSend($user, $subject),
            default                     => false,
        };
    }

    // ─── Méthodes de décision privées ────────────────────────────────────────

    /**
     * Tout utilisateur authentifié peut initier une conversation.
     *
     * On utilise isGranted('ROLE_USER') plutôt qu'un simple "return true"
     * car isGranted() propage la hiérarchie des rôles. En pratique, tout
     * utilisateur authentifié a ROLE_USER (garanti par User::getRoles()).
     */
    private function canInitiate(): bool
    {
        return $this->security->isGranted('ROLE_USER');
    }

    /**
     * Peut lire une conversation : uniquement si l'utilisateur en est participant.
     *
     * hasParticipant() compare les objets User par identité (===) grâce à
     * l'Unit of Work de Doctrine (deux variables pointant sur le même user = même objet PHP).
     */
    private function canView(User $user, mixed $subject): bool
    {
        if (!$subject instanceof Conversation) {
            return false;
        }

        // Vérification de participation — implémentée dans Conversation::hasParticipant()
        return $subject->hasParticipant($user);
    }

    /**
     * Peut envoyer un message : même condition que la lecture.
     *
     * En V1, il n'y a pas de notion de conversation "archivée" ou "bloquée".
     * Si un utilisateur peut voir la conversation, il peut aussi y envoyer un message.
     *
     * Pour V2 : ajouter ici la vérification du statut de la conversation
     * (ex: $subject->isBlocked() || $subject->isArchived() → return false).
     */
    private function canSend(User $user, mixed $subject): bool
    {
        // Même règle que VIEW : être participant suffit
        return $this->canView($user, $subject);
    }
}

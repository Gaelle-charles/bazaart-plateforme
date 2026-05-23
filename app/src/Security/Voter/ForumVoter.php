<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ForumReply;
use App\Entity\ForumThread;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ForumVoter — gère toutes les autorisations liées au forum communautaire.
 *
 * Convention Symfony : toute décision d'autorisation passe par un Voter.
 * On n'écrit JAMAIS `if ($user->getRoles() === ...)` dans un controller ou un service.
 * On appelle `$this->denyAccessUnlessGranted(ForumVoter::FORUM_LOCK, $thread)`.
 *
 * Ce voter a été mis à jour pour utiliser les vrais types ForumThread et ForumReply
 * (les entités sont maintenant créées — les méthodes method_exists() de la v0 sont
 * remplacées par des vérifications typées instanceof).
 *
 * Attributs gérés :
 *   - FORUM_CREATE_THREAD : créer un sujet (sujet = null)
 *   - FORUM_REPLY         : répondre à un thread (sujet = ForumThread)
 *   - FORUM_EDIT          : modifier un thread ou une réponse (sujet = ForumThread|ForumReply)
 *   - FORUM_DELETE        : supprimer un thread ou une réponse (sujet = ForumThread|ForumReply)
 *   - FORUM_LOCK          : verrouiller/déverrouiller (sujet = ForumThread)
 *   - FORUM_PIN           : épingler/désépingler (sujet = ForumThread)
 *   - FORUM_MODERATE      : accès aux actions de modération générales
 *
 * @extends Voter<string, ForumThread|ForumReply|null>
 */
class ForumVoter extends Voter
{
    // ─── Constantes des attributs gérés ──────────────────────────────────────

    /** Créer un nouveau thread dans une catégorie du forum */
    public const string FORUM_CREATE_THREAD = 'FORUM_CREATE_THREAD';

    /** Répondre à un thread existant */
    public const string FORUM_REPLY = 'FORUM_REPLY';

    /** Modifier le contenu d'un thread ou d'une réponse */
    public const string FORUM_EDIT = 'FORUM_EDIT';

    /** Supprimer un thread ou une réponse */
    public const string FORUM_DELETE = 'FORUM_DELETE';

    /** Verrouiller un thread (plus de nouvelles réponses possibles) */
    public const string FORUM_LOCK = 'FORUM_LOCK';

    /** Épingler un thread en haut de la catégorie */
    public const string FORUM_PIN = 'FORUM_PIN';

    /** Actions de modération générales (masquer, signaler, etc.) */
    public const string FORUM_MODERATE = 'FORUM_MODERATE';

    /**
     * Le service Security est injecté pour utiliser isGranted() au lieu de getRoles().
     *
     * Pourquoi $this->security->isGranted() et non $user->getRoles() ?
     *   isGranted() propage la role_hierarchy de security.yaml.
     *   Exemple : isGranted('ROLE_MODERATOR') retourne true pour ROLE_ADMIN
     *   car security.yaml déclare que ROLE_ADMIN hérite de ROLE_MODERATOR.
     *   getRoles() ne propage PAS cette hiérarchie — on obtiendrait seulement
     *   les rôles explicitement attribués à l'utilisateur.
     */
    public function __construct(private readonly Security $security) {}

    /** Liste de tous les attributs que ce voter prend en charge */
    private const array SUPPORTED_ATTRIBUTES = [
        self::FORUM_CREATE_THREAD,
        self::FORUM_REPLY,
        self::FORUM_EDIT,
        self::FORUM_DELETE,
        self::FORUM_LOCK,
        self::FORUM_PIN,
        self::FORUM_MODERATE,
    ];

    /**
     * Détermine si ce voter doit traiter la demande d'autorisation.
     *
     * Règles de filtrage :
     *   - FORUM_CREATE_THREAD : sujet peut être null (on crée, il n'y a pas encore d'entité)
     *   - FORUM_REPLY : sujet peut être un ForumThread (on répond à un thread)
     *   - Autres : sujet doit être ForumThread ou ForumReply
     *
     * Si supports() retourne false, Symfony passe au voter suivant
     * (système de chaîne de responsabilité).
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        // On ne traite que les attributs déclarés dans ce voter
        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)) {
            return false;
        }

        // Pour FORUM_CREATE_THREAD : pas de sujet requis (création)
        if ($attribute === self::FORUM_CREATE_THREAD) {
            return true;
        }

        // Pour FORUM_REPLY : on peut passer un ForumThread comme sujet (vérification isLocked)
        // ou null si on ne veut vérifier que le rôle utilisateur
        if ($attribute === self::FORUM_REPLY) {
            return $subject === null || $subject instanceof ForumThread;
        }

        // Pour tous les autres attributs : le sujet doit être un ForumThread ou ForumReply
        return $subject instanceof ForumThread || $subject instanceof ForumReply;
    }

    /**
     * Logique de décision principale.
     *
     * Appelée uniquement si `supports()` a retourné true.
     * Le match() PHP 8 est plus lisible que les if/elseif en cascade.
     *
     * @param ForumThread|ForumReply|null $subject L'entité cible (thread ou réponse)
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Récupère l'utilisateur depuis le token Symfony Security
        $user = $token->getUser();

        // Aucun accès pour les utilisateurs non authentifiés
        // (même si ROLE_USER est requis au niveau du controller, cette vérification
        // est une sécurité supplémentaire au niveau du voter)
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::FORUM_CREATE_THREAD => $this->canCreateThread($user),
            self::FORUM_REPLY         => $this->canReply($user, $subject),
            self::FORUM_EDIT          => $this->canEdit($user, $subject),
            self::FORUM_DELETE        => $this->canDelete($user, $subject),
            self::FORUM_LOCK          => $this->isAdminOrModerator(),
            self::FORUM_PIN           => $this->security->isGranted('ROLE_ADMIN'),
            self::FORUM_MODERATE      => $this->isAdminOrModerator(),
            default                   => false,
        };
    }

    // ─── Méthodes de décision ─────────────────────────────────────────────────

    /**
     * Tout utilisateur connecté peut créer un thread.
     *
     * Le forum est ouvert à toute la communauté Bazaart.
     * Pas de restriction de rôle au-delà de l'authentification.
     */
    private function canCreateThread(User $user): bool
    {
        // ROLE_USER est garanti implicitement (le voter rejette les anonymes en amont)
        return true;
    }

    /**
     * Peut répondre à un thread :
     *   - Tout utilisateur connecté, SAUF si le thread est verrouillé
     *   - Admin et modérateurs peuvent toujours répondre, même sur un thread verrouillé
     *
     * @param ForumThread|ForumReply|null $subject
     */
    private function canReply(User $user, mixed $subject): bool
    {
        // Vérifie que l'utilisateur a au moins ROLE_USER
        if (!$this->security->isGranted('ROLE_USER')) {
            return false;
        }

        // Si on passe un ForumThread comme sujet, on vérifie le verrouillage
        if ($subject instanceof ForumThread && $subject->isLocked()) {
            // Seul un admin/moderateur peut répondre sur un thread verrouillé
            return $this->isAdminOrModerator();
        }

        // Thread non verrouillé (ou pas de sujet) : tout le monde peut répondre
        return true;
    }

    /**
     * Peut modifier un thread ou une réponse :
     *   - L'auteur du contenu (en comparant les objets User par référence)
     *   - Un administrateur (ROLE_ADMIN via la hiérarchie)
     *   - Un modérateur (ROLE_MODERATOR)
     *
     * Note sur la comparaison === pour les entités Doctrine :
     *   Doctrine garantit l'identité des objets dans l'Unit of Work.
     *   Si $subject->getAuthor() et $user représentent le même user en base,
     *   ce sont le même objet PHP → === retourne true.
     *
     * @param ForumThread|ForumReply|null $subject
     */
    private function canEdit(User $user, mixed $subject): bool
    {
        // Admin et modérateurs peuvent toujours éditer n'importe quel contenu
        if ($this->isAdminOrModerator()) {
            return true;
        }

        // L'auteur peut modifier son propre contenu
        if ($subject instanceof ForumThread) {
            return $subject->getAuthor() === $user;
        }

        if ($subject instanceof ForumReply) {
            return $subject->getAuthor() === $user;
        }

        // Pas de sujet ou type inconnu → refus par sécurité
        return false;
    }

    /**
     * Peut supprimer un thread ou une réponse.
     * Même règle que pour FORUM_EDIT : auteur ou admin/modo.
     *
     * @param ForumThread|ForumReply|null $subject
     */
    private function canDelete(User $user, mixed $subject): bool
    {
        // Même logique que canEdit — mutualisation intentionnelle
        return $this->canEdit($user, $subject);
    }

    // ─── Helper privé ─────────────────────────────────────────────────────────

    /**
     * Vérifie si l'utilisateur actuellement connecté est admin OU modérateur.
     *
     * On utilise $this->security->isGranted() plutôt que d'inspecter les rôles
     * directement pour deux raisons :
     *
     *   1. Propagation de la hiérarchie : isGranted('ROLE_MODERATOR') retourne
     *      true pour un ROLE_ADMIN car security.yaml déclare ROLE_ADMIN comme
     *      héritant de ROLE_MODERATOR. getRoles() ne propage PAS cette hiérarchie.
     *
     *   2. isGranted() opère sur l'utilisateur actuellement connecté (token).
     *      C'est bien ce qu'on veut — le voter s'exécute dans le contexte de la requête.
     *
     * Note : isGranted('ROLE_MODERATOR') serait techniquement suffisant (ROLE_ADMIN
     * hérite de ROLE_MODERATOR via la hiérarchie). On garde les deux vérifications
     * pour que le code soit lisible sans mémoriser la hiérarchie complète.
     */
    private function isAdminOrModerator(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted('ROLE_MODERATOR');
    }
}

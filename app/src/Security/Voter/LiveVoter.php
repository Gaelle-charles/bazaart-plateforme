<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Live;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * LiveVoter — gère les autorisations liées aux lives planifiés.
 *
 * Ce voter a été mis à jour maintenant que l'entité Live existe.
 * Il remplace l'implémentation provisoire basée sur method_exists().
 *
 * ─── Attributs gérés ────────────────────────────────────────────────────────
 *
 * LIVE_CREATE         : Planifier un nouveau live (ROLE_ADMIN uniquement en V1)
 * LIVE_EDIT           : Modifier un live (l'hôte ou un admin)
 * LIVE_CANCEL         : Annuler un live (l'hôte ou un admin)
 * LIVE_REGISTER       : S'inscrire à un live (tout ROLE_USER)
 * LIVE_UPLOAD_REPLAY  : Renseigner le lien replay (l'hôte ou un admin)
 * LIVE_VIEW           : Voir le détail d'un live (tout ROLE_USER)
 * LIVE_MANAGE         : Gérer le live dans l'admin (ROLE_ADMIN uniquement)
 *
 * ─── Convention de subject ───────────────────────────────────────────────────
 *
 * - LIVE_CREATE et LIVE_REGISTER n'ont pas de sujet → passer null
 * - Tous les autres attributs reçoivent une instance de Live
 *
 * @extends Voter<string, Live|null>
 */
class LiveVoter extends Voter
{
    // ─── Constantes des attributs gérés ──────────────────────────────────────

    /** Planifier / créer un nouveau live (ROLE_ADMIN en V1) */
    public const string LIVE_CREATE = 'LIVE_CREATE';

    /**
     * Modifier les informations d'un live planifié.
     * Réservé à l'hôte du live ou à un admin.
     */
    public const string LIVE_EDIT = 'LIVE_EDIT';

    /**
     * Annuler un live planifié.
     * Réservé à l'hôte du live ou à un admin.
     */
    public const string LIVE_CANCEL = 'LIVE_CANCEL';

    /**
     * S'inscrire à un live planifié pour recevoir un rappel email.
     * Tout utilisateur connecté peut s'inscrire.
     */
    public const string LIVE_REGISTER = 'LIVE_REGISTER';

    /**
     * Renseigner l'URL du replay d'un live terminé.
     * Réservé à l'hôte du live ou à un admin.
     * En V1 : lien externe (YouTube VOD, Twitch). V2 : upload Bunny Stream.
     */
    public const string LIVE_UPLOAD_REPLAY = 'LIVE_UPLOAD_REPLAY';

    /**
     * Voir le détail d'un live.
     * Tout utilisateur connecté peut voir un live.
     */
    public const string LIVE_VIEW = 'LIVE_VIEW';

    /**
     * Accès au dashboard d'administration des lives.
     * Réservé aux administrateurs.
     */
    public const string LIVE_MANAGE = 'LIVE_MANAGE';

    /**
     * Le service Security est injecté pour utiliser isGranted() à la place de getRoles().
     * isGranted() respecte la role_hierarchy de security.yaml (ROLE_ADMIN hérite
     * de tous les rôles), contrairement à getRoles() qui retourne uniquement
     * les rôles bruts stockés en BDD.
     */
    public function __construct(private readonly Security $security) {}

    /** Liste complète des attributs gérés par ce voter */
    private const array SUPPORTED_ATTRIBUTES = [
        self::LIVE_CREATE,
        self::LIVE_EDIT,
        self::LIVE_CANCEL,
        self::LIVE_REGISTER,
        self::LIVE_UPLOAD_REPLAY,
        self::LIVE_VIEW,
        self::LIVE_MANAGE,
    ];

    /**
     * Indique à Symfony si ce voter doit être consulté pour la combinaison
     * (attribut, sujet) donnée.
     *
     * - Les attributs LIVE_* sont toujours gérés ici.
     * - Le sujet doit être une instance de Live ou null (pour CREATE/REGISTER).
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        // On n'accepte que les attributs du module Live
        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)) {
            return false;
        }

        // LIVE_CREATE et LIVE_REGISTER n'ont pas de sujet
        if (in_array($attribute, [self::LIVE_CREATE, self::LIVE_REGISTER, self::LIVE_MANAGE], strict: true)) {
            return true;
        }

        // Pour tous les autres attributs, le sujet doit être une instance de Live
        return $subject instanceof Live;
    }

    /**
     * Logique de décision principale.
     *
     * @param string     $attribute L'attribut demandé (LIVE_*)
     * @param Live|null  $subject   Le live concerné (ou null pour CREATE/REGISTER)
     * @param TokenInterface $token Le token de sécurité Symfony
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Refuse si l'utilisateur n'est pas authentifié
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::LIVE_CREATE        => $this->canCreate($user),
            self::LIVE_EDIT          => $this->canEdit($user, $subject),
            self::LIVE_CANCEL        => $this->canCancel($user, $subject),
            self::LIVE_REGISTER      => $this->canRegister($user),
            self::LIVE_UPLOAD_REPLAY => $this->canUploadReplay($user, $subject),
            self::LIVE_VIEW          => $this->canView($user),
            self::LIVE_MANAGE        => $this->canManage($user),
            default                  => false,
        };
    }

    // ─── Méthodes de décision ─────────────────────────────────────────────────

    /**
     * En V1, seuls les admins peuvent créer un live via le dashboard admin.
     *
     * Note CDC : en V2, l'hôte (ROLE_ARTIST) pourra créer ses propres lives.
     * Pour l'instant, tous les lives sont créés et managés par l'équipe Bazaart.
     */
    private function canCreate(User $user): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    /**
     * Peut modifier un live :
     *   - L'hôte du live (celui qui l'anime)
     *   - Un administrateur
     */
    private function canEdit(User $user, ?Live $live): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // L'hôte peut modifier son propre live
        return $live !== null && $live->getHostedBy() === $user;
    }

    /**
     * Peut annuler un live :
     *   - L'hôte du live
     *   - Un administrateur
     */
    private function canCancel(User $user, ?Live $live): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $live !== null && $live->getHostedBy() === $user;
    }

    /**
     * Tout utilisateur connecté peut s'inscrire à un live pour recevoir un rappel.
     * (Le LiveService vérifie en plus que le live est SCHEDULED.)
     */
    private function canRegister(User $user): bool
    {
        // Tout ROLE_USER peut s'inscrire — isGranted respecte la role_hierarchy
        return $this->security->isGranted('ROLE_USER');
    }

    /**
     * Peut renseigner le lien replay après un live :
     *   - L'hôte du live
     *   - Un administrateur
     */
    private function canUploadReplay(User $user, ?Live $live): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $live !== null && $live->getHostedBy() === $user;
    }

    /**
     * Tout utilisateur connecté peut voir les détails d'un live.
     * (Les pages lives sont dans la zone authentifiée /lives.)
     */
    private function canView(User $user): bool
    {
        return $this->security->isGranted('ROLE_USER');
    }

    /**
     * Accès au dashboard admin des lives.
     * Réservé aux administrateurs Bazaart.
     */
    private function canManage(User $user): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }
}

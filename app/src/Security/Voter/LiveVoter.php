<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * LiveVoter — gère les autorisations liées aux lives planifiés.
 *
 * L'entité `Live` n'existe pas encore en BDD (elle sera créée en semaine J3–J5
 * selon le planning V1). Ce voter est créé en avance pour éviter de disperser
 * la logique d'autorisation dans les controllers quand les entités seront prêtes.
 *
 * ─── Stratégie pour l'entité inexistante ─────────────────────────────────────
 *
 * Deux options étaient possibles :
 *
 *   A) Filtrer sur une interface (ex: HasHostInterface) que Live implémentera.
 *      Propre, mais crée une dépendance qu'on ne peut pas honorer maintenant.
 *
 *   B) Filtrer sur le nom de classe en chaîne — pas recommandé (couplage fragile).
 *
 *   C) Accepter `object|null` et vérifier `method_exists('getHost')`.
 *      ✅ Option retenue : pragmatique, safe, et facile à upgrader vers une
 *      interface typée dès que l'entité Live existera.
 *
 * ─── Upgrade path vers l'entité Live ─────────────────────────────────────────
 *
 * Quand l'entité App\Entity\Live sera créée avec la propriété $host (User),
 * mettre à jour ce voter ainsi :
 *
 *   1. Ajouter `use App\Entity\Live;` en import.
 *   2. Changer la signature : `@extends Voter<string, Live|null>`.
 *   3. Dans supports() : vérifier `$subject instanceof Live` (+ null pour CREATE).
 *   4. Remplacer `method_exists($subject, 'getHost')` par `$subject->getHost()`.
 *
 * @extends Voter<string, object|null>
 */
class LiveVoter extends Voter
{
    // ─── Constantes des attributs gérés ──────────────────────────────────────

    /**
     * Planifier / créer un nouveau live.
     * Tout utilisateur connecté peut planifier un live (lien externe V1).
     */
    public const string LIVE_CREATE = 'LIVE_CREATE';

    /**
     * Modifier les informations d'un live planifié (titre, date, lien...).
     * Réservé à l'hôte du live ou à un admin.
     */
    public const string LIVE_EDIT = 'LIVE_EDIT';

    /**
     * Annuler un live planifié.
     * Réservé à l'hôte du live ou à un admin.
     */
    public const string LIVE_CANCEL = 'LIVE_CANCEL';

    /**
     * S'inscrire à un live planifié pour recevoir un rappel.
     * Tout utilisateur connecté peut s'inscrire.
     */
    public const string LIVE_REGISTER = 'LIVE_REGISTER';

    /**
     * Uploader le replay d'un live terminé.
     * Réservé à l'hôte du live ou à un admin.
     * En V1, le replay est un lien externe (YouTube, Twitch) — pas d'upload réel.
     */
    public const string LIVE_UPLOAD_REPLAY = 'LIVE_UPLOAD_REPLAY';

    /**
     * Le service Security est injecté pour utiliser isGranted() à la place de getRoles().
     * isGranted() respecte la role_hierarchy de security.yaml (ROLE_ADMIN hérite
     * de tous les rôles), contrairement à getRoles() qui retourne uniquement
     * les rôles bruts stockés en BDD.
     */
    public function __construct(private readonly Security $security) {}

    // Liste complète des attributs gérés
    private const array SUPPORTED_ATTRIBUTES = [
        self::LIVE_CREATE,
        self::LIVE_EDIT,
        self::LIVE_CANCEL,
        self::LIVE_REGISTER,
        self::LIVE_UPLOAD_REPLAY,
    ];

    /**
     * Accepte tous les attributs LIVE_* pour n'importe quel sujet (objet ou null).
     * LIVE_CREATE et LIVE_REGISTER n'ont pas de sujet (on ne cible pas un live).
     * Les autres attributs ciblent un live existant (objet).
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true);
    }

    /**
     * Logique de décision principale.
     *
     * @param string         $attribute L'attribut demandé
     * @param object|null    $subject   Le live concerné (ou null pour CREATE/REGISTER)
     * @param TokenInterface $token     Le token de sécurité Symfony
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
            default                  => false,
        };
    }

    // ─── Méthodes de décision ─────────────────────────────────────────────────

    /**
     * Tout utilisateur connecté peut planifier un live.
     * (En V1, un live est simplement un lien vers un stream externe.)
     */
    private function canCreate(User $user): bool
    {
        return true;
    }

    /**
     * Peut modifier un live :
     *   - L'hôte du live (celui qui l'a créé)
     *   - Un administrateur
     *
     * On utilise method_exists() car l'entité Live n'existe pas encore.
     * L'entité devra avoir une méthode getHost(): User.
     */
    private function canEdit(User $user, mixed $subject): bool
    {
        // Un admin peut tout modifier (isGranted respecte la role_hierarchy)
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // L'hôte peut modifier son propre live
        // (utilise getHost() de façon dynamique pour compatibilité future)
        if ($subject !== null && method_exists($subject, 'getHost')) {
            /** @var object $subject */
            return $subject->getHost() === $user;
        }

        return false;
    }

    /**
     * Peut annuler un live :
     *   - L'hôte du live
     *   - Un administrateur
     */
    private function canCancel(User $user, mixed $subject): bool
    {
        // Un admin peut annuler n'importe quel live (isGranted respecte la role_hierarchy)
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // L'hôte peut annuler son propre live
        if ($subject !== null && method_exists($subject, 'getHost')) {
            /** @var object $subject */
            return $subject->getHost() === $user;
        }

        return false;
    }

    /**
     * Tout utilisateur connecté peut s'inscrire à un live pour recevoir un rappel.
     */
    private function canRegister(User $user): bool
    {
        return true;
    }

    /**
     * Peut renseigner le lien replay après un live :
     *   - L'hôte du live (lui seul connaît le lien de son replay)
     *   - Un administrateur
     *
     * Note V1 : "uploader le replay" signifie renseigner une URL externe
     * (YouTube, Twitch VOD...). Le vrai upload sera une feature V2 avec Bunny Stream.
     */
    private function canUploadReplay(User $user, mixed $subject): bool
    {
        // Un admin peut renseigner le replay de n'importe quel live (isGranted respecte la role_hierarchy)
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Seul l'hôte peut renseigner le replay de son propre live
        if ($subject !== null && method_exists($subject, 'getHost')) {
            /** @var object $subject */
            return $subject->getHost() === $user;
        }

        return false;
    }
}

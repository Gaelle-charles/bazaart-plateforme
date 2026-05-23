<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Resource;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ResourceVoter — gère toutes les autorisations liées aux ressources (opportunités).
 *
 * Convention Symfony : un Voter étend la classe abstraite `Voter<TAttribute, TSubject>`.
 * Symfony l'instancie automatiquement (pas besoin de le déclarer dans services.yaml
 * grâce à l'autowiring + autoconfigure).
 *
 * Utilisation dans un controller ou un service :
 *   $this->denyAccessUnlessGranted(ResourceVoter::RESOURCE_EDIT, $resource);
 *   // ou :
 *   if ($this->isGranted(ResourceVoter::RESOURCE_PUBLISH, $resource)) { ... }
 *
 * @extends Voter<string, Resource|null>
 */
class ResourceVoter extends Voter
{
    // ─── Constantes des attributs gérés par ce voter ─────────────────────────
    //
    // Déclarer les attributs comme constantes plutôt que des chaînes brutes
    // permet l'autocomplétion dans les IDEs et évite les fautes de frappe.

    /** Modifier une ressource existante (titre, description, etc.) */
    public const string RESOURCE_EDIT = 'RESOURCE_EDIT';

    /** Supprimer définitivement une ressource */
    public const string RESOURCE_DELETE = 'RESOURCE_DELETE';

    /** Valider ou rejeter une soumission (passage en Published / Rejected) */
    public const string RESOURCE_PUBLISH = 'RESOURCE_PUBLISH';

    /** Soumettre une nouvelle ressource (tout utilisateur connecté peut le faire) */
    public const string RESOURCE_SUBMIT = 'RESOURCE_SUBMIT';

    /**
     * Gérer ses propres ressources depuis un dashboard.
     * Accessible à l'auteur de la ressource (et aux admins qui voient tout).
     */
    public const string RESOURCE_MANAGE_OWN = 'RESOURCE_MANAGE_OWN';

    /**
     * Le service Security est injecté pour utiliser isGranted() à la place de
     * $user->getRoles(). isGranted() respecte la role_hierarchy de security.yaml,
     * contrairement à getRoles() qui retourne uniquement les rôles bruts stockés en BDD.
     *
     * Exemple : un ROLE_ADMIN hérite de ROLE_STRUCTURE via la hiérarchie.
     * getRoles() retournerait ["ROLE_ADMIN", "ROLE_USER"] — in_array('ROLE_ADMIN') passerait,
     * mais si demain on restructure la hiérarchie, ce code ne nécessite aucune modification.
     */
    public function __construct(private readonly Security $security) {}

    // ─── Liste de tous les attributs supportés ────────────────────────────────
    private const array SUPPORTED_ATTRIBUTES = [
        self::RESOURCE_EDIT,
        self::RESOURCE_DELETE,
        self::RESOURCE_PUBLISH,
        self::RESOURCE_SUBMIT,
        self::RESOURCE_MANAGE_OWN,
    ];

    /**
     * Symfony appelle `supports()` pour chaque voter à chaque appel `isGranted()`.
     * Si cette méthode retourne false, `voteOnAttribute()` n'est jamais appelé.
     *
     * On vérifie deux choses :
     *   1. L'attribut demandé fait partie de ceux que ce voter gère.
     *   2. Le sujet est du bon type (Resource ou null pour RESOURCE_SUBMIT).
     *
     * @param string $attribute L'attribut testé (ex: 'RESOURCE_EDIT')
     * @param mixed  $subject   Le sujet de la vérification (ici une Resource ou null)
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        // On ne traite que les attributs déclarés dans ce voter
        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)) {
            return false;
        }

        // RESOURCE_SUBMIT ne nécessite pas de ressource existante (on crée de zéro)
        if ($attribute === self::RESOURCE_SUBMIT) {
            return true;
        }

        // Pour tous les autres attributs, le sujet doit être une Resource
        return $subject instanceof Resource;
    }

    /**
     * Logique de décision principale.
     *
     * Cette méthode est appelée uniquement si `supports()` retourne true.
     * Elle doit retourner true si l'accès est accordé, false sinon.
     *
     * @param string         $attribute L'attribut demandé
     * @param Resource|null  $subject   La ressource concernée (peut être null pour SUBMIT)
     * @param TokenInterface $token     Le token de sécurité contenant l'utilisateur connecté
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Récupère l'utilisateur connecté depuis le token Symfony
        $user = $token->getUser();

        // Si l'utilisateur n'est pas connecté (anonymous), on refuse tout
        if (!$user instanceof User) {
            return false;
        }

        // Délègue selon l'attribut demandé
        return match ($attribute) {
            self::RESOURCE_SUBMIT      => $this->canSubmit($user),
            self::RESOURCE_EDIT        => $this->canEdit($user, $subject),       // @phpstan-ignore-line ($subject est garanti Resource par supports())
            self::RESOURCE_DELETE      => $this->canDelete($user, $subject),     // @phpstan-ignore-line
            self::RESOURCE_PUBLISH     => $this->canPublish($user),
            self::RESOURCE_MANAGE_OWN  => $this->canManageOwn($user, $subject), // @phpstan-ignore-line
            default                    => false,
        };
    }

    // ─── Méthodes de décision ─────────────────────────────────────────────────

    /**
     * Tout utilisateur connecté peut soumettre une ressource.
     * La restriction supplémentaire (structure vs artiste) est gérée par le service
     * de soumission, qui détermine si la ressource sera auto-publiée ou non.
     */
    private function canSubmit(User $user): bool
    {
        // Il suffit d'être authentifié (ROLE_USER est implicite car $user est non-null)
        return true;
    }

    /**
     * Peut modifier une ressource :
     *   - L'auteur (celui qui a soumis la ressource)
     *   - Un administrateur (ROLE_ADMIN)
     */
    private function canEdit(User $user, Resource $resource): bool
    {
        // L'auteur peut toujours modifier sa propre ressource
        if ($resource->getSubmittedBy() === $user) {
            return true;
        }

        // Un admin a le droit de modifier n'importe quelle ressource.
        // isGranted() est préféré à in_array($user->getRoles()) car il respecte
        // la role_hierarchy — pérenne si la hiérarchie évolue.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return false;
    }

    /**
     * Peut supprimer une ressource :
     *   - L'auteur
     *   - Un administrateur
     *
     * Note : on pourrait vouloir empêcher l'auteur de supprimer une ressource
     * déjà publiée (pour préserver la cohérence). Ce cas métier sera géré
     * dans le service ResourceDeletionService si nécessaire — ici on reste permissif.
     */
    private function canDelete(User $user, Resource $resource): bool
    {
        // L'auteur peut supprimer sa propre ressource
        if ($resource->getSubmittedBy() === $user) {
            return true;
        }

        // Seul un admin peut supprimer une ressource qui ne lui appartient pas
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return false;
    }

    /**
     * Peut valider ou rejeter une soumission :
     *   - Uniquement un administrateur (ROLE_ADMIN)
     *
     * Les structures publient en auto-publication — elles n'ont pas besoin de
     * valider les ressources des autres. Ce privilège est réservé à l'équipe Bazaart.
     */
    private function canPublish(User $user): bool
    {
        // Seul un admin peut valider/rejeter une soumission artiste
        return $this->security->isGranted('ROLE_ADMIN');
    }

    /**
     * Peut gérer une ressource depuis son dashboard personnel :
     *   - L'auteur de la ressource
     *   - Un administrateur (qui a accès à tout)
     *
     * Note V1 : la vérification ROLE_STRUCTURE n'est pas faite ici — le titre
     * "canManageOwn" cible la ressource dont l'utilisateur est l'auteur.
     * Pour les accès structure, c'est le StructureVoter qui fait foi.
     */
    private function canManageOwn(User $user, Resource $resource): bool
    {
        // L'auteur accède toujours à ses propres ressources
        if ($resource->getSubmittedBy() === $user) {
            return true;
        }

        // Un admin peut gérer toutes les ressources, même celles des autres
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return false;
    }
}

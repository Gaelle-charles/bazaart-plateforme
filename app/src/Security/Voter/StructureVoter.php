<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * StructureVoter — gère les autorisations spécifiques aux comptes Structure.
 *
 * Un compte Structure est un OrganizationProfile dont isStructurePartner = true,
 * validé par un admin. L'utilisateur associé a le rôle ROLE_STRUCTURE en BDD.
 *
 * Ce voter va plus loin que le simple contrôle de rôle de security.yaml :
 * il vérifie aussi que isStructurePartner = true sur l'OrganizationProfile.
 * Pourquoi ? Pour se prémunir d'une incohérence éventuelle entre le rôle
 * en BDD et le flag isStructurePartner (ex: un admin a ajouté ROLE_STRUCTURE
 * manuellement sans activer l'OrganizationProfile).
 *
 * Exemple d'utilisation :
 *   $this->denyAccessUnlessGranted(StructureVoter::STRUCTURE_VIEW_DASHBOARD);
 *   $this->denyAccessUnlessGranted(StructureVoter::STRUCTURE_ACTIVATE, $orgProfile);
 *
 * @extends Voter<string, object|null>
 */
class StructureVoter extends Voter
{
    // ─── Constantes des attributs gérés ──────────────────────────────────────

    /**
     * Accéder au tableau de bord structure.
     * Requiert : ROLE_STRUCTURE + isStructurePartner = true.
     */
    public const string STRUCTURE_VIEW_DASHBOARD = 'STRUCTURE_VIEW_DASHBOARD';

    /**
     * Publier une ressource en auto-publication (sans validation admin).
     * Requiert : ROLE_STRUCTURE + isStructurePartner = true.
     */
    public const string STRUCTURE_PUBLISH_RESOURCE = 'STRUCTURE_PUBLISH_RESOURCE';

    /**
     * Activer un compte Structure (approuver une demande d'inscription).
     * Requiert : ROLE_ADMIN uniquement.
     * Le sujet est l'OrganizationProfile à activer.
     */
    public const string STRUCTURE_ACTIVATE = 'STRUCTURE_ACTIVATE';

    /**
     * S'inscrire comme structure partenaire.
     * Requiert : ROLE_USER (tout utilisateur connecté).
     */
    public const string STRUCTURE_REGISTER = 'STRUCTURE_REGISTER';

    /**
     * Le service Security de Symfony est injecté ici plutôt que d'utiliser
     * $user->getRoles() directement. Pourquoi ?
     *
     * $user->getRoles() retourne seulement les rôles bruts stockés en BDD (+ ROLE_USER).
     * Elle NE propage PAS la role_hierarchy définie dans security.yaml.
     *
     * Exemple concret : un admin a ["ROLE_ADMIN"] en BDD. Symfony sait (via la
     * hiérarchie) que ROLE_ADMIN hérite de ROLE_STRUCTURE. Mais getRoles() retourne
     * uniquement ["ROLE_ADMIN", "ROLE_USER"]. Un in_array('ROLE_STRUCTURE', ...) échoue.
     *
     * $this->security->isGranted('ROLE_STRUCTURE') utilise le token + le RoleHierarchyVoter
     * de Symfony, ce qui propage correctement la hiérarchie complète.
     */
    public function __construct(private readonly Security $security) {}

    // Liste complète des attributs gérés par ce voter
    private const array SUPPORTED_ATTRIBUTES = [
        self::STRUCTURE_VIEW_DASHBOARD,
        self::STRUCTURE_PUBLISH_RESOURCE,
        self::STRUCTURE_ACTIVATE,
        self::STRUCTURE_REGISTER,
    ];

    /**
     * Ce voter accepte tous les attributs STRUCTURE_* pour n'importe quel sujet.
     * La vérification du type du sujet est faite dans chaque méthode de décision.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true);
    }

    /**
     * Logique de décision principale.
     *
     * @param string         $attribute L'attribut demandé
     * @param object|null    $subject   L'OrganizationProfile concerné (ou null)
     * @param TokenInterface $token     Le token de sécurité Symfony
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Refuse systématiquement si l'utilisateur n'est pas connecté
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::STRUCTURE_VIEW_DASHBOARD    => $this->canViewDashboard($user),
            self::STRUCTURE_PUBLISH_RESOURCE  => $this->canPublishResource($user),
            self::STRUCTURE_ACTIVATE          => $this->canActivate($user),
            self::STRUCTURE_REGISTER          => $this->canRegister($user),
            default                           => false,
        };
    }

    // ─── Méthodes de décision ─────────────────────────────────────────────────

    /**
     * Peut accéder au tableau de bord structure :
     *   - L'utilisateur doit avoir ROLE_STRUCTURE (en tenant compte de la hiérarchie)
     *   - ET son OrganizationProfile doit avoir isStructurePartner = true
     *
     * La double vérification (rôle + flag BDD) protège contre les incohérences.
     * Dans le workflow normal, les deux sont mis à jour ensemble par StructureActivationService.
     *
     * Pourquoi isGranted() et pas in_array(getRoles()) ?
     * Un admin (["ROLE_ADMIN"] en BDD) hérite de ROLE_STRUCTURE via security.yaml.
     * Seul isGranted() propage cette hiérarchie — getRoles() ne le fait pas.
     */
    private function canViewDashboard(User $user): bool
    {
        // isGranted() utilise le token Symfony + la role_hierarchy complète.
        // Un ROLE_ADMIN hérite de ROLE_STRUCTURE → il passe ce test.
        if (!$this->security->isGranted('ROLE_STRUCTURE')) {
            return false;
        }

        // Vérification du flag isStructurePartner sur l'OrganizationProfile.
        // L'OrganizationProfile peut ne pas encore exister (l'utilisateur peut
        // avoir le rôle mais pas encore de profil — cas de migration ou bug).
        $orgProfile = $user->getOrganizationProfile();
        if ($orgProfile === null) {
            return false;
        }

        return $orgProfile->isStructurePartner();
    }

    /**
     * Peut publier une ressource en auto-publication :
     *   Même condition que canViewDashboard() : ROLE_STRUCTURE + isStructurePartner.
     *
     * L'auto-publication signifie que la ressource passe directement en Published
     * sans passer par la file de validation admin.
     */
    private function canPublishResource(User $user): bool
    {
        // On réutilise la même logique que pour le dashboard
        return $this->canViewDashboard($user);
    }

    /**
     * Peut activer un compte Structure (valider une demande d'inscription) :
     *   - Uniquement un administrateur (ROLE_ADMIN)
     *
     * C'est un acte irréversible (ou en tout cas significatif) : on donne à une
     * organisation le droit de publier sans modération. Seule l'équipe Bazaart
     * peut prendre cette décision.
     */
    private function canActivate(User $user): bool
    {
        // isGranted() plutôt que getRoles() — cohérence avec la role_hierarchy.
        // Même si ROLE_ADMIN est toujours stocké directement en BDD aujourd'hui,
        // cette écriture est pérenne et résistante à d'éventuels changements de hiérarchie.
        return $this->security->isGranted('ROLE_ADMIN');
    }

    /**
     * Peut s'inscrire comme structure partenaire :
     *   - Tout utilisateur connecté (ROLE_USER)
     *
     * L'inscription crée une demande en attente — un admin devra ensuite valider
     * via STRUCTURE_ACTIVATE. Tout utilisateur authentifié peut déposer une demande.
     */
    private function canRegister(User $user): bool
    {
        // Tout utilisateur authentifié peut demander à devenir structure
        return true;
    }
}

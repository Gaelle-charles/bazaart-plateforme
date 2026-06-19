<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * MatchingVoter — Autorisations pour l'accès au moteur de matching (ADR-0021, Lot B).
 *
 * Ce voter centralise la règle d'accès : seuls les artistes connectés (ROLE_ARTIST)
 * peuvent accéder à leurs matchs. Les admins y ont également accès (par hiérarchie).
 *
 * POURQUOI UN VOTER plutôt qu'un attribut #[IsGranted] dans le controller ?
 *   1. Lisibilité : la règle est dans un fichier dédié, pas disséminée dans les controllers.
 *   2. Évolutivité : si demain on ajoute une condition (ex: "artiste ET abonné"),
 *      on modifie ce voter sans toucher au controller.
 *   3. Testabilité : le voter peut être testé indépendamment du controller.
 *   4. Convention Bazaart (cf. CLAUDE.md §7) : toujours des Voters, jamais de checks
 *      inline de rôles (pas de $user->getRoles() ou in_array('ROLE_ARTIST')).
 *
 * UTILISATION dans le controller :
 *   $this->denyAccessUnlessGranted(MatchingVoter::MATCHING_VIEW);
 *
 * @extends Voter<string, null>
 */
final class MatchingVoter extends Voter
{
    /**
     * Attribut pour consulter ses matchs.
     * Sujet = null (on ne vote pas sur une entité particulière, juste sur l'action).
     */
    public const string MATCHING_VIEW = 'MATCHING_VIEW';

    /**
     * Security est injecté pour utiliser isGranted() avec la role_hierarchy.
     * Rappel : isGranted('ROLE_ARTIST') respecte la hiérarchie définie dans security.yaml,
     * contrairement à in_array('ROLE_ARTIST', $user->getRoles()) qui ne la respecte pas.
     */
    public function __construct(private readonly Security $security) {}

    /**
     * Ce voter gère uniquement l'attribut MATCHING_VIEW, sans sujet spécifique.
     * Le sujet est null (pas d'entité à vérifier — on vérifie juste le rôle de l'utilisateur).
     *
     * @param string $attribute Attribut demandé
     * @param mixed  $subject   Toujours null pour ce voter
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MATCHING_VIEW;
    }

    /**
     * Accorde l'accès si l'utilisateur est un artiste connecté.
     * Les admins héritent de tous les rôles via la hiérarchie → ont aussi accès.
     *
     * @param string         $attribute MATCHING_VIEW
     * @param null           $subject   Non utilisé
     * @param TokenInterface $token     Token de sécurité Symfony
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // L'utilisateur doit être connecté (instance de notre classe User)
        $user = $token->getUser();
        if (!$user instanceof User) {
            // Non connecté → accès refusé
            return false;
        }

        // Seuls les artistes (ROLE_ARTIST) peuvent consulter leurs matchs.
        // isGranted() respecte la role_hierarchy → ROLE_ADMIN a aussi accès.
        return $this->security->isGranted('ROLE_ARTIST');
    }
}

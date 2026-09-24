<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Project;
use App\Entity\ProjectNote;
use App\Entity\ProjectTaskComment;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ProjectVoter — autorisations de l'Espace projets (ADR-0037).
 *
 * ─── Attributs ──────────────────────────────────────────────────────────────
 *
 * ACCESS          (sans sujet)          : entrer dans l'Espace projets → ROLE_PROJECT
 * NOTE_EDIT       (ProjectNote)         : modifier / supprimer une note → son autrice uniquement
 * COMMENT_DELETE  (ProjectTaskComment)  : supprimer un commentaire → son autrice uniquement
 * PROJECT_DELETE  (Project)             : supprimer définitivement un projet →
 *                                         créatrice, responsable ou ROLE_ADMIN
 *
 * POURQUOI Security::isGranted('ROLE_PROJECT') et pas $user->getRoles() ?
 *   isGranted() passe par le RoleHierarchyVoter de Symfony : c'est LA façon
 *   fiable de tester un rôle (cf. mémoire relecteur « getRoles() vs isGranted() »).
 *
 * Le reste (créer/modifier des tâches, épingler une note…) est ouvert à toute
 * l'équipe : 3 personnes qui se font confiance, l'outil ne doit pas les freiner.
 *
 * @extends Voter<string, mixed>
 */
class ProjectVoter extends Voter
{
    public const string ACCESS         = 'PROJECT_SPACE_ACCESS';
    public const string NOTE_EDIT      = 'PROJECT_NOTE_EDIT';
    public const string COMMENT_DELETE = 'PROJECT_COMMENT_DELETE';
    public const string PROJECT_DELETE = 'PROJECT_DELETE';

    public function __construct(
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::ACCESS         => true,
            self::NOTE_EDIT      => $subject instanceof ProjectNote,
            self::COMMENT_DELETE => $subject instanceof ProjectTaskComment,
            self::PROJECT_DELETE => $subject instanceof Project,
            default              => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Pré-requis commun à TOUS les attributs : être membre de l'Espace projets.
        if (!$this->security->isGranted('ROLE_PROJECT')) {
            return false;
        }

        return match ($attribute) {
            self::ACCESS         => true,
            self::NOTE_EDIT      => $subject instanceof ProjectNote && $subject->isAuthoredBy($user),
            self::COMMENT_DELETE => $subject instanceof ProjectTaskComment
                && $subject->getAuthor()?->getId() === $user->getId(),
            self::PROJECT_DELETE => $subject instanceof Project && (
                $subject->getCreatedBy()?->getId() === $user->getId()
                || $subject->getOwner()?->getId() === $user->getId()
                || $this->security->isGranted('ROLE_ADMIN')
            ),
            default              => false,
        };
    }
}

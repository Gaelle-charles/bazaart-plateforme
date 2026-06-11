<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Retrouve un utilisateur par le hash SHA-256 de son token de réinitialisation.
     *
     * Cette méthode est appelée par PasswordResetService::validateToken() et
     * PasswordResetService::resetPassword() pour vérifier la validité d'un token.
     *
     * Pourquoi chercher par hash et pas par token en clair ?
     *   → Le token en clair n'est jamais stocké en BDD (sécurité).
     *   → On calcule hash('sha256', $token) côté service AVANT d'interroger la BDD.
     *   → L'index idx_users_reset_token_hash sur cette colonne rend la requête rapide.
     *
     * @param string $tokenHash Le hash SHA-256 du token (64 chars hexa)
     * @return User|null null si aucun utilisateur ne correspond à ce hash
     */
    public function findByResetTokenHash(string $tokenHash): ?User
    {
        // Recherche directe sur le hash — findOneBy utilise l'index automatiquement
        return $this->findOneBy(['resetTokenHash' => $tokenHash]);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RegisterDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * Inscrit un nouvel utilisateur.
     * Retourne l'entité créée ou null si l'email est déjà utilisé.
     */
    public function register(RegisterDTO $dto): ?User
    {
        // Vérifie si l'email est déjà pris
        if ($this->userRepository->findOneBy(['email' => $dto->email]) !== null) {
            return null;
        }

        $user = new User();
        $user->setEmail($dto->email);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $dto->password)
        );

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Vérifie les identifiants et retourne l'utilisateur ou null.
     */
    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);

        if ($user === null) {
            return null;
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return null;
        }

        return $user;
    }
}

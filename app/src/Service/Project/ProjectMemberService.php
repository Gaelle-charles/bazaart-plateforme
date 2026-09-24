<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\ProjectMemberProfile;
use App\Entity\User;
use App\Repository\ProjectMemberProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ProjectMemberService — les membres de l'Espace projets et leurs profils (ADR-0037).
 *
 * Qui est membre ? Toute personne dont users.roles contient ROLE_PROJECT.
 * (Aujourd'hui : Mllebelamour@gmail.com, zahibowendie@gmail.com, hello@gaellecode.fr.)
 *
 * Ce service centralise :
 *   - la liste des membres (pour les assignations, la vue « Par personne »…) ;
 *   - le profil de préférences (couleur d'avatar, onboarding) créé à la volée ;
 *   - l'attribution / le retrait de l'accès (utilisé par la commande app:projets:acces).
 */
class ProjectMemberService
{
    public const string ROLE = 'ROLE_PROJECT';

    /**
     * Couleurs d'avatar attribuées dans l'ordre d'arrivée des membres.
     * Assez contrastées entre elles pour distinguer 3 à 6 personnes d'un coup d'œil.
     */
    public const array MEMBER_COLORS = ['#FF6B2C', '#3B82F6', '#1F8A5B', '#8B5CF6', '#E5484D', '#0EA5A4'];

    /** Cache mémoire pour la durée de la requête HTTP (la liste est lue plusieurs fois par page). */
    /** @var list<User>|null */
    private ?array $membersCache = null;

    /** @var array<int, ProjectMemberProfile> */
    private array $profilesCache = [];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ProjectMemberProfileRepository $profileRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * Membres de l'équipe projets, triés par prénom.
     *
     * @return list<User>
     */
    public function getMembers(): array
    {
        return $this->membersCache ??= $this->userRepository->findByRole(self::ROLE);
    }

    /** Retrouve une membre par son ID, ou null si l'ID ne correspond pas à une membre. */
    public function findMember(int $userId): ?User
    {
        foreach ($this->getMembers() as $member) {
            if ($member->getId() === $userId) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Profil de préférences d'une membre (créé et enregistré s'il n'existe pas).
     */
    public function getProfile(User $user): ProjectMemberProfile
    {
        $userId = (int) $user->getId();
        if (isset($this->profilesCache[$userId])) {
            return $this->profilesCache[$userId];
        }

        $profile = $this->profileRepository->findOneBy(['user' => $user]);
        if ($profile === null) {
            // Couleur suivante de la palette selon le nombre de profils existants :
            // la 1re membre reçoit l'orange, la 2e le bleu, etc.
            $index   = $this->profileRepository->count([]) % count(self::MEMBER_COLORS);
            $profile = new ProjectMemberProfile($user, self::MEMBER_COLORS[$index]);
            $this->em->persist($profile);
            $this->em->flush();
        }

        return $this->profilesCache[$userId] = $profile;
    }

    /** Enregistre les préférences modifiées (couleur, emails). */
    public function saveProfile(ProjectMemberProfile $profile): void
    {
        $this->em->persist($profile);
        $this->em->flush();
    }

    /**
     * Couleurs d'avatar de toutes les membres, indexées par ID utilisateur.
     * Utilisé par l'extension Twig pour colorer les pastilles sans requête par avatar.
     *
     * @return array<int, string>
     */
    public function getColorMap(): array
    {
        $map = [];
        foreach ($this->getMembers() as $member) {
            $map[(int) $member->getId()] = $this->getProfile($member)->getColor();
        }

        return $map;
    }

    /**
     * Nom affiché : « Prénom Nom » si renseigné, sinon la partie avant @ de l'email
     * (les comptes créés par la commande n'ont pas toujours de prénom).
     */
    public static function displayName(?User $user): string
    {
        if ($user === null) {
            return 'Compte supprimé';
        }

        $fullName = $user->getFullName();
        if ($fullName !== '') {
            return $fullName;
        }

        return ucfirst(explode('@', $user->getEmail())[0]);
    }

    /** Initiales pour les avatars (« Gaëlle Charles » → « GC », « zahibowendie » → « ZA »). */
    public static function initials(?User $user): string
    {
        if ($user === null) {
            return '?';
        }

        $first = trim((string) $user->getFirstName());
        $last  = trim((string) $user->getLastName());
        if ($first !== '') {
            return mb_strtoupper(mb_substr($first, 0, 1) . ($last !== '' ? mb_substr($last, 0, 1) : mb_substr($first, 1, 1)));
        }

        return mb_strtoupper(mb_substr(explode('@', $user->getEmail())[0], 0, 2));
    }

    // ─── Gestion des accès (commande app:projets:acces) ──────────────────────

    /** Recherche insensible à la casse (« Mllebelamour@… » = « mllebelamour@… »). */
    public function findUserByEmail(string $email): ?User
    {
        /** @var User|null $user */
        $user = $this->userRepository->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    /**
     * Ajoute ROLE_PROJECT au compte. Retourne false si l'accès existait déjà.
     *
     * NB : on lit ici le contenu brut des rôles (getRoles()) pour MODIFIER la
     * colonne, pas pour autoriser une action : c'est le seul cas où c'est légitime.
     */
    public function grantAccess(User $user): bool
    {
        $roles = $user->getRoles();
        if (in_array(self::ROLE, $roles, true)) {
            return false;
        }

        // getRoles() ajoute toujours ROLE_USER : on le retire avant d'enregistrer
        // pour ne pas le dupliquer en BDD (il est recalculé à chaque lecture).
        $stored   = array_values(array_diff($roles, ['ROLE_USER']));
        $stored[] = self::ROLE;
        $user->setRoles($stored);
        $this->em->flush();
        $this->membersCache = null;

        return true;
    }

    /** Retire ROLE_PROJECT. Retourne false si le compte n'avait pas l'accès. */
    public function revokeAccess(User $user): bool
    {
        $roles = $user->getRoles();
        if (!in_array(self::ROLE, $roles, true)) {
            return false;
        }

        $user->setRoles(array_values(array_diff($roles, ['ROLE_USER', self::ROLE])));
        $this->em->flush();
        $this->membersCache = null;

        return true;
    }

    /**
     * Crée un compte pour une membre qui n'est pas encore inscrite sur la plateforme.
     *
     * - Mot de passe ALÉATOIRE et inconnu de tous : la personne se connecte avec
     *   « Se connecter avec Google » (adresses Gmail) ou définit son mot de passe
     *   via « Mot de passe oublié ».
     * - Compte vérifié et onboarding artiste marqué comme fait : ce n'est pas une
     *   artiste qui s'inscrit, on ne lui impose pas le parcours d'inscription.
     */
    public function createMemberAccount(string $email): User
    {
        $user = new User();
        $user->setEmail(mb_strtolower(trim($email)));
        $user->setRoles([self::ROLE]);
        $user->setIsVerified(true);
        $user->setOnboardingCompleted(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(24))));

        $this->em->persist($user);
        $this->em->flush();
        $this->membersCache = null;

        return $user;
    }
}

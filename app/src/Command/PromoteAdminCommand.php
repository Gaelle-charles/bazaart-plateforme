<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande pour donner le rôle ROLE_ADMIN à un utilisateur existant.
 *
 * Usage : docker compose exec app php bin/console app:promote-admin email@exemple.com
 *
 * On ne crée pas d'interface web pour cette action car elle est critique
 * et doit rester uniquement accessible via le terminal du serveur.
 */
#[AsCommand(
    name: 'app:promote-admin',
    description: 'Donne le rôle ROLE_ADMIN à un utilisateur existant.',
)]
class PromoteAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email de l\'utilisateur à promouvoir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $user  = $this->userRepository->findOneBy(['email' => $email]);

        if ($user === null) {
            $io->error(sprintf('Aucun utilisateur trouvé avec l\'email "%s".', $email));
            return Command::FAILURE;
        }

        // getRoles() ajoute toujours ROLE_USER automatiquement (voir User::getRoles()),
        // donc on travaille directement sur le tableau stocké en base.
        $roles = $user->getRoles();

        if (in_array('ROLE_ADMIN', $roles, true)) {
            $io->warning(sprintf('L\'utilisateur "%s" est déjà administrateur.', $email));
            return Command::SUCCESS;
        }

        $roles[] = 'ROLE_ADMIN';
        $user->setRoles(array_unique($roles));

        $this->em->flush();

        $io->success(sprintf('L\'utilisateur "%s" est maintenant administrateur.', $email));

        return Command::SUCCESS;
    }
}

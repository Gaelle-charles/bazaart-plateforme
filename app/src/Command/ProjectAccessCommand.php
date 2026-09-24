<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PasswordResetService;
use App\Service\Project\ProjectMemberService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * app:projets:acces — donne (ou retire) l'accès à l'Espace projets (ADR-0037).
 *
 * EXEMPLES (sur le serveur, dans le conteneur PHP) :
 *
 *   # Voir qui a accès
 *   php bin/console app:projets:acces
 *
 *   # Donner l'accès aux 3 membres de l'équipe (crée les comptes manquants)
 *   php bin/console app:projets:acces --creer Mllebelamour@gmail.com zahibowendie@gmail.com g.charlesbel@gmail.com
 *
 *   # Retirer l'accès
 *   php bin/console app:projets:acces --retirer quelquun@exemple.com
 *
 * Comme app:promote-admin, cette action sensible n'a volontairement PAS d'interface
 * web : seule une personne qui a accès au serveur peut ouvrir l'Espace projets à
 * quelqu'un (les notes internes de l'équipe y sont visibles).
 *
 * Contrôleur fin : la logique est dans ProjectMemberService.
 */
#[AsCommand(
    name: 'app:projets:acces',
    description: 'Donne ou retire l\'accès à l\'Espace projets (ROLE_PROJECT) et liste les membres.',
)]
class ProjectAccessCommand extends Command
{
    public function __construct(
        private readonly ProjectMemberService $memberService,
        private readonly PasswordResetService $passwordResetService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('emails', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Adresse(s) email des personnes concernées.')
            ->addOption('creer', null, InputOption::VALUE_NONE, 'Crée le compte s\'il n\'existe pas encore (et envoie un lien pour choisir un mot de passe).')
            ->addOption('retirer', null, InputOption::VALUE_NONE, 'Retire l\'accès au lieu de le donner.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $emails */
        $emails  = $input->getArgument('emails');
        $create  = (bool) $input->getOption('creer');
        $revoke  = (bool) $input->getOption('retirer');
        $failure = false;

        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $io->error(sprintf('« %s » n\'est pas une adresse email valide.', $email));
                $failure = true;
                continue;
            }

            $user = $this->memberService->findUserByEmail($email);

            // ── Retrait d'accès ──────────────────────────────────────────────
            if ($revoke) {
                if ($user === null) {
                    $io->warning(sprintf('%s : aucun compte, rien à retirer.', $email));
                    continue;
                }
                $this->memberService->revokeAccess($user)
                    ? $io->success(sprintf('%s : accès à l\'Espace projets retiré.', $email))
                    : $io->note(sprintf('%s : n\'avait pas accès.', $email));
                continue;
            }

            // ── Compte inexistant ────────────────────────────────────────────
            if ($user === null) {
                if (!$create) {
                    $io->error(sprintf('%s : aucun compte sur la plateforme. Relance avec --creer pour le créer.', $email));
                    $failure = true;
                    continue;
                }

                $user = $this->memberService->createMemberAccount($email);
                // Lien « choisir mon mot de passe » (valable 1 h). Pour une adresse
                // Gmail, « Se connecter avec Google » fonctionne aussi immédiatement.
                $this->passwordResetService->requestReset($user->getEmail());
                $io->success(sprintf(
                    '%s : compte créé avec accès à l\'Espace projets. Un email pour choisir un mot de passe a été envoyé '
                    . '(la personne peut aussi utiliser « Se connecter avec Google »).',
                    $user->getEmail()
                ));
                continue;
            }

            // ── Compte existant ──────────────────────────────────────────────
            $this->memberService->grantAccess($user)
                ? $io->success(sprintf('%s : accès à l\'Espace projets accordé.', $email))
                : $io->note(sprintf('%s : a déjà accès.', $email));
        }

        // ── Récapitulatif ────────────────────────────────────────────────────
        $rows = array_map(
            static fn ($member): array => [ProjectMemberService::displayName($member), $member->getEmail()],
            $this->memberService->getMembers(),
        );
        $io->section('Membres de l\'Espace projets');
        $rows === [] ? $io->text('Aucune membre pour l\'instant.') : $io->table(['Nom', 'Email'], $rows);

        return $failure ? Command::FAILURE : Command::SUCCESS;
    }
}

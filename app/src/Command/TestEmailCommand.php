<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * TestEmailCommand — Envoie un email de test pour vérifier la configuration du mailer.
 *
 * Pourquoi cette commande ?
 *   Le formulaire de contact du site masque les erreurs SMTP (il retourne toujours
 *   un succès à l'utilisateur). Pour diagnostiquer la configuration email en production,
 *   on a besoin d'un test qui AFFICHE l'erreur exacte si l'envoi échoue.
 *
 * Cette commande envoie un vrai email via le MAILER_DSN configuré (Brevo en prod) :
 *   - depuis 'noreply@bazaart.fr' (l'adresse officielle, codée en dur dans tous les services)
 *   - vers le destinataire passé en argument
 *
 * Lancement :
 *   docker compose -f docker-compose.prod.yml exec platform_app php bin/console app:test-email destinataire@exemple.fr
 *
 * En cas de succès → l'email arrive dans la boîte ET apparaît dans Brevo → Journaux.
 * En cas d'échec   → l'erreur SMTP exacte est affichée dans le terminal.
 */
#[AsCommand(
    name: 'app:test-email',
    description: 'Envoie un email de test pour vérifier la configuration du mailer (Brevo).',
)]
final class TestEmailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Le destinataire est obligatoire : on ne veut pas d'adresse par défaut
        // qui enverrait un email surprise à quelqu'un.
        $this->addArgument(
            'destinataire',
            InputArgument::REQUIRED,
            'Adresse email qui recevra le message de test',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $destinataire */
        $destinataire = $input->getArgument('destinataire');

        $io->title('Test d\'envoi email — Bazaart');
        $io->text(sprintf('Expéditeur   : noreply@bazaart.fr'));
        $io->text(sprintf('Destinataire : %s', $destinataire));
        $io->newLine();

        // Construction de l'email de test.
        // On utilise la même adresse expéditrice que les services réels (noreply@bazaart.fr),
        // pour tester exactement la configuration qui sera utilisée en production.
        $email = (new Email())
            ->from(new Address('noreply@bazaart.fr', 'Bazaart'))
            ->to($destinataire)
            ->subject('[Bazaart] Test de configuration email')
            ->text(
                "Ceci est un email de test envoyé depuis la plateforme Bazaart.\n\n".
                "Si vous recevez ce message, la configuration Brevo (SMTP + SPF + DKIM) fonctionne correctement.\n"
            );

        try {
            // L'envoi réel. Si le SMTP refuse (mauvais identifiants, port bloqué, etc.),
            // une TransportExceptionInterface est levée avec le détail de l'erreur.
            $this->mailer->send($email);

            $io->success('Email envoyé sans erreur SMTP. Vérifiez la boîte de réception et Brevo → Journaux.');

            return Command::SUCCESS;
        } catch (TransportExceptionInterface $e) {
            // On affiche le message d'erreur brut du transport SMTP : c'est lui
            // qui indique la cause réelle (auth refusée, host injoignable, etc.).
            $io->error('Échec de l\'envoi SMTP :');
            $io->writeln($e->getMessage());

            return Command::FAILURE;
        }
    }
}

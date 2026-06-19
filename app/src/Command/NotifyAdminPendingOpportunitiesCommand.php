<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ScrapedResourceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * NotifyAdminPendingOpportunitiesCommand
 *
 * Envoie un email d'alerte a l'equipe Bazaart quand le nombre d'opportunites
 * scrapees en attente de validation depasse un seuil configurable.
 *
 * OBJECTIF :
 *   Eviter que le backlog de validation grossisse sans que l'equipe s'en rende compte.
 *   Cette commande est idempotente : elle peut etre lancee chaque jour par un cron
 *   sans effet de bord (elle ne modifie aucune donnee, elle envoie juste un email).
 *
 * IDEMPOTENCE :
 *   La commande ne garde pas d'historique des envois precedents.
 *   Si le backlog est toujours > seuil le lendemain, un nouvel email est envoie.
 *   C'est voulu : le message du jour est toujours a jour (nombre exact d'en-attente).
 *   Pour eviter le spam, on peut : baisser la frequence du cron (hebdomadaire)
 *   ou rajouter un systeme d'opt-out (hors scope V1).
 *
 * CONFIGURATION :
 *   - Seuil : parametre ADMIN_PENDING_THRESHOLD dans .env.local (defaut : 5).
 *     Injecte via le bind $adminPendingThreshold dans services.yaml.
 *     Baisser a 1 pour tester en dev.
 *   - Destinataire : ADMIN_EMAIL (bind $adminEmail dans services.yaml).
 *   - Expediteur : noreply@bazaart.fr (adresse canonique de l'application).
 *
 * USAGE :
 *   docker compose exec app php bin/console app:notify-admin-pending-opportunities
 *   docker compose exec app php bin/console app:notify-admin-pending-opportunities --dry-run
 *   docker compose exec app php bin/console app:notify-admin-pending-opportunities --threshold=2
 *
 * BRANCHEMENT CRON (a faire par l'admin systeme) :
 *   0 8 * * * docker compose exec -T app php bin/console app:notify-admin-pending-opportunities
 *   → Chaque matin a 8h, si > 5 opportunites en attente : email envoye.
 */
#[AsCommand(
    name: 'app:notify-admin-pending-opportunities',
    description: 'Envoie un email a l\'equipe si le nombre d\'opportunites en attente depasse le seuil configure',
)]
class NotifyAdminPendingOpportunitiesCommand extends Command
{
    public function __construct(
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
        // Adresse email de l'equipe — injectee via le bind $adminEmail dans services.yaml
        // (variable ADMIN_EMAIL dans .env, defaut 'bonjourbazaart@gmail.com')
        private readonly string $adminEmail,
        // Seuil configurable — injecte via le bind $adminPendingThreshold dans services.yaml
        // (variable ADMIN_PENDING_THRESHOLD dans .env, defaut 5)
        private readonly int $adminPendingThreshold,
    ) {
        // Important : appeler le constructeur parent pour que Symfony enregistre la commande
        parent::__construct();
    }

    /**
     * Configure les options disponibles en ligne de commande.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simule l\'execution sans envoyer l\'email (utile pour tester en dev)'
            )
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_REQUIRED,
                'Surcharge le seuil configure (ex: --threshold=2 pour tester avec 2 en attente)',
            )
        ;
    }

    /**
     * Corps principal de la commande.
     *
     * Flux :
     *   1. Lit le seuil (depuis l'option --threshold ou la config)
     *   2. Compte les opportunites en attente (COUNT SQL, pas de chargement entites)
     *   3. Si count > seuil : envoie un email a l'equipe (sauf en --dry-run)
     *   4. Sinon : log + sortie propre
     *
     * Erreurs :
     *   - Toute exception de l'envoi email est catchee : la commande ne plante pas
     *     (un echec d'email ne doit pas bloquer un cron).
     *   - L'erreur est loguee en ERROR (visible dans Sentry en prod).
     *   - La commande retourne Command::FAILURE si l'email n'a pas pu etre envoye.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ── Determination du seuil ────────────────────────────────────────────
        // L'option --threshold surcharge la valeur injectee par services.yaml.
        // Validation : si --threshold est fourni, on s'assure que c'est un entier >= 0.
        $threshold = $this->adminPendingThreshold;
        $thresholdOpt = $input->getOption('threshold');
        if ($thresholdOpt !== null) {
            if (!is_numeric($thresholdOpt) || (int) $thresholdOpt < 0) {
                $io->error('--threshold doit etre un entier positif ou nul. Ex: --threshold=2');
                return Command::FAILURE;
            }
            $threshold = (int) $thresholdOpt;
        }

        $isDryRun = (bool) $input->getOption('dry-run');

        $io->title('Alerte admin — opportunites en attente');
        $io->text(sprintf(
            'Seuil : %d | Email : %s | Mode : %s',
            $threshold,
            $this->adminEmail,
            $isDryRun ? 'DRY-RUN (aucun email envoye)' : 'REEL',
        ));

        // ── Comptage SQL ──────────────────────────────────────────────────────
        // SELECT COUNT(*) — pas de chargement d'entites en memoire.
        // On utilise countPending() qui est deja optimise et existant dans le repository.
        $pendingCount = $this->scrapedResourceRepository->countPending();

        $io->text(sprintf('Opportunites en attente : <info>%d</info>', $pendingCount));

        // ── Comparaison avec le seuil ─────────────────────────────────────────
        if ($pendingCount <= $threshold) {
            // Backlog sous controle : pas d'email, sortie propre.
            $io->success(sprintf(
                '%d opportunite(s) en attente <= seuil %d. Pas d\'email envoye.',
                $pendingCount,
                $threshold
            ));
            return Command::SUCCESS;
        }

        // ── Construction de l'URL du back-office ─────────────────────────────
        // On genere l'URL absolue vers la page de gestion des opportunites scrapees.
        // UrlGeneratorInterface::ABSOLUTE_URL requiert que le router ait un contexte
        // HTTP (host, scheme). En CLI, Symfony utilise les parametres router.request_context
        // definis dans framework.yaml ou .env (APP_URL etc.).
        // Si la generation echoue (pas de contexte), on utilise une URL de fallback.
        try {
            $adminUrl = $this->urlGenerator->generate(
                'app_admin_scraped_opportunities',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (\Exception) {
            // Fallback si le contexte de routage n'est pas configure en CLI
            $adminUrl = 'https://bazaart.fr/admin/scraped-opportunities';
        }

        // ── Contenu de l'email ────────────────────────────────────────────────
        // Email en texte brut : simple, deliverable partout, pas de rendu HTML a maintenir.
        // On detaille le nombre exact, le seuil, et le lien direct vers le back-office.
        $subject = sprintf(
            '[Bazaart] %d opportunite(s) en attente de validation',
            $pendingCount
        );

        $bodyLines = [
            'Bonjour,',
            '',
            sprintf(
                'Il y a actuellement %d opportunite(s) scrapee(s) en attente de validation'
                . ' dans le back-office Bazaart (seuil d\'alerte : %d).',
                $pendingCount,
                $threshold,
            ),
            '',
            'Pour traiter ces opportunites, rendez-vous ici :',
            $adminUrl,
            '',
            'Actions disponibles dans le back-office :',
            '  - Verifier : valide l\'opportunite et la publie dans la Ressourcerie.',
            '  - Rejeter : ecarte l\'opportunite (hors sujet, doublon).',
            '',
            'Cet email est envoye automatiquement par la commande app:notify-admin-pending-opportunities.',
            'Il peut etre declenche manuellement ou par un cron (voir docs/scraping-cron.md).',
            '',
            '-- Equipe Bazaart',
        ];

        $emailBody = implode("\n", $bodyLines);

        // ── Mode dry-run : on affiche sans envoyer ────────────────────────────
        if ($isDryRun) {
            $io->section('Email qui serait envoye :');
            $io->text('Destinataire : ' . $this->adminEmail);
            $io->text('Sujet : ' . $subject);
            $io->text('Corps :');
            $io->block($emailBody, null, 'fg=yellow');
            $io->success('DRY-RUN termine. Aucun email envoye.');
            return Command::SUCCESS;
        }

        // ── Envoi de l'email ──────────────────────────────────────────────────
        // On encapsule dans un try/catch : un echec d'email ne doit pas bloquer
        // un cron (il ne faut pas que la commande retourne une erreur qui redeclencherait
        // des alertes systeme intempestives).
        try {
            $email = (new Email())
                // Expediteur canonique : noreply@bazaart.fr avec nom d'affichage "Bazaart"
                // Meme pattern que CourseProposalService pour cohérence des emails admin.
                ->from(new Address('noreply@bazaart.fr', 'Bazaart'))
                ->to($this->adminEmail)
                ->subject($subject)
                ->text($emailBody);

            $this->mailer->send($email);

            // Log de succes pour tracabilite (visible dans Symfony profiler en dev,
            // dans les logs Nginx/PHP-FPM en prod, et dans Sentry si configure).
            $this->logger->info(
                'Email d\'alerte opportunites envoye.',
                [
                    'pending_count' => $pendingCount,
                    'threshold'     => $threshold,
                    'recipient'     => $this->adminEmail,
                ]
            );

            $io->success(sprintf(
                'Email envoye a %s : %d opportunite(s) en attente (seuil : %d).',
                $this->adminEmail,
                $pendingCount,
                $threshold,
            ));

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            // L'erreur est loguee en ERROR : visible dans Sentry en prod,
            // dans app.log en dev.
            $this->logger->error(
                'Echec envoi email alerte opportunites.',
                [
                    'error'         => $e->getMessage(),
                    'pending_count' => $pendingCount,
                    'threshold'     => $threshold,
                ]
            );

            $io->error(sprintf(
                'Impossible d\'envoyer l\'email : %s',
                $e->getMessage()
            ));

            // On retourne FAILURE pour que le cron puisse detecter l'echec
            // et potentiellement relancer ou alerter via un mecanisme externe.
            return Command::FAILURE;
        }
    }
}

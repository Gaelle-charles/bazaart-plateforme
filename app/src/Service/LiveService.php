<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Live;
use App\Entity\LiveAttendee;
use App\Entity\User;
use App\Enum\LiveStatus;
use App\Enum\NotificationType;
use App\Repository\LiveAttendeeRepository;
use App\Repository\LiveRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * LiveService — logique métier du module Lives planifiés V1.
 *
 * Ce service est le seul endroit où la logique métier est appliquée.
 * Les controllers (LiveController, AdminLiveController) et la commande
 * (SendLiveRemindersCommand) délèguent toutes les opérations ici.
 *
 * Principe de séparation des responsabilités (architecture Bazaart) :
 *   - Controllers : HTTP, CSRF, redirections, flash messages
 *   - Voters       : autorisations
 *   - CE service   : création, validation métier, persistance, emails
 *
 * Ce service NE connaît pas l'objet Request Symfony.
 * Il travaille uniquement avec des entités et des scalaires.
 */
class LiveService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LiveRepository $liveRepository,
        private readonly LiveAttendeeRepository $attendeeRepository,
        private readonly MailerInterface $mailer,
        // Twig est injecté pour rendre les templates d'emails directement depuis le service
        private readonly Environment $twig,
        // Logger pour tracer les erreurs d'envoi sans bloquer le processus
        private readonly LoggerInterface $logger,
        // UserRepository pour récupérer tous les utilisateurs lors de la notification NewLive
        private readonly UserRepository $userRepository,
        // NotificationService pour créer des notifications in-app aux utilisateurs
        private readonly NotificationService $notificationService,
    ) {}

    // ─── Gestion des lives ────────────────────────────────────────────────────

    /**
     * Crée un nouveau live planifié.
     *
     * @param array{
     *     title: string,
     *     description?: string|null,
     *     scheduledAt: \DateTimeInterface,
     *     externalUrl: string,
     *     replayUrl?: string|null,
     *     coverImageUrl?: string|null,
     * } $data   Les données du formulaire admin (déjà validées par Symfony Validator)
     * @param User $host   L'hôte / animateur du live (assigné par l'admin)
     */
    public function createLive(array $data, User $host): Live
    {
        $live = new Live();
        $live->setTitle($data['title']);
        $live->setDescription($data['description'] ?? null);
        $live->setScheduledAt($data['scheduledAt']);
        $live->setExternalUrl($data['externalUrl']);
        $live->setReplayUrl($data['replayUrl'] ?? null);
        $live->setCoverImageUrl($data['coverImageUrl'] ?? null);
        $live->setHostedBy($host);
        // Le statut par défaut est SCHEDULED (initialisé dans l'entité)

        $this->em->persist($live);
        $this->em->flush();

        $this->logger->info('Live créé', [
            'live_id'    => $live->getId(),
            'title'      => $live->getTitle(),
            'scheduledAt' => $live->getScheduledAt()->format('Y-m-d H:i'),
            'host'       => $host->getEmail(),
        ]);

        // ── Notifications in-app : prévenir tous les utilisateurs du nouveau live ──
        //
        // En V1 (faible volumétrie d'utilisateurs), on envoie les notifications en boucle
        // synchrone directement après la persistance du live.
        //
        // Pourquoi pas Messenger async en V1 ?
        //   → Complexité inutile pour un petit nombre d'utilisateurs.
        //   → Le planning serré (deadline 15 juin) privilégie le fonctionnel au scalable.
        //   → À refactoriser en V2 avec un Message + Handler pour supporter N milliers d'utilisateurs.
        //
        // Note : NotificationService::create() gère l'anti-auto-notification (sender === recipient).
        // On ne passe pas de $sender ici car c'est un live admin → tout le monde doit être notifié.
        // createBatch() persist sans flush → on regroupe tous les INSERT en une transaction
        // au lieu de N transactions individuelles (une par utilisateur avec create()).
        $allUsers = $this->userRepository->findAll();
        foreach ($allUsers as $user) {
            $this->notificationService->createBatch(
                recipient: $user,
                type: NotificationType::NewLive,
                relatedEntityType: 'live',
                relatedEntityId: $live->getId(),
                data: [
                    // Titre du live pour l'affichage dans le centre de notifications
                    'liveTitle'   => $live->getTitle(),
                    // Date formatée en français : "25/05/2026 à 20h30"
                    'scheduledAt' => $live->getScheduledAt()->format('d/m/Y à H\hi'),
                ],
            );
        }
        // Un seul flush pour tous les persist() — évite N BEGIN/COMMIT PostgreSQL
        $this->em->flush();

        $this->logger->info('Notifications NewLive envoyées', [
            'live_id'     => $live->getId(),
            'users_count' => count($allUsers),
        ]);

        return $live;
    }

    /**
     * Met à jour les informations d'un live existant.
     *
     * @param array{
     *     title?: string,
     *     description?: string|null,
     *     scheduledAt?: \DateTimeInterface,
     *     externalUrl?: string,
     *     replayUrl?: string|null,
     *     coverImageUrl?: string|null,
     *     status?: LiveStatus,
     *     hostedBy?: User,
     * } $data   Les données du formulaire (déjà validées)
     */
    public function updateLive(Live $live, array $data): Live
    {
        // On met à jour uniquement les champs fournis dans $data
        if (isset($data['title'])) {
            $live->setTitle($data['title']);
        }
        if (array_key_exists('description', $data)) {
            $live->setDescription($data['description']);
        }
        if (isset($data['scheduledAt'])) {
            $live->setScheduledAt($data['scheduledAt']);
        }
        if (isset($data['externalUrl'])) {
            $live->setExternalUrl($data['externalUrl']);
        }
        if (array_key_exists('replayUrl', $data)) {
            $live->setReplayUrl($data['replayUrl']);
        }
        if (array_key_exists('coverImageUrl', $data)) {
            $live->setCoverImageUrl($data['coverImageUrl']);
        }
        if (isset($data['status'])) {
            $live->setStatus($data['status']);
        }
        if (isset($data['hostedBy'])) {
            $live->setHostedBy($data['hostedBy']);
        }

        // PreUpdate lifecycle callback va automatiquement mettre à jour updatedAt
        $this->em->flush();

        return $live;
    }

    /**
     * Annule un live planifié et notifie tous les inscrits par email.
     *
     * Cette méthode :
     *   1. Change le statut du live en CANCELLED
     *   2. Envoie un email à chaque inscrit pour les prévenir
     *
     * Note : on flushe AVANT d'envoyer les emails.
     * Le statut CANCELLED est persisté en base d'abord, puis les notifications
     * partent. Si le mailer est indisponible, le live est déjà annulé en BDD —
     * c'est acceptable : l'admin verra l'erreur dans les logs et pourra notifier
     * manuellement. Utiliser Messenger async en V2 pour découpler davantage.
     */
    public function cancelLive(Live $live): void
    {
        // Charge les inscrits AVANT de changer le statut (par précaution)
        $attendees = $live->getAttendees()->toArray();

        // Passe le statut à CANCELLED
        $live->setStatus(LiveStatus::CANCELLED);
        $this->em->flush();

        // Notifie chaque inscrit par email (erreurs absorbées individuellement)
        foreach ($attendees as $attendee) {
            $this->sendCancellationEmail($live, $attendee->getUser());
        }

        $this->logger->info('Live annulé, notifications envoyées', [
            'live_id'        => $live->getId(),
            'attendees_count' => count($attendees),
        ]);
    }

    // ─── Gestion des inscriptions ─────────────────────────────────────────────

    /**
     * Inscrit un utilisateur à un live (s'abonne aux rappels email).
     *
     * Vérifie que :
     *   - Le live est encore SCHEDULED (on ne peut pas s'inscrire à un live terminé)
     *   - L'utilisateur n'est pas déjà inscrit (contrainte unique en BDD + vérif service)
     *
     * @throws \LogicException Si le live n'est pas en statut SCHEDULED
     * @throws \LogicException Si l'utilisateur est déjà inscrit
     */
    public function registerAttendee(Live $live, User $user): LiveAttendee
    {
        // Guard : on ne peut s'inscrire qu'à un live planifié
        if (!$live->isScheduled()) {
            throw new \LogicException(sprintf(
                'Impossible de s\'inscrire au live "%s" : statut "%s" (seuls les lives SCHEDULED acceptent des inscriptions).',
                $live->getTitle(),
                $live->getStatus()->label(),
            ));
        }

        // Guard : vérification du doublon AVANT la tentative d'insertion
        // (plus explicite que laisser l'exception de contrainte unique PostgreSQL remonter)
        if ($this->isUserRegistered($live, $user)) {
            throw new \LogicException(sprintf(
                'L\'utilisateur "%s" est déjà inscrit au live "%s".',
                $user->getEmail(),
                $live->getTitle(),
            ));
        }

        // Crée et persiste l'inscription
        $attendee = new LiveAttendee();
        $attendee->setLive($live);
        $attendee->setUser($user);

        $this->em->persist($attendee);
        $this->em->flush();

        return $attendee;
    }

    /**
     * Désinscrit un utilisateur d'un live.
     *
     * Si l'utilisateur n'est pas inscrit, la méthode ne fait rien
     * (comportement idempotent — on ne lève pas d'exception).
     */
    public function unregisterAttendee(Live $live, User $user): void
    {
        $attendee = $this->attendeeRepository->findByLiveAndUser($live, $user);

        if ($attendee === null) {
            // L'utilisateur n'est pas inscrit — comportement silencieux
            return;
        }

        $this->em->remove($attendee);
        $this->em->flush();
    }

    /**
     * Vérifie si un utilisateur est inscrit à un live.
     *
     * Utilisé dans les controllers pour afficher le bon bouton
     * (S'inscrire / Se désinscrire) et dans registerAttendee pour éviter les doublons.
     */
    public function isUserRegistered(Live $live, User $user): bool
    {
        return $this->attendeeRepository->findByLiveAndUser($live, $user) !== null;
    }

    // ─── Requêtes d'affichage ─────────────────────────────────────────────────

    /**
     * Retourne les prochains lives planifiés (pour la page calendrier).
     *
     * @return Live[]
     */
    public function getUpcoming(int $limit = 10): array
    {
        return $this->liveRepository->findUpcoming($limit);
    }

    /**
     * Retourne les lives terminés avec un replay disponible.
     *
     * @return Live[]
     */
    public function getPast(int $limit = 10): array
    {
        return $this->liveRepository->findPastWithReplay($limit);
    }

    // ─── Rappels email ────────────────────────────────────────────────────────

    /**
     * Envoie les rappels email 24h avant chaque live planifié.
     *
     * Stratégie :
     *   1. Récupère tous les lives SCHEDULED dans les prochaines 24h
     *   2. Pour chaque live, trouve les inscrits qui n'ont pas encore été notifiés
     *   3. Envoie un email à chacun
     *   4. Marque reminderSent = true pour éviter les doublons
     *
     * Retourne le nombre total d'emails envoyés avec succès.
     * Les erreurs individuelles sont loguées mais n'interrompent pas la boucle.
     *
     * Utilisé par : SendLiveRemindersCommand (app:live:send-reminders)
     */
    public function sendReminders(): int
    {
        // Trouve les lives qui démarrent dans les 24 prochaines heures
        $upcomingLives = $this->liveRepository->findScheduledInNext24Hours();

        if (empty($upcomingLives)) {
            $this->logger->info('app:live:send-reminders — aucun live dans les 24h, rien à envoyer.');
            return 0;
        }

        $totalSent = 0;

        foreach ($upcomingLives as $live) {
            // Récupère les inscrits non encore notifiés pour CE live
            $pendingAttendees = $this->attendeeRepository->findPendingRemindersForLive($live);

            if (empty($pendingAttendees)) {
                // Tous les inscrits ont déjà été notifiés (ou il n'y a personne)
                continue;
            }

            foreach ($pendingAttendees as $attendee) {
                $sent = $this->sendReminderEmail($live, $attendee);

                if ($sent) {
                    // Marque le rappel comme envoyé IMMÉDIATEMENT après succès
                    // → si un flush ultérieur échoue, au pire on renvoie un doublon
                    //   (acceptable) plutôt que de ne jamais notifier
                    $attendee->setReminderSent(true);
                    $totalSent++;
                }
            }

            // Un seul flush par live (plutôt qu'un flush par inscrit) — meilleure perf
            $this->em->flush();
        }

        $this->logger->info('app:live:send-reminders — rappels envoyés', [
            'count' => $totalSent,
        ]);

        return $totalSent;
    }

    // ─── Méthodes privées d'envoi email ──────────────────────────────────────

    /**
     * Envoie l'email de rappel à un inscrit.
     *
     * Retourne true si l'email a été transmis au mailer, false en cas d'erreur.
     * Les erreurs sont loguées mais n'interrompent pas la boucle d'envoi.
     *
     * Structure de l'email :
     *   - Sujet : "Rappel — [titre] démarre dans moins de 24h"
     *   - HTML  : emails/live_reminder.html.twig
     *   - Texte : emails/live_reminder.txt.twig
     */
    private function sendReminderEmail(Live $live, LiveAttendee $attendee): bool
    {
        try {
            // Contexte Twig : variables disponibles dans le template email
            $context = [
                'live'    => $live,
                'user'    => $attendee->getUser(),
                'subject' => sprintf('Rappel — "%s" démarre dans moins de 24h', $live->getTitle()),
            ];

            $htmlBody = $this->twig->render('emails/live_reminder.html.twig', $context);
            $textBody = $this->twig->render('emails/live_reminder.txt.twig', $context);

            // Sans ->from(), certains serveurs SMTP rejettent le message (RFC 5321).
            // 'noreply@bazaart.fr' est l'adresse d'expédition officielle Bazaart.
            $email = (new Email())
                ->from(new Address('noreply@bazaart.fr', 'Bazaart'))
                ->to($attendee->getUser()->getEmail())
                ->subject($context['subject'])
                ->html($htmlBody)
                ->text($textBody);

            $this->mailer->send($email);

            return true;

        } catch (\Throwable $e) {
            // On absorbe l'exception pour ne pas bloquer les autres envois
            $this->logger->error('Échec envoi rappel live', [
                'live_id'   => $live->getId(),
                'user_email' => $attendee->getUser()->getEmail(),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Envoie l'email d'annulation d'un live à un utilisateur inscrit.
     *
     * Cette méthode est appelée par cancelLive() pour chaque inscrit.
     * Les erreurs sont absorbées (on ne veut pas que l'annulation échoue
     * si un email ne peut pas être envoyé à un utilisateur spécifique).
     */
    private function sendCancellationEmail(Live $live, User $user): void
    {
        try {
            $context = [
                'live' => $live,
                'user' => $user,
            ];

            // On utilise le même template que le rappel mais avec un sujet différent
            // En V2, on pourra créer un template dédié pour les annulations
            $htmlBody = $this->twig->render('emails/live_cancellation.html.twig', $context);
            $textBody = $this->twig->render('emails/live_cancellation.txt.twig', $context);

            // Même obligation que pour sendReminderEmail : ->from() requis pour
            // passer les filtres anti-spam des serveurs SMTP en production.
            $email = (new Email())
                ->from(new Address('noreply@bazaart.fr', 'Bazaart'))
                ->to($user->getEmail())
                ->subject(sprintf('Annulation — Le live "%s" n\'aura pas lieu', $live->getTitle()))
                ->html($htmlBody)
                ->text($textBody);

            $this->mailer->send($email);

        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email annulation live', [
                'live_id'    => $live->getId(),
                'user_email' => $user->getEmail(),
                'error'      => $e->getMessage(),
            ]);
        }
    }
}

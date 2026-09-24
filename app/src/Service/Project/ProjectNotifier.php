<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\ProjectTask;
use App\Entity\ProjectTaskComment;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * ProjectNotifier — emails de suivi de l'Espace projets (ADR-0037).
 *
 * Trois emails, tous désactivables par chaque membre (Équipe & réglages) :
 *   1. « X t'a assigné une tâche »
 *   2. « X a commenté une de tes tâches »
 *   3. le récap quotidien (commande app:projets:rappels)
 *
 * RÈGLES :
 *   - On n'écrit jamais à la personne qui a fait l'action (pas d'email « tu as… »).
 *   - Un échec d'envoi est journalisé mais ne bloque JAMAIS l'action de l'utilisatrice :
 *     la tâche est créée même si le serveur mail est indisponible.
 */
class ProjectNotifier
{
    private const string FROM_EMAIL = 'noreply@bazaart.fr';
    private const string FROM_NAME  = 'Bazaart · Espace projets';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ProjectMemberService $memberService,
        private readonly ProjectClock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function notifyAssigned(ProjectTask $task, User $assignee, User $actor): void
    {
        if ($assignee->getId() === $actor->getId() || !$this->wantsEmails($assignee)) {
            return;
        }

        $actorName = ProjectMemberService::displayName($actor);
        $this->send($assignee, sprintf('%s t\'a assigné une tâche : %s', $actorName, $task->getTitle()), [
            'heading' => 'Nouvelle tâche pour toi',
            'intro'   => sprintf('%s t\'a assigné la tâche suivante.', $actorName),
            'task'    => $task,
            'quote'   => null,
        ]);
    }

    /**
     * Prévient les personnes assignées (et la créatrice) qu'un commentaire a été posté.
     */
    public function notifyComment(ProjectTaskComment $comment, User $actor): void
    {
        $task       = $comment->getTask();
        $recipients = [];
        foreach ($task->getAssignees() as $assignee) {
            $recipients[(int) $assignee->getId()] = $assignee;
        }
        if ($task->getCreatedBy() !== null) {
            $recipients[(int) $task->getCreatedBy()->getId()] = $task->getCreatedBy();
        }
        unset($recipients[(int) $actor->getId()]);

        $actorName = ProjectMemberService::displayName($actor);
        foreach ($recipients as $recipient) {
            if (!$this->wantsEmails($recipient)) {
                continue;
            }
            $this->send($recipient, sprintf('%s a commenté : %s', $actorName, $task->getTitle()), [
                'heading' => 'Nouveau commentaire',
                'intro'   => sprintf('%s a écrit sur la tâche « %s » :', $actorName, $task->getTitle()),
                'task'    => $task,
                'quote'   => mb_strlen($comment->getContent()) > 600 ? mb_substr($comment->getContent(), 0, 599) . '…' : $comment->getContent(),
            ]);
        }
    }

    /**
     * Récap quotidien : tâches en retard, du jour et des 3 prochains jours.
     *
     * @param array{overdue: list<ProjectTask>, today: list<ProjectTask>, soon: list<ProjectTask>} $groups
     */
    public function sendDigest(User $member, array $groups): bool
    {
        $count = count($groups['overdue']) + count($groups['today']) + count($groups['soon']);
        if ($count === 0 || !$this->wantsEmails($member)) {
            return false;
        }

        $subject = count($groups['overdue']) > 0
            ? sprintf('Ton récap Bazaart : %d tâche(s) en retard', count($groups['overdue']))
            : sprintf('Ton récap Bazaart : %d tâche(s) à venir', $count);

        return $this->send($member, $subject, [
            'groups' => $groups,
            'today'  => $this->clock->today(),
        ], 'emails/project_digest');
    }

    private function wantsEmails(User $user): bool
    {
        return $this->memberService->getProfile($user)->wantsEmailNotifications();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(User $recipient, string $subject, array $context, string $template = 'emails/project_notification'): bool
    {
        $context['recipientName'] = ProjectMemberService::displayName($recipient);
        $context['spaceUrl']      = $this->urlGenerator->generate('app_admin_pm_overview', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $context['settingsUrl']   = $this->urlGenerator->generate('app_admin_pm_team', [], UrlGeneratorInterface::ABSOLUTE_URL);
        if (isset($context['task']) && $context['task'] instanceof ProjectTask) {
            $context['taskUrl'] = $this->urlGenerator->generate('app_admin_pm_task_show', ['id' => $context['task']->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $email = (new TemplatedEmail())
            ->from(new Address(self::FROM_EMAIL, self::FROM_NAME))
            ->to($recipient->getEmail())
            ->subject($subject)
            ->htmlTemplate($template . '.html.twig')
            ->textTemplate($template . '.txt.twig')
            ->context($context);

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('[Espace projets] Email non envoyé : ' . $e->getMessage(), ['to' => $recipient->getEmail()]);

            return false;
        }
    }
}

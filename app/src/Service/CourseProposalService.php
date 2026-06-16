<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CourseProposal;
use App\Entity\User;
use App\Enum\CourseLevel;
use App\Enum\CourseProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * CourseProposalService — logique métier des propositions de formation (ADR-0012).
 *
 * Responsabilités :
 *   - créer une proposition (validation + persistance + email à l'équipe Bazaart) ;
 *   - traiter une proposition (acceptation/refus + email à l'auteur).
 *
 * Le service ne connaît pas le Request : il reçoit des entités et des scalaires.
 * L'autorisation (ROLE_USER / ROLE_ADMIN) est gérée en amont (controller + access_control).
 */
class CourseProposalService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        // Adresse de l'équipe (ADMIN_EMAIL), injectée via le bind global de services.yaml
        private readonly string $adminEmail,
    ) {}

    /**
     * Crée une proposition de formation à partir des données du formulaire.
     *
     * @param array<string, mixed> $data Données POST (title, description, targetAudience,
     *                                   experience, level?, portfolioUrl?)
     * @return CourseProposal|string L'entité créée, ou un message d'erreur (string).
     */
    public function createProposal(User $author, array $data): CourseProposal|string
    {
        // ── Validation ────────────────────────────────────────────────────────
        $title = trim((string) ($data['title'] ?? ''));
        if (mb_strlen($title) < 5) {
            return 'Le titre doit contenir au moins 5 caractères.';
        }
        if (mb_strlen($title) > 255) {
            return 'Le titre ne doit pas dépasser 255 caractères.';
        }

        $description = trim((string) ($data['description'] ?? ''));
        if (mb_strlen($description) < 20) {
            return 'La description doit contenir au moins 20 caractères.';
        }

        $targetAudience = trim((string) ($data['targetAudience'] ?? ''));
        if ($targetAudience === '') {
            return 'Le public visé est obligatoire.';
        }

        $experience = trim((string) ($data['experience'] ?? ''));
        if ($experience === '') {
            return 'Merci de décrire ton expérience sur le sujet.';
        }

        // Niveau optionnel : on accepte une valeur d'enum valide, sinon null
        $level = null;
        $levelRaw = trim((string) ($data['level'] ?? ''));
        if ($levelRaw !== '') {
            $level = CourseLevel::tryFrom($levelRaw); // null si valeur inconnue → ignoré
        }

        // Lien portfolio optionnel
        $portfolioUrl = trim((string) ($data['portfolioUrl'] ?? ''));
        $portfolioUrl = $portfolioUrl !== '' ? $portfolioUrl : null;
        if ($portfolioUrl !== null && mb_strlen($portfolioUrl) > 500) {
            return 'Le lien portfolio est trop long (500 caractères max).';
        }

        // ── Création ──────────────────────────────────────────────────────────
        $proposal = new CourseProposal();
        $proposal->setProposedBy($author);
        $proposal->setTitle($title);
        $proposal->setDescription($description);
        $proposal->setTargetAudience($targetAudience);
        $proposal->setExperience($experience);
        $proposal->setLevel($level);
        $proposal->setPortfolioUrl($portfolioUrl);
        // status reste Pending par défaut

        $this->em->persist($proposal);
        $this->em->flush();

        // ── Notification email à l'équipe Bazaart ─────────────────────────────
        $this->notifyAdminNewProposal($proposal);

        return $proposal;
    }

    /**
     * Accepte une proposition : statut + traçabilité + email à l'auteur.
     */
    public function accept(CourseProposal $proposal, User $admin, ?string $note): void
    {
        $this->decide($proposal, $admin, CourseProposalStatus::Accepted, $note);
    }

    /**
     * Refuse une proposition : statut + traçabilité + email à l'auteur.
     */
    public function reject(CourseProposal $proposal, User $admin, ?string $note): void
    {
        $this->decide($proposal, $admin, CourseProposalStatus::Rejected, $note);
    }

    /**
     * Applique une décision (acceptation/refus) et notifie l'auteur.
     */
    private function decide(
        CourseProposal $proposal,
        User $admin,
        CourseProposalStatus $status,
        ?string $note
    ): void {
        $proposal->setStatus($status);
        $proposal->setReviewedBy($admin);
        $proposal->setReviewedAt(new \DateTime());
        $note = $note !== null ? trim($note) : null;
        $proposal->setAdminNote($note !== '' ? $note : null);

        $this->em->flush();

        $this->notifyAuthorDecision($proposal);
    }

    // ─── Emails ─────────────────────────────────────────────────────────────

    /**
     * Email à l'équipe : une nouvelle proposition vient d'être soumise.
     */
    private function notifyAdminNewProposal(CourseProposal $proposal): void
    {
        $email = (new Email())
            ->from('noreply@bazaart.fr')
            ->to($this->adminEmail)
            ->subject('[Formations] Nouvelle proposition — ' . $proposal->getTitle())
            ->text(implode("\n\n", [
                'Une nouvelle proposition de formation a été soumise.',
                'Titre        : ' . $proposal->getTitle(),
                // Partie locale de l'email (RGPD : pas l'email complet)
                'Proposé par  : ' . explode('@', $proposal->getProposedBy()->getEmail())[0],
                'Public visé  : ' . $proposal->getTargetAudience(),
                "Description  :\n" . $proposal->getDescription(),
                "Expérience   :\n" . $proposal->getExperience(),
                'Lien         : ' . ($proposal->getPortfolioUrl() ?? '—'),
                '',
                'Traite cette proposition dans le back-office : /admin/formations/propositions',
            ]));

        $this->mailer->send($email);
    }

    /**
     * Email à l'auteur : sa proposition a été acceptée ou refusée.
     */
    private function notifyAuthorDecision(CourseProposal $proposal): void
    {
        $accepted = $proposal->getStatus() === CourseProposalStatus::Accepted;
        $subject  = $accepted
            ? 'Ta proposition de formation a été acceptée !'
            : 'Réponse à ta proposition de formation';

        $body = [
            $accepted
                ? 'Bonne nouvelle : ta proposition de formation a été acceptée par l\'équipe Bazaart.'
                : 'Ta proposition de formation a été étudiée par l\'équipe Bazaart.',
            'Titre  : ' . $proposal->getTitle(),
            'Statut : ' . $proposal->getStatus()->label(),
        ];

        if ($proposal->getAdminNote() !== null) {
            $body[] = "Message de l'équipe :\n" . $proposal->getAdminNote();
        }

        $body[] = $accepted
            ? 'Nous reviendrons vers toi pour la suite (mise en place de la formation).'
            : 'Merci pour ta contribution, n\'hésite pas à proposer d\'autres idées.';

        $email = (new Email())
            ->from('noreply@bazaart.fr')
            ->to($proposal->getProposedBy()->getEmail())
            ->subject($subject)
            ->text(implode("\n\n", $body));

        $this->mailer->send($email);
    }
}

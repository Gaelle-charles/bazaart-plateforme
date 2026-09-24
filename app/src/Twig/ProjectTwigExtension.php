<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectTaskStatus;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTaskRepository;
use App\Service\Project\GoogleDriveService;
use App\Service\Project\ProjectClock;
use App\Service\Project\ProjectDateFormatter;
use App\Service\Project\ProjectMemberService;
use App\Service\Project\ProjectOnboardingService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * ProjectTwigExtension — fonctions et filtres Twig de l'Espace projets (ADR-0037).
 *
 * Même principe que AdminBadgeExtension : ce qui est utile dans TOUS les templates
 * du module (avatars, dates en français, badge de la sidebar…) est exposé ici,
 * pour ne pas avoir à le passer depuis chaque contrôleur.
 *
 * FILTRES
 *   user|pm_name          « Gaëlle Charles-Belamour » (ou partie avant @ de l'email)
 *   user|pm_initials      « GC »
 *   user|pm_color         couleur d'avatar de la membre (#RRGGBB)
 *   date|pm_short         « 3 oct. »
 *   date|pm_long          « jeudi 24 septembre 2026 »
 *   date|pm_datetime      « 24 sept. 2026 à 14h05 » (heure de l'équipe)
 *   date|pm_ago           « il y a 5 min »
 *   date|pm_due           « Demain », « Dans 3 j », « Il y a 2 j »…
 *   texte|pm_autolink     rend les URL cliquables (texte échappé AVANT, voir plus bas)
 *
 * FONCTIONS
 *   pm_today()            date du jour de l'équipe (pour task.isOverdue(pm_today()))
 *   pm_members()          membres de l'équipe
 *   pm_badge_my_overdue() nombre de MES tâches en retard (badge sidebar)
 *   pm_drive_connected()  le Google Drive de l'équipe est-il connecté ?
 *   pm_show_tour()        faut-il ouvrir la visite guidée (première visite) ?
 *   pm_selectable_projects() projets proposés dans l'ajout rapide de tâche
 */
class ProjectTwigExtension extends AbstractExtension
{
    /** Cache par requête (la sidebar est rendue une fois, mais on reste prudent). */
    private ?int $cachedOverdue = null;

    /** @var array<int, string>|null */
    private ?array $colorMap = null;

    private ?bool $driveConnected = null;

    /** @var list<Project>|null */
    private ?array $selectableProjects = null;

    public function __construct(
        private readonly ProjectMemberService $memberService,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectOnboardingService $onboardingService,
        private readonly GoogleDriveService $driveService,
        private readonly ProjectClock $clock,
        private readonly Security $security,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('pm_name', [ProjectMemberService::class, 'displayName']),
            new TwigFilter('pm_initials', [ProjectMemberService::class, 'initials']),
            new TwigFilter('pm_color', $this->memberColor(...)),
            new TwigFilter('pm_short', fn (?\DateTimeInterface $d): string => $d !== null ? ProjectDateFormatter::short($d, $this->clock->today()) : ''),
            new TwigFilter('pm_long', fn (?\DateTimeInterface $d): string => $d !== null ? ProjectDateFormatter::long($d) : ''),
            new TwigFilter('pm_datetime', fn (?\DateTimeInterface $d): string => $d !== null ? ProjectDateFormatter::dateTime($this->clock->toLocal($d)) : ''),
            new TwigFilter('pm_ago', fn (?\DateTimeInterface $d): string => $d !== null ? ProjectDateFormatter::ago($d, $this->clock->now()) : ''),
            new TwigFilter('pm_due', fn (?\DateTimeInterface $d): string => $d !== null ? ProjectDateFormatter::due($d, $this->clock->today()) : ''),
            new TwigFilter('pm_day_short', fn (\DateTimeInterface $d): string => ProjectDateFormatter::dayShort($d)),
            // pre_escape: 'html' → Twig échappe le texte AVANT d'appeler le filtre :
            // on ne transforme donc que du texte déjà neutralisé (pas de XSS possible).
            // is_safe: ['html'] → le résultat (qui contient nos <a>) n'est pas ré-échappé.
            new TwigFilter('pm_autolink', $this->autolink(...), ['pre_escape' => 'html', 'is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pm_today', fn (): \DateTimeImmutable => $this->clock->today()),
            new TwigFunction('pm_members', fn (): array => $this->memberService->getMembers()),
            new TwigFunction('pm_badge_my_overdue', $this->myOverdueCount(...)),
            new TwigFunction('pm_drive_connected', fn (): bool => $this->driveConnected ??= $this->driveService->isConnected()),
            new TwigFunction('pm_show_tour', $this->showTour(...)),
            new TwigFunction('pm_selectable_projects', fn (): array => $this->selectableProjects ??= $this->projectRepository->findSelectable()),
            new TwigFunction('pm_task_statuses', static fn (): array => ProjectTaskStatus::cases()),
            new TwigFunction('pm_project_statuses', static fn (): array => ProjectStatus::cases()),
        ];
    }

    public function memberColor(?User $user): string
    {
        if ($user === null) {
            return '#5B584F';
        }
        $this->colorMap ??= $this->memberService->getColorMap();

        // Personne qui n'est plus membre (accès retiré) : gris neutre.
        return $this->colorMap[(int) $user->getId()] ?? '#5B584F';
    }

    public function myOverdueCount(): int
    {
        if ($this->cachedOverdue !== null) {
            return $this->cachedOverdue;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->cachedOverdue = 0;
        }

        $today = $this->clock->today();
        $count = 0;
        foreach ($this->taskRepository->findOpenAssignedTo($user) as $task) {
            if ($task->isOverdue($today)) {
                ++$count;
            }
        }

        return $this->cachedOverdue = $count;
    }

    /** Visite guidée à ouvrir automatiquement : seulement à la toute première visite. */
    public function showTour(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->onboardingService->shouldShowTour($user);
    }

    /**
     * Transforme les URL http(s) d'un texte DÉJÀ échappé en liens cliquables.
     *
     * On s'arrête aux entités &quot; &#039; &lt; &gt; : un guillemet tapé juste
     * après une URL ne fait pas partie du lien. La ponctuation finale (« . , ; ) »)
     * est laissée hors du lien.
     */
    public function autolink(string $escapedText): string
    {
        return (string) preg_replace_callback(
            '~https?://(?:(?!&quot;|&#0?39;|&lt;|&gt;)[^\s<])+~u',
            static function (array $m): string {
                $url      = $m[0];
                $trailing = '';
                while ($url !== '' && str_contains('.,;:!?)', substr($url, -1))) {
                    $trailing = substr($url, -1) . $trailing;
                    $url      = substr($url, 0, -1);
                }

                return sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>%2$s', $url, $trailing);
            },
            $escapedText,
        );
    }
}

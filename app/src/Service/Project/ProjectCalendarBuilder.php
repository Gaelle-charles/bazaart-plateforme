<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\ProjectTask;

/**
 * ProjectCalendarBuilder — grille mensuelle de la vue Calendrier (ADR-0037).
 *
 * La grille commence le LUNDI de la semaine du 1er du mois et se termine le
 * DIMANCHE de la semaine du dernier jour : 4 à 6 lignes de 7 jours, comme un
 * calendrier papier. Les jours hors du mois sont grisés mais restent des cibles
 * de glisser-déposer.
 *
 * Tout le calcul est fait ici (PHP) : le template Twig n'a plus qu'à boucler.
 */
final class ProjectCalendarBuilder
{
    /**
     * Lit « ?month=2026-10 ». Valeur absente ou invalide → mois en cours.
     */
    public static function parseMonth(?string $value, \DateTimeImmutable $today): \DateTimeImmutable
    {
        if ($value !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1) {
            $month = \DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
            if ($month !== false) {
                return $month;
            }
        }

        return $today->modify('first day of this month');
    }

    /**
     * Bornes (incluses) de la grille affichée : sert à ne charger que les tâches utiles.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable}
     */
    public static function gridRange(\DateTimeImmutable $month): array
    {
        $first = $month->modify('first day of this month');
        $last  = $month->modify('last day of this month');

        // format('N') : 1 = lundi … 7 = dimanche.
        $from = $first->modify(sprintf('-%d days', (int) $first->format('N') - 1));
        $to   = $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param list<ProjectTask> $tasks    tâches ayant une échéance dans la grille
     * @param list<Project>     $projects projets (leurs échéances apparaissent comme repères)
     *
     * @return array{
     *     month: \DateTimeImmutable,
     *     title: string,
     *     prev: string,
     *     next: string,
     *     current: string,
     *     weeks: list<list<array{date: \DateTimeImmutable, key: string, inMonth: bool, isToday: bool, isWeekend: bool, tasks: list<ProjectTask>, projects: list<Project>}>>
     * }
     */
    public function build(\DateTimeImmutable $month, array $tasks, array $projects, \DateTimeImmutable $today): array
    {
        $month = $month->modify('first day of this month');
        $range = self::gridRange($month);

        // Index « AAAA-MM-JJ » → tâches / projets de ce jour.
        $tasksByDay = [];
        foreach ($tasks as $task) {
            if ($task->getDueDate() !== null) {
                $tasksByDay[$task->getDueDate()->format('Y-m-d')][] = $task;
            }
        }
        $projectsByDay = [];
        foreach ($projects as $project) {
            if ($project->getDueDate() !== null) {
                $projectsByDay[$project->getDueDate()->format('Y-m-d')][] = $project;
            }
        }

        $weeks  = [];
        $week   = [];
        $cursor = $range['from'];
        while ($cursor <= $range['to']) {
            $key    = $cursor->format('Y-m-d');
            $dayTasks = $tasksByDay[$key] ?? [];
            // Dans une case : non terminées d'abord, puis par priorité décroissante.
            usort($dayTasks, static fn (ProjectTask $a, ProjectTask $b): int => [$a->isDone(), -$a->getPriority()->weight()] <=> [$b->isDone(), -$b->getPriority()->weight()]);

            $week[] = [
                'date'      => $cursor,
                'key'       => $key,
                'inMonth'   => $cursor->format('Y-m') === $month->format('Y-m'),
                'isToday'   => $key === $today->format('Y-m-d'),
                'isWeekend' => (int) $cursor->format('N') >= 6,
                'tasks'     => $dayTasks,
                'projects'  => $projectsByDay[$key] ?? [],
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week    = [];
            }
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'month'   => $month,
            'title'   => ProjectDateFormatter::monthYear($month),
            'prev'    => $month->modify('-1 month')->format('Y-m'),
            'next'    => $month->modify('+1 month')->format('Y-m'),
            'current' => $month->format('Y-m'),
            'weeks'   => $weeks,
        ];
    }
}

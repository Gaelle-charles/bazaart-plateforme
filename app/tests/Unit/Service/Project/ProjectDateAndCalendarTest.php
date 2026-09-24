<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Project;
use App\Entity\ProjectTask;
use App\Enum\ProjectTaskPriority;
use App\Service\Project\ProjectCalendarBuilder;
use App\Service\Project\ProjectClock;
use App\Service\Project\ProjectDateFormatter;
use App\Service\Project\ProjectTemplateCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Dates en français, grille du calendrier, modèles de projets, fuseau de l'équipe (ADR-0037).
 */
class ProjectDateAndCalendarTest extends TestCase
{
    public function testRelativeDueLabels(): void
    {
        $today = new \DateTimeImmutable('2026-09-24');

        self::assertSame('Aujourd\'hui', ProjectDateFormatter::due(new \DateTimeImmutable('2026-09-24'), $today));
        self::assertSame('Demain', ProjectDateFormatter::due(new \DateTimeImmutable('2026-09-25'), $today));
        self::assertSame('Hier', ProjectDateFormatter::due(new \DateTimeImmutable('2026-09-23'), $today));
        self::assertSame('Dans 3 j', ProjectDateFormatter::due(new \DateTimeImmutable('2026-09-27'), $today));
        self::assertSame('Il y a 5 j', ProjectDateFormatter::due(new \DateTimeImmutable('2026-09-19'), $today));
        self::assertSame('3 oct.', ProjectDateFormatter::due(new \DateTimeImmutable('2026-10-03'), $today));
        self::assertSame('3 janv. 2027', ProjectDateFormatter::due(new \DateTimeImmutable('2027-01-03'), $today));
        self::assertSame('jeudi 24 septembre 2026', ProjectDateFormatter::long($today));
    }

    public function testCalendarGridStartsOnMondayAndEndsOnSunday(): void
    {
        // Octobre 2026 : le 1er est un jeudi, le 31 un samedi.
        $range = ProjectCalendarBuilder::gridRange(new \DateTimeImmutable('2026-10-01'));
        self::assertSame('2026-09-28', $range['from']->format('Y-m-d'));
        self::assertSame('2026-11-01', $range['to']->format('Y-m-d'));

        $task = (new ProjectTask())->setTitle('Dépôt du dossier')->setDueDate(new \DateTimeImmutable('2026-10-15'))->setPriority(ProjectTaskPriority::Urgent);
        $project = (new Project())->setName('Festival')->setDueDate(new \DateTimeImmutable('2026-10-31'));

        $calendar = (new ProjectCalendarBuilder())->build(new \DateTimeImmutable('2026-10-01'), [$task], [$project], new \DateTimeImmutable('2026-10-15'));

        self::assertSame('octobre 2026', $calendar['title']);
        self::assertSame('2026-09', $calendar['prev']);
        self::assertSame('2026-11', $calendar['next']);
        self::assertCount(5, $calendar['weeks']);
        $days = array_merge(...$calendar['weeks']);
        $byKey = array_column($days, null, 'key');
        self::assertCount(1, $byKey['2026-10-15']['tasks']);
        self::assertTrue($byKey['2026-10-15']['isToday']);
        self::assertCount(1, $byKey['2026-10-31']['projects']);
        self::assertFalse($byKey['2026-09-28']['inMonth']);
    }

    public function testMonthParameterFallsBackToCurrentMonth(): void
    {
        $today = new \DateTimeImmutable('2026-09-24');

        self::assertSame('2026-02-01', ProjectCalendarBuilder::parseMonth('2026-02', $today)->format('Y-m-d'));
        self::assertSame('2026-09-01', ProjectCalendarBuilder::parseMonth('2026-13', $today)->format('Y-m-d'));
        self::assertSame('2026-09-01', ProjectCalendarBuilder::parseMonth('<script>', $today)->format('Y-m-d'));
    }

    public function testTemplateTasksArePlannedBackwardsFromDeadline(): void
    {
        $catalog = new ProjectTemplateCatalog();
        $tasks   = $catalog->buildTasks('candidature', new \DateTimeImmutable('2026-12-01'));

        self::assertSame('Lire le règlement et vérifier l\'éligibilité', $tasks[0]['title']);
        self::assertSame('2026-11-01', $tasks[0]['dueDate']?->format('Y-m-d'));
        self::assertSame('Déposer le dossier', $tasks[5]['title']);
        self::assertSame('2026-11-30', $tasks[5]['dueDate']?->format('Y-m-d'));
        self::assertCount(4, $tasks[1]['subtasks']);

        // Sans échéance de projet : tâches sans date. Modèle inconnu : rien.
        self::assertNull($catalog->buildTasks('candidature', null)[0]['dueDate']);
        self::assertSame([], $catalog->buildTasks('inconnu', null));
    }

    public function testTemplatesContainNoEmDash(): void
    {
        // Règle éditoriale de Gaëlle : pas de tiret cadratin dans les textes affichés.
        $catalog = new ProjectTemplateCatalog();
        foreach (array_keys($catalog->all()) as $key) {
            foreach ($catalog->buildTasks($key, null) as $task) {
                self::assertStringNotContainsString('—', $task['title']);
            }
        }
    }

    public function testTodayUsesTeamTimezone(): void
    {
        $clock = new ProjectClock('America/Guadeloupe');
        $expected = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guadeloupe')))->format('Y-m-d');

        self::assertSame($expected . ' 00:00:00', $clock->today()->format('Y-m-d H:i:s'));
    }
}

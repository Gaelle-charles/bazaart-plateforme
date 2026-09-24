<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Project;
use App\Enum\ProjectStatus;
use App\Service\Project\ProjectService;
use PHPUnit\Framework\TestCase;

/**
 * Chronologie des projets et tri d'affichage (ProjectService, ADR-0037).
 * Ces méthodes sont du pur calcul : on instancie le service sans ses dépendances.
 */
class ProjectTimelineTest extends TestCase
{
    private ProjectService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(ProjectService::class))->newInstanceWithoutConstructor();
    }

    public function testTimelineComputesBarsAsPercentagesOfSixMonths(): void
    {
        $inside  = (new Project())->setName('Festival')->setStartDate(new \DateTimeImmutable('2026-10-01'))->setDueDate(new \DateTimeImmutable('2026-10-31'));
        $clipped = (new Project())->setName('Long')->setStartDate(new \DateTimeImmutable('2026-01-01'))->setDueDate(new \DateTimeImmutable('2026-09-15'));
        $undated = (new Project())->setName('Sans dates');
        $outside = (new Project())->setName('Plus tard')->setDueDate(new \DateTimeImmutable('2027-12-01'));

        $timeline = $this->service->buildTimeline([$inside, $clipped, $undated, $outside], new \DateTimeImmutable('2026-08-10'), new \DateTimeImmutable('2026-09-24'));

        // Fenêtre : 1er août 2026 → 31 janvier 2027 (6 mois, 184 jours)
        self::assertSame('2026-08-01', $timeline['start']->format('Y-m-d'));
        self::assertSame('2027-01-31', $timeline['end']->format('Y-m-d'));
        self::assertCount(6, $timeline['months']);
        self::assertSame(0.0, (float) $timeline['months'][0]['left']);

        self::assertCount(2, $timeline['rows']);
        [$first, $second] = $timeline['rows'];
        self::assertSame('Long', $first['project']->getName(), 'Rangées triées par position de départ');
        self::assertTrue($first['clippedStart'], 'Commence avant la fenêtre : bord pointillé');
        self::assertSame(0.0, (float) $first['left']);
        self::assertEqualsWithDelta(61 * 100 / 184, $second['left'], 0.01, 'Le 1er octobre est le 62e jour de la fenêtre');
        self::assertEqualsWithDelta(31 * 100 / 184, $second['width'], 0.01, 'Octobre dure 31 jours');

        self::assertSame(['Sans dates'], array_map(static fn (Project $p): string => $p->getName(), $timeline['undated']));
        self::assertSame(['Plus tard'], array_map(static fn (Project $p): string => $p->getName(), $timeline['outside']));
        self::assertNotNull($timeline['todayLeft']);
    }

    public function testProjectsAreSortedByStatusThenDeadline(): void
    {
        $archived = (new Project())->setName('Archivé')->setStatus(ProjectStatus::Archived);
        $late     = (new Project())->setName('B en cours')->setDueDate(new \DateTimeImmutable('2026-12-01'));
        $soon     = (new Project())->setName('A en cours')->setDueDate(new \DateTimeImmutable('2026-10-01'));
        $noDate   = (new Project())->setName('C en cours');
        $planned  = (new Project())->setName('À venir')->setStatus(ProjectStatus::Planned);

        $sorted = $this->service->sortForDisplay([$archived, $noDate, $planned, $late, $soon]);

        self::assertSame(
            ['A en cours', 'B en cours', 'C en cours', 'À venir', 'Archivé'],
            array_map(static fn (Project $p): string => $p->getName(), $sorted),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\DTO\Project\ProjectTaskFilter;
use App\Enum\ProjectTaskPriority;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le filtre des tâches doit ignorer toute valeur invalide venue de l'URL
 * et savoir se re-sérialiser pour les liens entre vues (ADR-0037).
 */
class ProjectTaskFilterTest extends TestCase
{
    public function testValidParametersAreParsed(): void
    {
        $filter = ProjectTaskFilter::fromRequest(new Request([
            'q' => '  budget  ', 'project' => '12', 'assignee' => 'me', 'priority' => 'urgent',
            'status' => 'in_progress', 'label' => '3', 'due' => 'week', 'done' => 'hide',
        ]));

        self::assertSame('budget', $filter->search);
        self::assertSame(12, $filter->projectId);
        self::assertSame('me', $filter->assignee);
        self::assertSame(ProjectTaskPriority::Urgent, $filter->priority);
        self::assertSame(3, $filter->labelId);
        self::assertSame('week', $filter->due);
        self::assertTrue($filter->hideDone);
        self::assertSame(8, $filter->countActive());
    }

    public function testInvalidParametersAreIgnored(): void
    {
        $filter = ProjectTaskFilter::fromRequest(new Request([
            'project' => '1 OR 1=1', 'assignee' => '<script>', 'priority' => 'max',
            'status' => 'fini', 'label' => '-4', 'due' => 'hier',
        ]));

        self::assertNull($filter->projectId);
        self::assertNull($filter->assignee);
        self::assertNull($filter->priority);
        self::assertNull($filter->status);
        self::assertNull($filter->labelId);
        self::assertNull($filter->due);
        self::assertFalse($filter->isActive());
    }

    public function testToQueryRoundTrip(): void
    {
        $query  = ['project' => 'none', 'assignee' => '7', 'due' => 'overdue'];
        $filter = ProjectTaskFilter::fromRequest(new Request($query));

        self::assertTrue($filter->withoutProject);
        self::assertSame($query, $filter->toQuery());
    }

    public function testSearchIsTruncated(): void
    {
        $filter = ProjectTaskFilter::fromRequest(new Request(['q' => str_repeat('é', 300)]));

        self::assertSame(100, mb_strlen((string) $filter->search));
    }
}

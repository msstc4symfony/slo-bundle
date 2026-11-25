<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\KindCoverage;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\PanelFactory;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\ScheduledTasks;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\SloRows;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use PHPUnit\Framework\TestCase;

final class ScheduledTasksTest extends TestCase
{
    public function testIdentity(): void
    {
        $dashboard = $this->dashboard();

        self::assertSame('Scheduled Tasks', $dashboard->title());
        self::assertSame('scheduled-tasks', $dashboard->slug());
    }

    public function testWithoutTaskObjectivesOnlyAMarkdownNoticeIsRendered(): void
    {
        $panels = $this->dashboard()->panels($this->context());

        self::assertCount(1, $panels);
        self::assertSame('text', $panels[0]->json['type'] ?? null);
        self::assertSame('Scheduled tasks', $panels[0]->json['title'] ?? null);
        self::assertSame(['content' => 'No scheduled task has an SLO.', 'mode' => 'markdown'], $panels[0]->json['options'] ?? null);
        self::assertSame(4, $panels[0]->height);
    }

    private function dashboard(): ScheduledTasks
    {
        $panels = new PanelFactory();

        return new ScheduledTasks($panels, new SloRows($panels, new PromQlBuilder()));
    }

    private function context(): DashboardContext
    {
        $coverage = new Output([], array_map(static fn (CoverageKind $kind): KindCoverage => new KindCoverage($kind, 0, 0, 100.0), CoverageKind::cases()), 0);

        return new DashboardContext(new Selector('shop', []), [], $coverage, false);
    }
}

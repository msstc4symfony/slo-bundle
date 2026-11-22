<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\KindCoverage;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\DashboardBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\PanelFactory;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\SloOverview;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\SloRows;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use PHPUnit\Framework\TestCase;

final class DashboardBuilderTest extends TestCase
{
    public function testUidAndTitleWithoutComponentsAreUnchanged(): void
    {
        $built = $this->build(new Selector('shop', []));

        self::assertSame('slo-8785f2a9fd0f', $built['uid']);
        self::assertSame('SLO: SLO Overview (shop)', $built['title']);
    }

    public function testUidAndTitleDependOnComponents(): void
    {
        $gateway = $this->build(new Selector('showcase', ['gateway']));
        $orders = $this->build(new Selector('showcase', ['orders']));

        self::assertNotSame($gateway['uid'], $orders['uid']);
        self::assertSame('SLO: SLO Overview (showcase: gateway)', $gateway['title']);
        self::assertSame('SLO: SLO Overview (showcase: orders)', $orders['title']);
        self::assertSame(
            $this->build(new Selector('showcase', ['a', 'b']))['uid'],
            $this->build(new Selector('showcase', ['b', 'a']))['uid'],
        );
        self::assertSame('SLO: SLO Overview (showcase: a, b)', $this->build(new Selector('showcase', ['b', 'a']))['title']);
    }

    /**
     * @return array{uid: non-empty-string, title: non-empty-string}
     */
    private function build(Selector $selector): array
    {
        $coverage = new Output(
            [],
            array_map(static fn (CoverageKind $kind): KindCoverage => new KindCoverage($kind, 0, 0, 100.0), CoverageKind::cases()),
            0,
        );
        $panels = new PanelFactory();
        $built = (new DashboardBuilder())->build(
            new SloOverview($panels, new SloRows($panels, new PromQlBuilder())),
            new DashboardContext($selector, [], $coverage, false),
        );

        return ['uid' => $built['uid'], 'title' => $built['title']];
    }
}

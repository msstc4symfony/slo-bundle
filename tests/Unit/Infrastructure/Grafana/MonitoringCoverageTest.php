<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageStatus;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\KindCoverage;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\PointCoverage;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\MonitoringCoverage;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\PanelFactory;
use PHPUnit\Framework\TestCase;

final class MonitoringCoverageTest extends TestCase
{
    public function testFreeTextCannotInjectMarkupLinksOrGrafanaVariables(): void
    {
        $reason = "<img src=x onerror=alert(1)> [click](http://evil) `code` \${__user.login} \$datasource a & b \\| c\r\nnext";

        $row = $this->pointRow('App\Controller\Health::check', $reason);

        self::assertSame(
            '| App\Controller\Health::check | http_route | ignored | - | &lt;img src=x onerror=alert(1)&gt; \[click\](http://evil) \`code\` &#36;{__user.login} &#36;datasource a &amp; b \\\\\| c  next |',
            $row,
        );
    }

    /**
     * @param non-empty-string $point
     * @param non-empty-string $reason
     */
    private function pointRow(string $point, string $reason): string
    {
        $coverage = new Output(
            [new PointCoverage($point, CoverageKind::HttpRoute, CoverageStatus::Ignored, $reason, [], true)],
            array_map(static fn (CoverageKind $kind): KindCoverage => new KindCoverage($kind, 0, 0, 100.0), CoverageKind::cases()),
            0,
        );
        $panels = (new MonitoringCoverage(new PanelFactory()))->panels(new DashboardContext(new Selector('shop', []), [], $coverage, false));
        $content = $panels[1]->json['options']['content'] ?? null;
        self::assertIsString($content);

        return explode("\n", $content)[2];
    }
}

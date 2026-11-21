<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerateDashboards;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Input;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\GrafanaFileBuilder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../../../Fixtures/Generated/';

    private const SLUGS = ['application-overview', 'http-routes', 'messenger', 'business-operations', 'slo-overview', 'monitoring-coverage'];

    public function testFixtureCatalogMatchesTheGoldenFiles(): void
    {
        $output = GenerationFixture::dashboardsAction(GenerationFixture::catalog())(GenerationFixture::dashboardsInput());

        self::assertSame(['grafana/dashboards', 'grafana/provisioning/dashboards'], $output->files->managedDirectories);
        self::assertSame(
            [
                ...array_map(static fn (string $slug): string => \sprintf('grafana/dashboards/slo-%s.json', $slug), $this->sorted(self::SLUGS)),
                'grafana/provisioning/dashboards/slo.yaml',
            ],
            array_map(static fn (GeneratedFile $file): string => $file->relativePath, $output->files->files),
        );
        foreach ($output->files->files as $file) {
            self::assertStringEqualsFile(self::GOLDEN . $file->relativePath, $file->contents, $file->relativePath);
        }
    }

    public function testGeneratingTwiceIsByteIdentical(): void
    {
        $first = GenerationFixture::dashboardsAction(GenerationFixture::catalog())(GenerationFixture::dashboardsInput());
        $second = GenerationFixture::dashboardsAction(GenerationFixture::catalog())(GenerationFixture::dashboardsInput());

        self::assertEquals($first->files, $second->files);
    }

    public function testEveryDashboardIsStructurallyValid(): void
    {
        $uids = [];
        foreach ($this->dashboards() as $path => $dashboard) {
            self::assertSame(41, $dashboard->get('schemaVersion'), $path);
            $uid = $dashboard->get('uid');
            self::assertIsString($uid);
            self::assertMatchesRegularExpression('/^slo-[0-9a-f]{12}$/', $uid, $path);
            $uids[] = $uid;
            self::assertSame(['datasource', 'application', 'component', 'environment'], $dashboard->column('templating.list', 'name'), $path);
            self::assertSame('now-24h', $dashboard->get('time.from'), $path);
            self::assertSame('now', $dashboard->get('time.to'), $path);
            self::assertSame('1m', $dashboard->get('refresh'), $path);
            self::assertFalse($dashboard->has('version'), $path);

            $ids = $dashboard->column('panels', 'id');
            self::assertSame(range(1, \count($ids)), $ids, $path . ': panel ids are 1..n in layout order');
            self::assertNotSame([], $ids, $path);
        }
        self::assertCount(\count(self::SLUGS), array_unique($uids));
    }

    public function testQueriesReadOnlyBundleSeriesAndRecordedSloSeries(): void
    {
        $seen = 0;
        foreach ($this->dashboards() as $path => $dashboard) {
            foreach ($dashboard->views('panels') as $panel) {
                if (!$panel->has('targets')) {
                    continue;
                }
                foreach ($panel->column('targets', 'expr') as $expr) {
                    self::assertIsString($expr);
                    preg_match_all('/[a-zA-Z_:][a-zA-Z0-9_:]*(?=\{)/', $expr, $matches);
                    self::assertNotSame([], $matches[0], $path . ': ' . $expr);
                    if (str_contains($expr, 'slo:')) {
                        self::assertStringContainsString('application="$application"', $expr, $path);
                    }
                    foreach ($matches[0] as $series) {
                        self::assertMatchesRegularExpression('/^(symfony_|slo:)/', $series, $path . ': ' . $expr);
                        $seen++;
                    }
                }
            }
        }
        self::assertGreaterThan(50, $seen);
    }

    public function testEveryQueryPanelDeclaresNoValue(): void
    {
        foreach ($this->dashboards() as $path => $dashboard) {
            foreach ($dashboard->views('panels') as $panel) {
                if ($panel->has('targets')) {
                    self::assertSame('No data', $panel->get('fieldConfig.defaults.noValue'), $path . ': ' . json_encode($panel->get('title')));
                }
            }
        }
    }

    public function testSloOverviewHasTheSixPanelsPerObjectiveWithTheWarmingUpMappingAndEndDate(): void
    {
        $dashboard = $this->dashboards()['grafana/dashboards/slo-slo-overview.json'];
        $panels = $dashboard->views('panels');

        self::assertSame(
            ['checkout.pay / availability', 'checkout.pay / latency', 'payment.process / availability', 'payment.process / latency', 'report.export / availability'],
            array_values(array_map(static fn (JsonView $panel): mixed => $panel->get('title'), array_filter($panels, static fn (JsonView $panel): bool => $panel->get('type') === 'row'))),
        );
        self::assertCount(5 + 5 * 6, $panels);

        $byTitle = [];
        $width = 0;
        foreach (\array_slice($panels, 0, 7) as $panel) {
            $title = $panel->get('title');
            self::assertIsString($title);
            $byTitle[$title] = $panel;
            if ($panel->get('type') !== 'row') {
                $panelWidth = $panel->get('gridPos.w');
                self::assertIsInt($panelWidth);
                $width += $panelWidth;
            }
        }
        self::assertSame(24, $width, 'one objective row fills the grid width');
        self::assertSame('slo:objective:target{application="$application",slo="checkout.pay",objective="availability"}', $byTitle['Target']->get('targets.0.expr'));
        self::assertSame('last_over_time(slo:sli:ratio{application="$application",slo="checkout.pay",objective="availability"}[10m])', $byTitle['SLI']->get('targets.0.expr'));
        self::assertSame('gauge', $byTitle['Error budget remaining']->get('type'));
        self::assertSame('last_over_time(slo:error_budget:remaining{application="$application",slo="checkout.pay",objective="availability"}[10m])', $byTitle['Error budget remaining']->get('targets.0.expr'));
        self::assertSame(
            'slo:error_ratio:rate1h{application="$application",slo="checkout.pay",objective="availability"} / (1 - slo:objective:target{application="$application",slo="checkout.pay",objective="availability"})',
            $byTitle['Burn rate']->get('targets.0.expr'),
        );
        $warming = $byTitle['Window state'];
        self::assertSame('last_over_time(slo:window:coverage_ratio{application="$application",slo="checkout.pay",objective="availability"}[10m])', $warming->get('targets.0.expr'));
        self::assertSame('range', $warming->get('fieldConfig.defaults.mappings.0.type'));
        self::assertSame('warming up', $warming->get('fieldConfig.defaults.mappings.0.options.result.text'));
        self::assertSame(0, $warming->get('fieldConfig.defaults.mappings.0.options.from'));
        $to = $warming->get('fieldConfig.defaults.mappings.0.options.to');
        self::assertIsFloat($to);
        self::assertLessThan(1, $to);

        $until = $byTitle['Warming up until'];
        self::assertSame('stat', $until->get('type'));
        self::assertSame('dateTimeAsIso', $until->get('fieldConfig.defaults.unit'));
        self::assertSame(
            '(time() + (1 - (last_over_time(slo:window:coverage_ratio{application="$application",slo="checkout.pay",objective="availability"}[10m]) < 0.999999)) * 2592000) * 1000',
            $until->get('targets.0.expr'),
        );
    }

    public function testApplicationOverviewReadsTheWindowGroupRecordsOverALookback(): void
    {
        $exprs = [];
        foreach ($this->dashboards()['grafana/dashboards/slo-application-overview.json']->views('panels') as $panel) {
            $title = $panel->get('title');
            self::assertIsString($title);
            $exprs[$title] = $panel->get('targets.0.expr');
        }

        self::assertSame('min(last_over_time(slo:sli:ratio{application="$application",objective=~".+"}[10m]))', $exprs['Worst SLI']);
        self::assertSame('min(last_over_time(slo:error_budget:remaining{application="$application",objective=~".+"}[10m]))', $exprs['Lowest error budget']);
    }

    public function testBusinessOperationsShowsTheLogicHistogramOnlyWhenItIsDefined(): void
    {
        $with = $this->dashboards()['grafana/dashboards/slo-business-operations.json'];
        $withoutOutput = GenerationFixture::dashboardsAction(GenerationFixture::catalog(logicHistogram: false))(GenerationFixture::dashboardsInput());
        $without = JsonView::decode($this->file($withoutOutput->files->files, 'grafana/dashboards/slo-business-operations.json'));

        self::assertStringContainsString('symfony_logic_action_duration_histogram_seconds_bucket', json_encode($with->get('panels'), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('symfony_logic_action', json_encode($without->get('panels'), JSON_THROW_ON_ERROR));
        $titles = $without->column('panels', 'title');
        self::assertContains('report.export / availability', $titles);
        self::assertNotContains('checkout.pay / availability', $titles);
    }

    public function testMonitoringCoverageEmbedsTheCatalogStateAsAMarkdownTable(): void
    {
        $panels = $this->dashboards()['grafana/dashboards/slo-monitoring-coverage.json']->views('panels');

        self::assertCount(2, $panels);
        self::assertSame(['Coverage by kind', 'Points'], [$panels[0]->get('title'), $panels[1]->get('title')]);
        self::assertSame('text', $panels[1]->get('type'));
        self::assertSame('markdown', $panels[1]->get('options.mode'));
        $summary = $panels[0]->get('options.content');
        $points = $panels[1]->get('options.content');
        self::assertIsString($summary);
        self::assertIsString($points);
        self::assertStringContainsString('| http_route | 50% | 100% |', $summary);
        self::assertStringContainsString('| Point | Kind | Status | SLOs | Note |', $points);
        self::assertStringContainsString('| checkout_pay | http_route | monitored | checkout.pay | - |', $points);
        self::assertStringContainsString('| health | http_route | unmonitored | - | no SLO is declared |', $points);
    }

    public function testProvisioningPointsAtTheConfiguredFolderAndTheGrafanaDashboardDirectory(): void
    {
        $output = GenerationFixture::dashboardsAction(GenerationFixture::catalog(), GrafanaFileBuilder::standard('prom-main', 'Reliability'))(new Input(new Selector('shop', [])));

        self::assertSame(
            <<<'YAML'
                apiVersion: 1
                providers:
                  - allowUiUpdates: false
                    disableDeletion: false
                    folder: Reliability
                    name: slo
                    options:
                      path: /var/lib/grafana/dashboards
                    type: file

                YAML,
            $this->file($output->files->files, 'grafana/provisioning/dashboards/slo.yaml'),
        );
        $dashboard = JsonView::decode($this->file($output->files->files, 'grafana/dashboards/slo-slo-overview.json'));
        self::assertSame('prom-main', $dashboard->get('templating.list.0.current.value'));
        self::assertSame('shop', $dashboard->get('templating.list.1.query'));
    }

    public function testIgnoredRoutesOfTheCoverageConfigAreLeftOutOfTheCoverageTable(): void
    {
        $output = GenerationFixture::dashboardsAction(GenerationFixture::catalog(), null, ['health'])(GenerationFixture::dashboardsInput());
        $points = JsonView::decode($this->file($output->files->files, 'grafana/dashboards/slo-monitoring-coverage.json'))->get('panels.1.options.content');

        self::assertIsString($points);
        self::assertStringContainsString('| checkout_pay | http_route | monitored |', $points);
        self::assertStringNotContainsString('| health | http_route | unmonitored |', $points);
    }

    /**
     * @return array<string, JsonView>
     */
    private function dashboards(): array
    {
        $output = GenerationFixture::dashboardsAction(GenerationFixture::catalog())(GenerationFixture::dashboardsInput());
        $dashboards = [];
        foreach ($output->files->files as $file) {
            if (str_ends_with($file->relativePath, '.json')) {
                $dashboards[$file->relativePath] = JsonView::decode($file->contents);
            }
        }

        return $dashboards;
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function file(array $files, string $path): string
    {
        foreach ($files as $file) {
            if ($file->relativePath === $path) {
                return $file->contents;
            }
        }
        self::fail($path . ' is not generated');
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $prefixed = array_map(static fn (string $slug): string => 'slo-' . $slug, $values);
        sort($prefixed, SORT_STRING);

        return array_map(static fn (string $name): string => substr($name, 4), $prefixed);
    }
}

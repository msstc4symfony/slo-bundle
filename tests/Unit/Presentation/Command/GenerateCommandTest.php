<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Presentation\Command;

use Msstc4Symfony\SloBundle\Application\Generation\Generate\Action as GenerateAction;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Action as GenerateDashboardsAction;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action as SyncAction;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\GrafanaFileBuilder;
use Msstc4Symfony\SloBundle\Presentation\Command\GenerateCommand;
use Msstc4Symfony\SloBundle\Test\Integration\DefinitionArgument;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\SyncFiles\InMemoryFileWriter;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateCommandTest extends TestCase
{
    public function testMissingApplicationSelectorExitsWithTwo(): void
    {
        $writer = new InMemoryFileWriter();
        $tester = new CommandTester($this->command($writer, application: null));

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('msstc4symfony_slo.generation.selector.application', $tester->getDisplay());
        self::assertSame([], $writer->files);
    }

    #[TestWith(['/'])]
    #[TestWith(['///'])]
    #[TestWith([''])]
    public function testRootOrEmptyOutputDirectoryIsRejected(string $directory): void
    {
        $writer = new InMemoryFileWriter();
        $tester = new CommandTester($this->command($writer));

        self::assertSame(Command::INVALID, $tester->execute(['--output' => $directory]));
        self::assertStringContainsString('The output directory must be a non-empty path other than "/".', $tester->getDisplay());
        self::assertSame([], $writer->files);
    }

    public function testWritesTheFilesIntoTheConfiguredDirectoryAndThenChecksClean(): void
    {
        $writer = new InMemoryFileWriter([
            '/project/observability/prometheus/prometheus.yml' => "hand-written\n",
            '/project/observability/prometheus/slo-old.rules.yml' => "removed slo\n",
        ]);
        $command = $this->command($writer);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $paths = array_keys($writer->files);
        self::assertContains('/project/observability/prometheus/prometheus.yml', $paths);
        self::assertContains('/project/observability/prometheus/slo-alerts.rules.yml', $paths);
        self::assertContains('/project/observability/grafana/dashboards/slo-slo-overview.json', $paths);
        self::assertContains('/project/observability/grafana/provisioning/dashboards/slo.yaml', $paths);
        self::assertNotContains('/project/observability/prometheus/slo-old.rules.yml', $paths);
        self::assertCount(10, $paths);
        self::assertStringContainsString('written prometheus/slo-alerts.rules.yml', $tester->getDisplay());
        self::assertStringContainsString('written grafana/dashboards/slo-http-routes.json', $tester->getDisplay());
        self::assertStringContainsString('deleted prometheus/slo-old.rules.yml', $tester->getDisplay());
        self::assertStringContainsString('9 file(s) written, 1 deleted in /project/observability', $tester->getDisplay());

        $check = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $check->execute(['--check' => true]));
        self::assertStringContainsString('up to date', $check->getDisplay());
    }

    public function testCheckListsStaleMissingAndExtraFilesAndExitsWithOne(): void
    {
        $writer = new InMemoryFileWriter([
            '/elsewhere/prometheus/slo-recording.rules.yml' => "old\n",
            '/elsewhere/prometheus/slo-removed.rules.yml' => "x\n",
            '/elsewhere/prometheus/prometheus.yml' => "hand-written\n",
            '/elsewhere/grafana/dashboards/slo-gone.json' => "{}\n",
            '/elsewhere/grafana/dashboards/hand-made.json' => "{}\n",
        ]);
        $tester = new CommandTester($this->command($writer));

        self::assertSame(Command::FAILURE, $tester->execute(['--check' => true, '--output' => '/elsewhere']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('stale   prometheus/slo-recording.rules.yml', $display);
        self::assertStringContainsString('missing prometheus/slo-alerts.rules.yml', $display);
        self::assertStringContainsString('extra   prometheus/slo-removed.rules.yml', $display);
        self::assertStringContainsString('missing grafana/dashboards/slo-slo-overview.json', $display);
        self::assertStringContainsString('missing grafana/provisioning/dashboards/slo.yaml', $display);
        self::assertStringContainsString('extra   grafana/dashboards/slo-gone.json', $display);
        self::assertStringNotContainsString('hand-made.json', $display);
        self::assertStringNotContainsString('prometheus.yml', $display);
        self::assertStringContainsString('run slo:generate', $display);
        self::assertSame("old\n", $writer->files['/elsewhere/prometheus/slo-recording.rules.yml']);
    }

    public function testWarningsGoToStandardError(): void
    {
        $tester = new CommandTester($this->command(new InMemoryFileWriter(), availabilityTarget: 90.0));

        self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('WARNING SLO checkout.pay availability: page burn-rate alert 1h/5m omitted', $tester->getErrorOutput());
        self::assertStringNotContainsString('WARNING', $tester->getDisplay());
    }

    public function testSlothSpecsAreWrittenOnlyWhenEnabledAndStaleOnesAreRemoved(): void
    {
        $stale = ['/project/observability/sloth/slo-removed.yml' => "old\n"];

        $off = new InMemoryFileWriter($stale);
        self::assertSame(Command::SUCCESS, (new CommandTester($this->command($off)))->execute([]));
        self::assertSame([], array_filter(array_keys($off->files), static fn (string $path): bool => str_contains($path, '/sloth/')));

        $on = new InMemoryFileWriter($stale);
        $tester = new CommandTester($this->command($on, sloth: true));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertArrayHasKey('/project/observability/sloth/slo-checkout.pay.yml', $on->files);
        self::assertArrayNotHasKey('/project/observability/sloth/slo-removed.yml', $on->files);
        self::assertSame(Command::SUCCESS, (new CommandTester($this->command($on, sloth: true)))->execute(['--check' => true]));
    }

    public function testMixedSlothWindowsWarnOnStandardError(): void
    {
        $tester = new CommandTester($this->command(new InMemoryFileWriter(), sloth: true));

        self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('WARNING Sloth has no per-SLO window but the SLOs use 3d, 30d', $tester->getErrorOutput());

        $off = new CommandTester($this->command(new InMemoryFileWriter()));
        $off->execute([], ['capture_stderr_separately' => true]);
        self::assertStringNotContainsString('Sloth', $off->getErrorOutput());
    }

    public function testCommandIsRegisteredWithTheGenerationParameters(): void
    {
        $container = TestContainer::compile([
            'coverage' => ['http_routes' => ['ignored_routes' => ['health']]],
            'generation' => [
                'selector' => ['application' => 'shop', 'components' => ['web']],
                'output_dir' => '/srv/observability',
                'prometheus' => ['alerts' => false, 'no_events_alert_hours' => 6],
                'sloth' => true,
                'grafana' => ['datasource_uid' => 'mimir', 'folder' => 'Shop SLO'],
            ],
        ]);

        $config = $container->get(GenerationConfig::class);
        self::assertInstanceOf(GenerationConfig::class, $config);
        self::assertSame(['shop', ['web'], '/srv/observability', true, false, 6, true], [$config->application, $config->components, $config->outputDir, $config->prometheusRules, $config->prometheusAlerts, $config->noEventsAlertHours, $config->sloth]);

        $grafana = $container->getDefinition(GrafanaFileBuilder::class);
        $standard = new ReflectionMethod(GrafanaFileBuilder::class, 'standard');
        self::assertSame('mimir', DefinitionArgument::of($grafana, $standard, 'datasourceUid'));
        self::assertSame('Shop SLO', DefinitionArgument::of($grafana, $standard, 'folder'));
        self::assertSame(['health'], $container->getParameterBag()->resolveValue(DefinitionArgument::of($container->getDefinition(GenerateDashboardsAction::class), new ReflectionMethod(GenerateDashboardsAction::class, '__construct'), 'ignoredRoutes')));
        self::assertSame('slo:generate', $this->command(new InMemoryFileWriter())->getName());
    }

    /**
     * @param non-empty-string|null $application
     */
    private function command(InMemoryFileWriter $writer, ?string $application = 'shop', float $availabilityTarget = 99.9, bool $sloth = false): GenerateCommand
    {
        $catalog = GenerationFixture::catalog(checkoutAvailabilityTarget: $availabilityTarget);

        return new GenerateCommand(new GenerateAction(
            GenerationFixture::action($catalog),
            GenerationFixture::dashboardsAction($catalog),
            GenerationFixture::slothAction($catalog),
            new SyncAction($writer),
            new GenerationConfig($application, ['web', 'worker'], '/project/observability', sloth: $sloth),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Presentation\Command;

use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action as SyncAction;
use Msstc4Symfony\SloBundle\Presentation\Command\GenerateCommand;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\SyncFiles\InMemoryFileWriter;
use PHPUnit\Framework\TestCase;
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

    public function testCommandIsRegisteredWithTheGenerationParameters(): void
    {
        $container = TestContainer::compile();
        $definition = $container->getDefinition(GenerateCommand::class);

        self::assertSame([null, [], sys_get_temp_dir() . '/infrastructure/observability', true, true, null, 'prometheus', 'SLO', []], \array_slice(array_values($definition->getArguments()), 3));
        self::assertSame('slo:generate', $this->command(new InMemoryFileWriter())->getName());
    }

    private function command(InMemoryFileWriter $writer, ?string $application = 'shop', float $availabilityTarget = 99.9): GenerateCommand
    {
        return new GenerateCommand(
            GenerationFixture::action(GenerationFixture::catalog(checkoutAvailabilityTarget: $availabilityTarget)),
            GenerationFixture::dashboardsAction(GenerationFixture::catalog(checkoutAvailabilityTarget: $availabilityTarget)),
            new SyncAction($writer),
            $application,
            ['web', 'worker'],
            '/project/observability',
            true,
            true,
            null,
            'prometheus',
            'SLO',
            [],
        );
    }
}

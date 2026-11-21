<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\Generate;

use Msstc4Symfony\SloBundle\Application\Generation\Generate\Action;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception\InvalidGenerationSettings;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Input;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action as SyncAction;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\SyncFiles\InMemoryFileWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testWritesEveryGeneratorIntoTheConfiguredDirectory(): void
    {
        $writer = new InMemoryFileWriter();

        $output = ($this->action($writer))(new Input());

        self::assertSame('/project/observability', $output->root);
        self::assertSame([], $output->warnings);
        self::assertNotSame([], $output->files->missing);
        self::assertSame($output->files->missing, array_map(static fn (string $path): string => substr($path, \strlen('/project/observability/')), array_keys($writer->files)));
        foreach (['prometheus/slo-alerts.rules.yml', 'grafana/dashboards/slo-slo-overview.json', 'grafana/provisioning/dashboards/slo.yaml'] as $file) {
            self::assertContains($file, $output->files->missing);
        }
        self::assertSame([], array_filter($output->files->missing, static fn (string $file): bool => str_starts_with($file, 'sloth/')));
    }

    public function testInputDirectoryOverridesTheConfigAndCheckModeWritesNothing(): void
    {
        $writer = new InMemoryFileWriter();

        $output = ($this->action($writer))(new Input('/elsewhere/', true));

        self::assertSame('/elsewhere', $output->root);
        self::assertNotSame([], $output->files->missing);
        self::assertSame([], $writer->files);
    }

    public function testSlothSpecsAndTheirWarningsJoinTheRuleWarnings(): void
    {
        $output = ($this->action(new InMemoryFileWriter(), sloth: true, availabilityTarget: 90.0))(new Input());

        self::assertContains('sloth/slo-checkout.pay.yml', $output->files->missing);
        self::assertStringStartsWith('SLO checkout.pay availability: page burn-rate alert 1h/5m omitted', $output->warnings[0] ?? '');
        self::assertStringStartsWith('Sloth has no per-SLO window', $output->warnings[array_key_last($output->warnings)] ?? '');
    }

    public function testRulesAndAlertsSettingsReachTheGeneratedFiles(): void
    {
        $alertsOff = ($this->action(new InMemoryFileWriter(), alerts: false))(new Input());
        self::assertContains('prometheus/slo-recording.rules.yml', $alertsOff->files->missing);
        self::assertNotContains('prometheus/slo-alerts.rules.yml', $alertsOff->files->missing);

        $rulesOff = ($this->action(new InMemoryFileWriter(), rules: false))(new Input());
        self::assertNotContains('prometheus/slo-recording.rules.yml', $rulesOff->files->missing);
        self::assertContains('prometheus/slo-alerts.rules.yml', $rulesOff->files->missing);
        self::assertStringStartsWith('generation.prometheus.alerts is enabled but generation.prometheus.rules is not', $rulesOff->warnings[0] ?? '');

        $writer = new InMemoryFileWriter();
        ($this->action($writer, noEventsAlertHours: 6))(new Input());
        $alerts = $writer->files['/project/observability/prometheus/slo-alerts.rules.yml'] ?? '';
        self::assertStringContainsString('SloNoEvents', $alerts);
        self::assertStringContainsString('recorded no events in 6h', $alerts);

        $writer = new InMemoryFileWriter();
        ($this->action($writer))(new Input());
        self::assertStringNotContainsString('SloNoEvents', $writer->files['/project/observability/prometheus/slo-alerts.rules.yml'] ?? '');
    }

    /**
     * An env placeholder can resolve to an empty application at runtime, after the configuration tree checked it.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function missingApplicationProvider(): iterable
    {
        yield 'not configured' => [null];
        yield 'resolved to an empty string' => [''];
    }

    #[DataProvider('missingApplicationProvider')]
    public function testMissingApplicationIsRejectedBeforeAnythingIsWritten(?string $application): void
    {
        $writer = new InMemoryFileWriter();

        try {
            ($this->action($writer, application: $application))(new Input());
            self::fail('Expected InvalidGenerationSettings.');
        } catch (InvalidGenerationSettings $e) {
            self::assertStringContainsString('generation.selector.application', $e->getMessage());
        }
        self::assertSame([], $writer->files);
    }

    #[TestWith(['/'])]
    #[TestWith(['//'])]
    #[TestWith([''])]
    public function testRootOrEmptyOutputDirectoryIsRejected(string $directory): void
    {
        $this->expectException(InvalidGenerationSettings::class);
        $this->expectExceptionMessage('non-empty path other than "/"');

        ($this->action(new InMemoryFileWriter()))(new Input($directory));
    }

    /**
     * @param positive-int|null $noEventsAlertHours
     */
    private function action(InMemoryFileWriter $writer, ?string $application = 'shop', bool $sloth = false, float $availabilityTarget = 99.9, bool $rules = true, bool $alerts = true, ?int $noEventsAlertHours = null): Action
    {
        $catalog = GenerationFixture::catalog(checkoutAvailabilityTarget: $availabilityTarget);

        return new Action(
            GenerationFixture::action($catalog),
            GenerationFixture::dashboardsAction($catalog),
            GenerationFixture::slothAction($catalog),
            new SyncAction($writer),
            new GenerationConfig($application, ['web'], '/project/observability', $rules, $alerts, $noEventsAlertHours, $sloth),
        );
    }
}

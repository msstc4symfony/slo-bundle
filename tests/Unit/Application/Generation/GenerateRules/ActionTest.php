<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerateRules;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Input;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Output;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ActionTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../../../Fixtures/Generated/';

    public function testFixtureCatalogMatchesTheGoldenFiles(): void
    {
        $output = GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input());

        self::assertSame(['prometheus'], $output->files->managedDirectories);
        self::assertSame(['prometheus/slo-alerts.rules.yml', 'prometheus/slo-recording.rules.yml'], $this->paths($output));
        foreach ($output->files->files as $file) {
            self::assertStringEqualsFile(self::GOLDEN . $file->relativePath, $file->contents, $file->relativePath);
        }
        self::assertSame([], $output->warnings);
    }

    public function testGeneratingTwiceIsByteIdentical(): void
    {
        $first = GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input(6));
        $second = GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input(6));

        self::assertEquals($first->files, $second->files);
    }

    public function testChangingOnlyTheTargetKeepsTheRevisionFilterAndChangesTheTargetConstant(): void
    {
        $before = $this->recording(GenerationFixture::action(GenerationFixture::catalog(99.0))(GenerationFixture::input()));
        $after = $this->recording(GenerationFixture::action(GenerationFixture::catalog(98.5))(GenerationFixture::input()));
        $definition = GenerationFixture::catalog()->get(SloName::fromString('checkout.pay'));
        self::assertNotNull($definition);
        $revision = (new RevisionCalculator())->revision($definition, ObjectiveKind::Latency, [])->value;

        self::assertStringContainsString('revision="' . $revision . '"', $before);
        self::assertStringContainsString('revision="' . $revision . '"', $after);
        self::assertStringContainsString("expr: vector(0.99)\n", $before);
        self::assertStringContainsString("expr: vector(0.985)\n", $after);
        self::assertStringNotContainsString('vector(0.985)', $before);
        self::assertSame(substr_count($before, 'revision="'), substr_count($after, 'revision="'));
    }

    public function testTwoApplicationsWithTheSameSloNameNeverShareSeries(): void
    {
        $catalog = GenerationFixture::catalog();
        $billing = GenerationFixture::action($catalog)(new Input(new Selector('billing', [])));
        $shop = GenerationFixture::action($catalog)(GenerationFixture::input());

        foreach ([$this->recording($billing), $this->alerts($billing)] as $document) {
            self::assertStringContainsString('application: billing', $document);
            self::assertStringNotContainsString('shop', $document);
        }
        foreach ([$this->recording($shop), $this->alerts($shop)] as $document) {
            self::assertStringContainsString('application: shop', $document);
            self::assertStringNotContainsString('billing', $document);
        }
        self::assertStringNotContainsString('slo:sli:ratio{slo=', $this->recording($billing));
        self::assertStringContainsString('1 - (1 - slo:sli:ratio{application="billing",slo="checkout.pay",objective="availability"})', $this->recording($billing));
    }

    public function testEveryObjectiveGetsOneGroupPerSloAndTheDocumentedRecords(): void
    {
        $recording = Yaml::parse($this->recording(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input())));
        self::assertIsArray($recording);
        self::assertIsArray($recording['groups']);

        $groups = [];
        $intervals = [];
        foreach ($recording['groups'] as $group) {
            self::assertIsArray($group);
            $intervals[] = $group['interval'];
            self::assertIsString($group['name']);
            self::assertIsArray($group['rules']);
            $groups[$group['name']] = array_map(static function (mixed $rule): string {
                self::assertIsArray($rule);
                self::assertIsArray($rule['labels']);
                self::assertIsString($rule['record']);
                self::assertIsString($rule['labels']['objective']);

                return $rule['record'] . ' ' . $rule['labels']['objective'] . (isset($rule['labels']['revision']) ? ' B' : ' A');
            }, $group['rules']);
        }

        self::assertSame([
            'slo-checkout.pay', 'slo-checkout.pay-window', 'slo-cleanup.run', 'slo-cleanup.run-window', 'slo-partners.sync', 'slo-partners.sync-window',
            'slo-payment.process', 'slo-payment.process-window', 'slo-report.export', 'slo-report.export-window',
        ], array_keys($groups));
        self::assertSame(['1m', '5m', '1m', '5m', '1m', '5m', '1m', '5m', '1m', '5m'], $intervals);
        $fast = static fn (string $objective, string $strategy): array => array_map(static fn (string $record): string => $record . ' ' . $objective . ' ' . $strategy, [
            'slo:events:rate5m', 'slo:error_ratio:rate5m', 'slo:events:rate30m', 'slo:error_ratio:rate30m',
            'slo:events:rate1h', 'slo:error_ratio:rate1h', 'slo:events:rate6h', 'slo:error_ratio:rate6h', 'slo:objective:target',
        ]);
        $slow = static fn (string $objective, string $strategy): array => array_map(static fn (string $record): string => $record . ' ' . $objective . ' ' . $strategy, [
            'slo:events:rate3d', 'slo:error_ratio:rate3d', 'slo:sli:ratio', 'slo:error_budget:remaining', 'slo:window:coverage_ratio',
        ]);
        self::assertSame([...$fast('availability', 'A'), ...$fast('latency', 'B')], $groups['slo-checkout.pay']);
        self::assertSame([...$slow('availability', 'A'), ...$slow('latency', 'B')], $groups['slo-checkout.pay-window']);
        self::assertSame([...$fast('availability', 'A'), ...$fast('latency', 'A')], $groups['slo-payment.process']);
        self::assertSame([...$slow('availability', 'A'), ...$slow('latency', 'A')], $groups['slo-payment.process-window']);
        self::assertSame($fast('availability', 'B'), $groups['slo-report.export']);
        self::assertSame($slow('availability', 'B'), $groups['slo-report.export-window']);
        self::assertSame([...$fast('availability', 'B'), ...$fast('latency', 'B')], $groups['slo-cleanup.run']);
        self::assertSame($slow('availability', 'B'), $groups['slo-partners.sync-window']);
        foreach ($groups as $name => $rules) {
            foreach ($rules as $rule) {
                self::assertSame(str_ends_with($name, '-window'), PromQlBuilder::isSlowRecord(explode(' ', $rule)[0]), $name . ': ' . $rule);
            }
        }
    }

    public function testOnlyScheduledTaskObjectivesCountUnfinishedRuns(): void
    {
        $recording = Yaml::parse($this->recording(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input())));
        self::assertIsArray($recording);
        self::assertIsArray($recording['groups']);

        foreach ($recording['groups'] as $group) {
            self::assertIsArray($group);
            self::assertIsArray($group['rules']);
            foreach ($group['rules'] as $rule) {
                self::assertIsArray($rule);
                self::assertIsArray($rule['labels']);
                self::assertIsString($rule['expr']);
                self::assertIsString($rule['record']);
                self::assertIsString($rule['labels']['slo']);
                $task = \in_array($rule['labels']['slo'], ['cleanup.run', 'partners.sync'], true);
                $reads = str_starts_with($rule['record'], 'slo:events:rate') || str_starts_with($rule['record'], 'slo:error_ratio:rate') || \in_array($rule['record'], ['slo:sli:ratio', 'slo:window:coverage_ratio'], true);
                self::assertSame($task && $reads, str_contains($rule['expr'], 'symfony_slo_scheduled_task_started_total'), $rule['record'] . ' of ' . $rule['labels']['slo']);
            }
        }
    }

    public function testNoEventsAlertIsOptIn(): void
    {
        $without = $this->alerts(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input()));
        $with = $this->alerts(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input(6)));

        self::assertStringNotContainsString('SloNoEvents', $without);
        self::assertSame(8, substr_count($with, 'alert: SloNoEvents'));
        self::assertStringContainsString('[6h]', $with);
        self::assertStringContainsString('recorded no events in 6h', $with);
    }

    public function testDisabledFilesAreNotGeneratedButStayManaged(): void
    {
        $action = GenerationFixture::action(GenerationFixture::catalog());

        self::assertSame(['prometheus/slo-recording.rules.yml'], $this->paths($action(GenerationFixture::input(alerts: false))));
        self::assertSame(['prometheus/slo-alerts.rules.yml'], $this->paths($action(GenerationFixture::input(rules: false))));
        $none = $action(GenerationFixture::input(rules: false, alerts: false));
        self::assertSame([], $this->paths($none));
        self::assertSame(['prometheus'], $none->files->managedDirectories);
    }

    public function testAlertsWithoutRecordingRulesAreWarned(): void
    {
        $action = GenerationFixture::action(GenerationFixture::catalog());

        self::assertSame(
            ['generation.prometheus.alerts is enabled but generation.prometheus.rules is not: the alert rules read slo:* records that are not generated'],
            $action(GenerationFixture::input(rules: false))->warnings,
        );
        self::assertSame([], $action(GenerationFixture::input(alerts: false))->warnings);
        self::assertSame([], $action(GenerationFixture::input(rules: false, alerts: false))->warnings);
    }

    public function testAlertsReadTheWindowGroupRecordsOverALookback(): void
    {
        $alerts = $this->alerts(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input()));

        self::assertStringContainsString('last_over_time(slo:error_ratio:rate3d{application="shop",slo="checkout.pay",objective="availability"}[10m]) > 0.001', $alerts);
        self::assertStringContainsString('last_over_time(slo:window:coverage_ratio{application="shop",slo="checkout.pay",objective="availability"}[10m]) >= 1', $alerts);
        self::assertStringNotContainsString('last_over_time(slo:error_ratio:rate6h', $alerts);
    }

    public function testOmittedBurnRatePairsAreWarnedAndLeftOut(): void
    {
        $output = GenerationFixture::action(GenerationFixture::catalog(checkoutAvailabilityTarget: 90.0))(GenerationFixture::input());

        self::assertSame(['SLO checkout.pay availability: page burn-rate alert 1h/5m omitted, factor 14.4 x error budget 0.1 >= 1'], $output->warnings);
        self::assertStringNotContainsString('> 1.44', $this->alerts($output));
        self::assertStringContainsString('> 0.6', $this->alerts($output));
    }

    public function testConflictingDeclarationsGetNoRulesAndAWarning(): void
    {
        $catalog = CatalogFixture::build(static function ($assembler): void {
            $assembler->declare(PointId::of(PointType::Operation, 'a.b'), new Slo('a.b', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::Operation, 'a.b'), new Slo('a.b', [new Availability(99.5)]));
        });

        $output = GenerationFixture::action($catalog)(GenerationFixture::input());

        self::assertSame(['SLO a.b is declared differently on several points and gets no rules, see slo:validate'], $output->warnings);
        self::assertStringContainsString('groups: []', $this->recording($output));
        self::assertStringContainsString('groups: []', $this->alerts($output));
    }

    /**
     * @return list<string>
     */
    private function paths(Output $output): array
    {
        return array_map(static fn (GeneratedFile $file): string => $file->relativePath, $output->files->files);
    }

    private function recording(Output $output): string
    {
        return $this->contents($output, Action::RECORDING_FILE);
    }

    private function alerts(Output $output): string
    {
        return $this->contents($output, Action::ALERTS_FILE);
    }

    private function contents(Output $output, string $path): string
    {
        foreach ($output->files->files as $file) {
            if ($file->relativePath === $path) {
                return $file->contents;
            }
        }
        self::fail($path . ' was not generated');
    }
}

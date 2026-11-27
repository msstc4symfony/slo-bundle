<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerateRules;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
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
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use PHPUnit\Framework\Attributes\TestWith;
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
                $objective = $rule['labels']['objective'] ?? 'slo';
                self::assertIsString($objective);

                return $rule['record'] . ' ' . $objective . (isset($rule['labels']['revision']) ? ' B' : ' A');
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
        $unfinished = static fn (string ...$records): array => array_map(static fn (string $record): string => 'slo:unfinished_runs:' . $record . ' slo A', $records);
        self::assertSame([...$unfinished('step', 'increase5m', 'increase30m', 'increase1h', 'increase6h'), ...$fast('availability', 'B'), ...$fast('latency', 'B')], $groups['slo-cleanup.run']);
        self::assertSame([...$unfinished('increase3d', 'increase30d'), ...$slow('availability', 'B'), ...$slow('latency', 'B')], $groups['slo-cleanup.run-window']);
        self::assertSame([...$unfinished('increase3d', 'increase30d'), ...$slow('availability', 'B')], $groups['slo-partners.sync-window']);
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
                $sums = str_starts_with($rule['record'], 'slo:events:rate') || str_starts_with($rule['record'], 'slo:error_ratio:rate') || $rule['record'] === 'slo:sli:ratio';
                $reads = \in_array($rule['record'], [PromQlBuilder::UNFINISHED_RUNS_RECORD, 'slo:window:coverage_ratio'], true);
                self::assertSame($task && $reads, str_contains($rule['expr'], 'symfony_slo_scheduled_task_started_total'), $rule['record'] . ' of ' . $rule['labels']['slo']);
                $increase = str_starts_with($rule['record'], PromQlBuilder::UNFINISHED_RUNS_INCREASE_RECORD);
                self::assertSame($task && $sums, str_contains($rule['expr'], PromQlBuilder::UNFINISHED_RUNS_INCREASE_RECORD), $rule['record'] . ' of ' . $rule['labels']['slo']);
                self::assertSame($task && $increase, str_contains($rule['expr'], 'sum_over_time(slo:unfinished_runs:step{'), $rule['record'] . ' of ' . $rule['labels']['slo']);
            }
        }
    }

    /**
     * A read of an increase record nobody writes would silently be 0: a task that always dies would look healthy.
     */
    public function testEveryIncreaseRecordReadIsRecordedForItsSlo(): void
    {
        $recorded = [];
        $read = [];
        foreach ($this->rules(GenerationFixture::catalog()) as $rule) {
            $recorded[$rule['slo']][] = $rule['record'];
            preg_match_all('/(' . preg_quote(PromQlBuilder::UNFINISHED_RUNS_INCREASE_RECORD, '/') . '\w+)\{application="shop",slo="([^"]+)"/', $rule['expr'], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                self::assertSame($rule['slo'], $match[2]);
                $read[$match[2]][] = $match[1];
            }
        }

        self::assertSame(['cleanup.run', 'partners.sync'], array_keys($read));
        foreach ($read as $slo => $records) {
            self::assertSame([], array_values(array_diff($records, $recorded[$slo])), $slo);
        }
    }

    /**
     * An SLO window equal to a rate window is recorded once, in the group of that rate window.
     */
    #[TestWith(['1h'])]
    #[TestWith(['6h'])]
    #[TestWith(['3d'])]
    public function testAnSloWindowEqualToARateWindowIsRecordedOnce(string $window): void
    {
        $catalog = CatalogFixture::build(
            static function (CatalogAssembler $assembler) use ($window): void {
                $assembler->declare(PointId::of(PointType::ScheduledTask, GenerationFixture::CLEANUP_TASK), new Slo('cleanup.run', [new Availability(99), new Latency('120s', 95)], window: $window));
            },
            new CatalogExtras(scheduledTasks: [GenerationFixture::CLEANUP_TASK]),
        );
        $increases = array_values(array_filter(
            $this->rules($catalog),
            static fn (array $rule): bool => str_starts_with($rule['record'], PromQlBuilder::UNFINISHED_RUNS_INCREASE_RECORD),
        ));

        self::assertSame(
            ['5m' => 'slo-cleanup.run', '30m' => 'slo-cleanup.run', '1h' => 'slo-cleanup.run', '6h' => 'slo-cleanup.run', '3d' => 'slo-cleanup.run-window'],
            array_column(array_map(static fn (array $rule): array => [substr($rule['record'], \strlen(PromQlBuilder::UNFINISHED_RUNS_INCREASE_RECORD)), $rule['group']], $increases), 1, 0),
        );
        self::assertCount(5, $increases);
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
     * @return list<array{group: string, record: string, slo: string, expr: string}>
     */
    private function rules(CompiledSloCatalog $catalog): array
    {
        $recording = Yaml::parse($this->recording(GenerationFixture::action($catalog)(GenerationFixture::input())));
        self::assertIsArray($recording);
        self::assertIsArray($recording['groups']);
        $rules = [];
        foreach ($recording['groups'] as $group) {
            self::assertIsArray($group);
            self::assertIsString($group['name']);
            self::assertIsArray($group['rules']);
            foreach ($group['rules'] as $rule) {
                self::assertIsArray($rule);
                self::assertIsArray($rule['labels']);
                self::assertIsString($rule['record']);
                self::assertIsString($rule['expr']);
                self::assertIsString($rule['labels']['slo']);
                $rules[] = ['group' => $group['name'], 'record' => $rule['record'], 'slo' => $rule['labels']['slo'], 'expr' => $rule['expr']];
            }
        }

        return $rules;
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

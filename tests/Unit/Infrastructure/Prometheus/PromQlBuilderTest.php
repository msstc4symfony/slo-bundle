<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Prometheus;

use LogicException;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\Binding;
use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesKind;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use PHPUnit\Framework\TestCase;

final class PromQlBuilderTest extends TestCase
{
    private const HTTP_TOTAL = 'symfony_http_response{application="shop",component=~"web|worker",route=~"checkout_pay"}';

    private const HTTP_BAD = 'symfony_http_response{application="shop",component=~"web|worker",route=~"checkout_pay",status=~"5.."}';

    public function testCounterSeriesOfStrategyA(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('checkout.pay', 'availability');

        self::assertSame('sum(rate(' . self::HTTP_TOTAL . '[5m]))', $builder->eventsRate(GenerationFixture::input()->selector, $objective, '5m'));
        self::assertSame(
            '(sum(rate(' . self::HTTP_BAD . '[1h])) or vector(0)) / (sum(rate(' . self::HTTP_TOTAL . '[1h])) > 0)',
            $builder->errorRatio(GenerationFixture::input()->selector, $objective, '1h'),
        );
        self::assertSame(
            '1 - ((sum(increase(' . self::HTTP_BAD . '[30d])) or vector(0)) / (sum(increase(' . self::HTTP_TOTAL . '[30d])) > 0))',
            $builder->sliRatio(GenerationFixture::input()->selector, $objective),
        );
        self::assertSame(
            '1 - (1 - slo:sli:ratio{application="shop",slo="checkout.pay",objective="availability"}) / 0.001',
            $builder->budgetRemaining($objective, 'shop'),
        );
        self::assertSame(
            'clamp((time() - min_over_time(timestamp(sum(' . self::HTTP_TOTAL . '))[721h:1h])) / 2592000, 0, 1)',
            $builder->coverageRatio(GenerationFixture::input()->selector, $objective),
        );
        self::assertSame('(sum(increase(' . self::HTTP_TOTAL . '[6h])) or vector(0)) == 0', $builder->noEvents(GenerationFixture::input()->selector, $objective, 6));
    }

    public function testDedicatedCountersFilterTheCurrentRevisionAndSumOverResult(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('report.export', 'availability');
        $total = \sprintf('symfony_slo_events_total{application="shop",component=~"web|worker",slo="report.export",objective="availability",revision="%s"}', $objective->revision->value);
        $bad = substr($total, 0, -1) . ',result="bad"}';

        self::assertSame('sum(rate(' . $total . '[30m]))', $builder->eventsRate(GenerationFixture::input()->selector, $objective, '30m'));
        self::assertSame('(sum(rate(' . $bad . '[5m])) or vector(0)) / (sum(rate(' . $total . '[5m])) > 0)', $builder->errorRatio(GenerationFixture::input()->selector, $objective, '5m'));
        self::assertSame('1 - ((sum(increase(' . $bad . '[3d])) or vector(0)) / (sum(increase(' . $total . '[3d])) > 0))', $builder->sliRatio(GenerationFixture::input()->selector, $objective));
        self::assertSame('1 - (1 - slo:sli:ratio{application="shop",slo="report.export",objective="availability"}) / 0.01', $builder->budgetRemaining($objective, 'shop'));
        self::assertSame('clamp((time() - min_over_time(timestamp(sum(' . $total . '))[73h:1h])) / 259200, 0, 1)', $builder->coverageRatio(GenerationFixture::input()->selector, $objective));
    }

    public function testHistogramLatencyReadsTheBucketAgainstTheCount(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('payment.process', 'latency');
        $matchers = 'application="shop",message="ProcessPayment",status="handled",transport!~"failed"';
        $count = 'symfony_messenger_message_handling_duration_histogram_seconds_count{' . $matchers . '}';
        $bucket = 'symfony_messenger_message_handling_duration_histogram_seconds_bucket{' . $matchers . ',le="1.0"}';
        $selector = new Selector('shop', []);

        self::assertSame('sum(rate(' . $count . '[5m]))', $builder->eventsRate($selector, $objective, '5m'));
        self::assertSame(
            '(sum(rate(' . $count . '[5m])) - sum(rate(' . $bucket . '[5m]))) / (sum(rate(' . $count . '[5m])) > 0)',
            $builder->errorRatio($selector, $objective, '5m'),
        );
    }

    public function testHistogramAvailabilityReadsTheCountWithTheBadMatchers(): void
    {
        $objective = $this->custom(new SeriesBinding(
            'logic_action_duration_histogram_seconds',
            SeriesKind::Histogram,
            SeriesBinding::index([Matcher::equals('action', 'refund')]),
            SeriesBinding::index([Matcher::regex('outcome', 'success|expected_failure')]),
            SeriesBinding::index([Matcher::equals('outcome', 'error')]),
            null,
        ));

        self::assertSame(
            '(sum(rate(symfony_logic_action_duration_histogram_seconds_count{application="shop",action="refund",outcome="error"}[5m])) or vector(0)) / (sum(rate(symfony_logic_action_duration_histogram_seconds_count{application="shop",action="refund"}[5m])) > 0)',
            (new PromQlBuilder())->errorRatio(new Selector('shop', []), $objective, '5m'),
        );
    }

    public function testBadMatchersOverrideTheSameLabel(): void
    {
        $objective = $this->custom(new SeriesBinding(
            'http_response',
            SeriesKind::Counter,
            SeriesBinding::index([Matcher::regex('route', 'a'), Matcher::notRegex('status', '404')]),
            [],
            SeriesBinding::index([Matcher::regex('status', '5..')]),
            null,
        ));

        self::assertStringContainsString(
            'sum(rate(symfony_http_response{application="shop",route=~"a",status=~"5.."}[5m]))',
            (new PromQlBuilder())->errorRatio(new Selector('shop', []), $objective, '5m'),
        );
    }

    public function testNoBadMatchersMeansNoBadEvents(): void
    {
        $objective = $this->custom(new SeriesBinding('http_response', SeriesKind::Counter, SeriesBinding::index([Matcher::regex('route', 'a')]), [], [], null));

        self::assertSame(
            'vector(0) / (sum(rate(symfony_http_response{application="shop",route=~"a"}[5m])) > 0)',
            (new PromQlBuilder())->errorRatio(new Selector('shop', []), $objective, '5m'),
        );
    }

    public function testRecordedSeriesSelectorIsScopedToTheApplicationSloAndObjective(): void
    {
        self::assertSame(
            'slo:error_ratio:rate1h{application="shop",slo="checkout.pay",objective="availability"}',
            (new PromQlBuilder())->recorded('slo:error_ratio:rate1h', $this->objective('checkout.pay', 'availability'), 'shop'),
        );
    }

    public function testApplicationValuesAreEscaped(): void
    {
        self::assertStringContainsString(
            'application="sh\"op",component=~"a\\\\.b"',
            (new PromQlBuilder())->eventsRate(new Selector('sh"op', ['a.b']), $this->objective('checkout.pay', 'availability'), '5m'),
        );
    }

    public function testEveryObjectiveReadsTheIncreaseRecordOfItsSlo(): void
    {
        $builder = new PromQlBuilder();
        $selector = GenerationFixture::input()->selector;
        $expected = '(sum(slo:unfinished_runs:increase30d{application="shop",slo="cleanup.run",objective=""}) or vector(0))';

        self::assertSame($expected, $builder->unfinishedRuns($selector, $this->objective('cleanup.run', 'latency'), '30d'));
        self::assertSame($expected, $builder->unfinishedRuns($selector, $this->objective('cleanup.run', 'availability'), '30d'));
    }

    /**
     * objective="" leaves out the per-objective step records 1.2.0 wrote, which a window spanning the upgrade still holds.
     */
    public function testTheIncreaseRecordSumsTheStepRecordOfTheSloOnly(): void
    {
        self::assertSame(
            '(sum(sum_over_time(slo:unfinished_runs:step{application="shop",slo="cleanup.run",objective=""}[30d])) or vector(0))',
            (new PromQlBuilder())->unfinishedRunsIncrease($this->objective('cleanup.run', 'latency'), 'shop', '30d'),
        );
    }

    public function testOnlyTheIncreaseRecordsOfLongWindowsAreSlowRecords(): void
    {
        self::assertFalse(PromQlBuilder::isSlowRecord('slo:unfinished_runs:step'));
        self::assertFalse(PromQlBuilder::isSlowRecord('slo:unfinished_runs:increase6h'));
        self::assertTrue(PromQlBuilder::isSlowRecord('slo:unfinished_runs:increase3d'));
        self::assertTrue(PromQlBuilder::isSlowRecord('slo:unfinished_runs:increase30d'));
    }

    public function testTheStepOfUnfinishedRunsIsComputedPerSeriesFromInstantCounterValues(): void
    {
        $started = 'symfony_slo_scheduled_task_started_total{application="shop",component=~"web|worker",task="App\\\\Task\\\\CleanupTask::__invoke"}';
        $finished = 'symfony_slo_scheduled_task_finished_total{application="shop",component=~"web|worker",task="App\\\\Task\\\\CleanupTask::__invoke"}';
        $finishedAt = static fn (string $offset): string => '(sum without (outcome) (' . $finished . $offset . ') or (' . $started . $offset . ' * 0))';

        self::assertSame(
            '(sum(clamp_min((' . $started . ' offset 2m - ' . $started . ' offset 3m) - (' . $finishedAt('') . ' - ' . $finishedAt(' offset 3m') . '), 0)'
            . ' unless ignoring (outcome) (resets(' . $started . '[3m]) > 0 or resets(' . $finished . '[3m]) > 0)) or vector(0))',
            (new PromQlBuilder())->stepUnfinishedRuns(GenerationFixture::input()->selector, $this->objective('cleanup.run', 'latency'), '1m'),
        );
    }

    public function testASlothWindowStartsAtTheLastSubqueryStepBeforeIt(): void
    {
        $started = 'symfony_slo_scheduled_task_started_total{application="shop",task="command:app:sync-partners"}';
        $finished = 'symfony_slo_scheduled_task_finished_total{application="shop",task="command:app:sync-partners"}';
        $finishedAt = static fn (string $offset): string => '(sum without (outcome) (' . $finished . $offset . ') or (' . $started . $offset . ' * 0))';

        self::assertSame(
            '(sum(clamp_min((' . $started . ' offset 10m - last_over_time((' . $started . ' offset 10m)[1m:1m] offset {{.window}}))'
            . ' - (' . $finishedAt('') . ' - last_over_time(' . $finishedAt(' offset 10m') . '[1m:1m] offset {{.window}})), 0)'
            . ' unless ignoring (outcome) (resets(' . $started . '[{{.window}}]) > 0 or resets(' . $started . '[11m] offset {{.window}}) > 0'
            . ' or resets(' . $finished . '[{{.window}}]) > 0 or resets(' . $finished . '[11m] offset {{.window}}) > 0)) or vector(0))',
            (new PromQlBuilder())->unfinishedRuns(new Selector('shop', []), $this->objective('partners.sync', 'availability'), '{{.window}}'),
        );
    }

    public function testUnfinishedRunsAreAddedToTheBadAndTotalEventsOfEveryTaskObjective(): void
    {
        $builder = new PromQlBuilder();
        $selector = GenerationFixture::input()->selector;
        foreach (['availability', 'latency'] as $kind) {
            $objective = $this->objective('cleanup.run', $kind);
            $increases = [];
            foreach (['5m', '6h', '30d', '{{.window}}'] as $range) {
                $increases[$range] = $this->taskIncreases($builder, $selector, $objective, $range);
            }

            self::assertSame($increases['5m']['bad'] . ' / (' . $increases['5m']['total'] . ' > 0)', $builder->errorRatio($selector, $objective, '5m'));
            self::assertSame($increases['5m']['total'] . ' / 300', $builder->eventsRate($selector, $objective, '5m'));
            self::assertSame($increases['6h']['bad'] . ' / 21600', $builder->badRate($selector, $objective, '6h'));
            self::assertSame('1 - (' . $increases['30d']['bad'] . ' / (' . $increases['30d']['total'] . ' > 0))', $builder->sliRatio($selector, $objective));
            self::assertSame($increases['{{.window}}']['total'], $builder->eventsIncrease($selector, $objective, '{{.window}}'));
            self::assertSame($increases['{{.window}}']['bad'], $builder->badIncrease($selector, $objective, '{{.window}}'));
        }
    }

    public function testTheWindowStartIsTheDeadlinePlusTheRange(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('partners.sync', 'availability');

        foreach (['1h' => '70m', '30d' => '43210m'] as $range => $windowStart) {
            $unfinished = $builder->stepUnfinishedRuns(new Selector('shop', []), $objective, $range);
            self::assertNotNull($unfinished);
            self::assertSame(3, substr_count($unfinished, '} offset ' . $windowStart));
            self::assertSame(2, substr_count($unfinished, '}[' . $windowStart . ']'));
        }
    }

    public function testCoverageAndNoEventsOfATaskCountStartedRunsAsData(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('partners.sync', 'availability');
        $total = \sprintf('symfony_slo_events_total{application="shop",slo="partners.sync",objective="availability",revision="%s"}', $objective->revision->value);
        $started = 'symfony_slo_scheduled_task_started_total{application="shop",task="command:app:sync-partners"}';

        self::assertSame(
            'clamp((time() - min_over_time(timestamp(sum(' . $total . ') or sum(' . $started . '))[721h:1h])) / 2592000, 0, 1)',
            $builder->coverageRatio(new Selector('shop', []), $objective),
        );
        self::assertSame(
            '((sum(increase(' . $total . '[6h])) or vector(0)) + (sum(increase(' . $started . '[6h])) or vector(0))) == 0',
            $builder->noEvents(new Selector('shop', []), $objective, 6),
        );
    }

    public function testATaskSloOnSeveralPointsMatchesEveryTask(): void
    {
        $definition = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $slo = new Slo('nightly', [new Availability(99)], deadline: '90s');
            $assembler->declare(PointId::of(PointType::ScheduledTask, 'App\Task\A::__invoke'), $slo);
            $assembler->declare(PointId::of(PointType::ScheduledTask, 'command:app:b'), $slo);
        })->all()[0];
        $objective = new BoundObjective($definition, $definition->objectives()[0], Binding::dedicated(), Revision::fromHex('0123abcd'));

        $unfinished = (new PromQlBuilder())->stepUnfinishedRuns(new Selector('shop', []), $objective, '1m');

        self::assertNotNull($unfinished);
        self::assertStringContainsString(
            'symfony_slo_scheduled_task_started_total{application="shop",task=~"App\\\\\\\\Task\\\\\\\\A\\\\:\\\\:__invoke|command\\\\:app\\\\:b"} offset 90s -',
            $unfinished,
        );
    }

    public function testOtherPointTypesHaveNoUnfinishedRuns(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('report.export', 'availability');

        self::assertNull($builder->unfinishedRuns(GenerationFixture::input()->selector, $objective, '5m'));
        self::assertNull($builder->stepUnfinishedRuns(GenerationFixture::input()->selector, $objective, '1m'));
        $total = \sprintf('symfony_slo_events_total{application="shop",component=~"web|worker",slo="report.export",objective="availability",revision="%s"}', $objective->revision->value);
        self::assertSame('sum(increase(' . $total . '[{{.window}}]))', $builder->eventsIncrease(GenerationFixture::input()->selector, $objective, '{{.window}}'));
        self::assertSame('(sum(increase(' . substr($total, 0, -1) . ',result="bad"}[{{.window}}])) or vector(0))', $builder->badIncrease(GenerationFixture::input()->selector, $objective, '{{.window}}'));
    }

    /**
     * The expected bad and total increases of a task objective: events plus unfinished runs, the total falling back
     * to the unfinished runs alone.
     *
     * @param non-empty-string $range
     *
     * @return array{bad: non-empty-string, total: non-empty-string}
     */
    private function taskIncreases(PromQlBuilder $builder, Selector $selector, BoundObjective $objective, string $range): array
    {
        $unfinished = $builder->unfinishedRuns($selector, $objective, $range);
        self::assertNotNull($unfinished);
        $total = \sprintf('symfony_slo_events_total{application="shop",component=~"web|worker",slo="%s",objective="%s",revision="%s"}', $objective->definition->name()->value, $objective->objective->kind->value, $objective->revision->value);
        $bad = substr($total, 0, -1) . ',result="bad"}';

        return [
            'bad' => '((sum(increase(' . $bad . '[' . $range . '])) or vector(0)) + ' . $unfinished . ')',
            'total' => '((sum(increase(' . $total . '[' . $range . '])) + ' . $unfinished . ') or (' . $unfinished . ' > 0))',
        ];
    }

    private function objective(string $slo, string $kind): BoundObjective
    {
        $catalog = GenerationFixture::catalog();
        foreach (GenerationFixture::collector($catalog)->collect() as $bound) {
            if ($bound->definition->name()->value === $slo && $bound->objective->kind->value === $kind) {
                return $bound;
            }
        }

        throw new LogicException(\sprintf('No %s objective on %s.', $kind, $slo));
    }

    private function custom(SeriesBinding $series): BoundObjective
    {
        $definition = CatalogFixture::build(static fn ($assembler) => $assembler->declare(PointId::of(PointType::LogicAction, 'App\Refund'), new Slo('refund', [new Availability(99)])))->all()[0];

        return new BoundObjective($definition, $definition->objectives()[0], Binding::existingSeries($series), Revision::fromHex('0123abcd'));
    }
}

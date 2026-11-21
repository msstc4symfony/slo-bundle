<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesKind;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * PromQL over the exported series: Strategy A reads metrics-bundle series, Strategy B the dedicated counter of the
 * current revision. Every ratio divides by `(total > 0)`, so no events yield no value instead of 100 %.
 *
 * @phpstan-type SeriesShape array{total: non-empty-string, bad: non-empty-string|null, bucket: non-empty-string|null}
 *
 * @internal
 */
final readonly class PromQlBuilder
{
    /** Two runs of the 5m group: one missed evaluation still finds a sample. */
    public const SLOW_RECORD_LOOKBACK = '10m';

    private const SLOW_RECORDS = ['slo:events:rate3d', 'slo:error_ratio:rate3d', 'slo:sli:ratio', 'slo:error_budget:remaining', 'slo:window:coverage_ratio'];

    private const NAMESPACE = 'symfony_';

    private const EVENTS_METRIC = 'symfony_slo_events_total';

    private const HOUR = 3600;

    /**
     * @param non-empty-string $range
     */
    public function eventsRate(Selector $selector, BoundObjective $objective, string $range): string
    {
        return $this->sum('rate', $this->series($selector, $objective)['total'], $range);
    }

    /**
     * @param non-empty-string $range
     */
    public function badRate(Selector $selector, BoundObjective $objective, string $range): string
    {
        return $this->bad($this->series($selector, $objective), 'rate', $range);
    }

    /**
     * @param non-empty-string $range
     */
    public function errorRatio(Selector $selector, BoundObjective $objective, string $range): string
    {
        $series = $this->series($selector, $objective);

        return \sprintf('%s / (%s > 0)', $this->bad($series, 'rate', $range), $this->sum('rate', $series['total'], $range));
    }

    public function sliRatio(Selector $selector, BoundObjective $objective): string
    {
        $series = $this->series($selector, $objective);
        $window = PromQlRenderer::window($objective->definition->window());

        return \sprintf('1 - (%s / (%s > 0))', $this->bad($series, 'increase', $window), $this->sum('increase', $series['total'], $window));
    }

    /**
     * Derived from the slo:sli:ratio recorded earlier in the same group: no SLI, no budget.
     *
     * @param non-empty-string $application
     */
    public function budgetRemaining(BoundObjective $objective, string $application): string
    {
        return \sprintf(
            '1 - (1 - %s) / %s',
            $this->recorded('slo:sli:ratio', $objective, $application),
            PromQlRenderer::number($objective->objective->target->errorBudgetRatio()),
        );
    }

    /**
     * Share of the window since the first sample (1h resolution), so long-window alerts wait for a full window.
     * The subquery looks one step past the window, so data older than the window reaches 1.
     */
    public function coverageRatio(Selector $selector, BoundObjective $objective): string
    {
        $seconds = $objective->definition->window()->seconds();

        return \sprintf(
            'clamp((time() - min_over_time(timestamp(sum(%s))[%dh:1h])) / %d, 0, 1)',
            $this->series($selector, $objective)['total'],
            intdiv($seconds, self::HOUR) + 1,
            $seconds,
        );
    }

    /**
     * @param positive-int $hours
     */
    public function noEvents(Selector $selector, BoundObjective $objective, int $hours): string
    {
        return \sprintf('(%s or vector(0)) == 0', $this->sum('increase', $this->series($selector, $objective)['total'], $hours . 'h'));
    }

    /**
     * The newest sample of a recorded series. Records of the 5m "-window" group are read over a lookback: an alert or a
     * dashboard evaluated between two runs of that group would otherwise see NoData.
     *
     * @param non-empty-string $record
     * @param non-empty-string $application
     *
     * @return non-empty-string
     */
    public function latest(string $record, BoundObjective $objective, string $application): string
    {
        return self::lookback($record, $this->recorded($record, $objective, $application));
    }

    /**
     * @param non-empty-string $record
     * @param non-empty-string $selector a selector of $record
     *
     * @return non-empty-string
     */
    public static function lookback(string $record, string $selector): string
    {
        return self::isSlowRecord($record) ? \sprintf('last_over_time(%s[%s])', $selector, self::SLOW_RECORD_LOOKBACK) : $selector;
    }

    /**
     * True for the records RuleGroupsBuilder puts into the 5m "-window" group.
     */
    public static function isSlowRecord(string $record): bool
    {
        return \in_array($record, self::SLOW_RECORDS, true);
    }

    /**
     * @param non-empty-string $record
     * @param non-empty-string $application value of the `application` label: the recorded series carry it as a rule label
     *
     * @return non-empty-string
     */
    public function recorded(string $record, BoundObjective $objective, string $application): string
    {
        return $record . $this->matchers([
            Matcher::equals('application', $application),
            Matcher::equals('slo', $objective->definition->name()->value),
            Matcher::equals('objective', $objective->objective->kind->value),
        ]);
    }

    /**
     * @return SeriesShape
     */
    private function series(Selector $selector, BoundObjective $objective): array
    {
        $base = [Matcher::equals('application', $selector->application)];
        if ($selector->components !== []) {
            $base[] = Matcher::regex('component', PromQlRenderer::alternation($selector->components));
        }

        $series = $objective->binding->series;
        if (!$series instanceof SeriesBinding) {
            $own = [
                ...$base,
                Matcher::equals('slo', $objective->definition->name()->value),
                Matcher::equals('objective', $objective->objective->kind->value),
                Matcher::equals('revision', $objective->revision->value),
            ];

            return [
                'total' => self::EVENTS_METRIC . $this->matchers($own),
                'bad' => self::EVENTS_METRIC . $this->matchers([...$own, Matcher::equals('result', 'bad')]),
                'bucket' => null,
            ];
        }

        $metric = self::NAMESPACE . $series->metric;
        $counted = $series->kind === SeriesKind::Histogram ? $metric . '_count' : $metric;
        $total = $counted . $this->matchers([...$base, ...array_values($series->matchers)]);

        if ($series->bucketBound !== null) {
            return [
                'total' => $total,
                'bad' => null,
                'bucket' => $metric . '_bucket' . $this->matchers([...$base, ...array_values($series->matchers), Matcher::equals('le', $series->bucketBound)]),
            ];
        }

        $bad = null;
        if ($series->badMatchers !== []) {
            $merged = array_replace($series->matchers, $series->badMatchers);
            ksort($merged, SORT_STRING);
            $bad = $counted . $this->matchers([...$base, ...array_values($merged)]);
        }

        return ['total' => $total, 'bad' => $bad, 'bucket' => null];
    }

    /**
     * @param SeriesShape $series
     * @param 'rate'|'increase' $function
     * @param non-empty-string $range
     */
    private function bad(array $series, string $function, string $range): string
    {
        if ($series['bucket'] !== null) {
            return \sprintf('(%s - %s)', $this->sum($function, $series['total'], $range), $this->sum($function, $series['bucket'], $range));
        }
        if ($series['bad'] !== null) {
            // The bad series does not exist before the first bad event.
            return \sprintf('(%s or vector(0))', $this->sum($function, $series['bad'], $range));
        }

        return 'vector(0)';
    }

    /**
     * @param 'rate'|'increase' $function
     * @param non-empty-string $selector
     * @param non-empty-string $range
     */
    private function sum(string $function, string $selector, string $range): string
    {
        return \sprintf('sum(%s(%s[%s]))', $function, $selector, $range);
    }

    /**
     * @param list<Matcher> $matchers
     *
     * @return non-empty-string
     */
    private function matchers(array $matchers): string
    {
        return PromQlRenderer::selector($matchers);
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesKind;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Deadline;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\SloMetric;

/**
 * PromQL over the exported series: Strategy A reads metrics-bundle series, Strategy B the dedicated counter of the
 * current revision. Every ratio divides by `(total > 0)`, so no events yield no value instead of 100 %.
 * Scheduled-task objectives add the unfinished runs to their bad and total events, in increase form.
 *
 * @phpstan-type SeriesShape array{total: non-empty-string, bad: non-empty-string|null, bucket: non-empty-string|null}
 *
 * @internal
 */
final readonly class PromQlBuilder
{
    /** Two runs of the 5m group: one missed evaluation still finds a sample. */
    public const SLOW_RECORD_LOOKBACK = '10m';

    /** Per-step unfinished runs of a scheduled-task objective, recorded by the 1m group and summed by every window. */
    public const UNFINISHED_RUNS_RECORD = 'slo:unfinished_runs:step';

    private const SLOW_RECORDS = ['slo:events:rate3d', 'slo:error_ratio:rate3d', 'slo:sli:ratio', 'slo:error_budget:remaining', 'slo:window:coverage_ratio'];

    private const NAMESPACE = 'symfony_';

    private const EVENTS_METRIC = 'symfony_slo_events_total';

    private const HOUR = 3600;

    /** The Sloth form samples its window edge at the step of the unfinished-runs record. */
    private const SUBQUERY_STEP_MILLISECONDS = Deadline::UNFINISHED_RUN_STEP_MILLISECONDS;

    /** A range Sloth fills in, such as `{{.window}}`. */
    private const TEMPLATE = '/^\{\{\.[a-z]+\}\}$/Di';

    /**
     * @param non-empty-string $range
     */
    public function eventsRate(Selector $selector, BoundObjective $objective, string $range): string
    {
        if ($this->isScheduledTask($objective)) {
            return \sprintf('%s / %d', $this->eventsIncrease($selector, $objective, $range), PromQlRenderer::seconds($range));
        }

        return $this->sum('rate', $this->series($selector, $objective)['total'], $range);
    }

    /**
     * @param non-empty-string $range
     */
    public function badRate(Selector $selector, BoundObjective $objective, string $range): string
    {
        if ($this->isScheduledTask($objective)) {
            return \sprintf('%s / %d', $this->badIncrease($selector, $objective, $range), PromQlRenderer::seconds($range));
        }

        return $this->bad($this->series($selector, $objective), 'rate', $range);
    }

    /**
     * @param non-empty-string $range
     */
    public function errorRatio(Selector $selector, BoundObjective $objective, string $range): string
    {
        if ($this->isScheduledTask($objective)) {
            return \sprintf('%s / (%s > 0)', $this->badIncrease($selector, $objective, $range), $this->eventsIncrease($selector, $objective, $range));
        }

        $series = $this->series($selector, $objective);

        return \sprintf('%s / (%s > 0)', $this->bad($series, 'rate', $range), $this->sum('rate', $series['total'], $range));
    }

    public function sliRatio(Selector $selector, BoundObjective $objective): string
    {
        $window = PromQlRenderer::window($objective->definition->window());

        return \sprintf('1 - (%s / (%s > 0))', $this->badIncrease($selector, $objective, $window), $this->eventsIncrease($selector, $objective, $window));
    }

    /**
     * Events over the range. A scheduled task adds its unfinished runs; before its first event the unfinished runs
     * alone are the total, so a task that always dies reads 100 % bad, and no runs at all still read NoData.
     *
     * @param non-empty-string $range
     */
    public function eventsIncrease(Selector $selector, BoundObjective $objective, string $range): string
    {
        $total = $this->sum('increase', $this->series($selector, $objective)['total'], $range);
        $unfinished = $this->unfinishedRuns($selector, $objective, $range);

        return $unfinished === null ? $total : \sprintf('((%1$s + %2$s) or (%2$s > 0))', $total, $unfinished);
    }

    /**
     * @param non-empty-string $range
     */
    public function badIncrease(Selector $selector, BoundObjective $objective, string $range): string
    {
        $bad = $this->bad($this->series($selector, $objective), 'increase', $range);
        $unfinished = $this->unfinishedRuns($selector, $objective, $range);

        return $unfinished === null ? $bad : \sprintf('(%s + %s)', $bad, $unfinished);
    }

    /**
     * Unfinished runs over the range. Prometheus rules sum the step record (stepUnfinishedRuns), so a long window needs
     * only d + step of series lifetime; a Sloth `{{.window}}` cannot read the bundle's records and gets the direct form.
     *
     * @param non-empty-string $range
     *
     * @return non-empty-string|null null off scheduled tasks
     */
    public function unfinishedRuns(Selector $selector, BoundObjective $objective, string $range): ?string
    {
        if (!$this->isScheduledTask($objective)) {
            return null;
        }
        if (preg_match(self::TEMPLATE, $range) === 1) {
            return $this->directUnfinishedRuns($selector, $objective, $range);
        }

        return \sprintf('(sum(sum_over_time(%s[%s])) or vector(0))', $this->recorded(self::UNFINISHED_RUNS_RECORD, $objective, $selector->application), $range);
    }

    /**
     * Runs that ended their deadline in the last step without finishing: recorded once per rule evaluation, so a killed
     * run is one increment of 1 at about its start + d.
     *
     * @param non-empty-string $step the evaluation interval of the group recording it
     *
     * @return non-empty-string|null null off scheduled tasks
     */
    public function stepUnfinishedRuns(Selector $selector, BoundObjective $objective, string $step): ?string
    {
        return $this->directUnfinishedRuns($selector, $objective, $step);
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
     * The subquery looks one step past the window, so data older than the window reaches 1. Started runs of a
     * scheduled task are data too: a task that never finishes has no event series.
     */
    public function coverageRatio(Selector $selector, BoundObjective $objective): string
    {
        $seconds = $objective->definition->window()->seconds();

        $data = \sprintf('sum(%s)', $this->series($selector, $objective)['total']);
        $runs = $this->runSeries($selector, $objective);
        if ($runs !== null) {
            $data .= \sprintf(' or sum(%s)', $runs['started']);
        }

        return \sprintf(
            'clamp((time() - min_over_time(timestamp(%s)[%dh:1h])) / %d, 0, 1)',
            $data,
            intdiv($seconds, self::HOUR) + 1,
            $seconds,
        );
    }

    /**
     * @param positive-int $hours
     */
    public function noEvents(Selector $selector, BoundObjective $objective, int $hours): string
    {
        $events = \sprintf('(%s or vector(0))', $this->sum('increase', $this->series($selector, $objective)['total'], $hours . 'h'));
        $runs = $this->runSeries($selector, $objective);
        if ($runs !== null) {
            $events = \sprintf('(%s + (%s or vector(0)))', $events, $this->sum('increase', $runs['started'], $hours . 'h'));
        }

        return $events . ' == 0';
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
     * Runs started in [t-d-r, t-d] minus runs finished in [t-d-r, t], d the run deadline (ruling R1), from instant
     * counter values (increase() extrapolates each window by its own factor). Computed per series and summed after:
     * a series missing at one of the instants (outage, fresh Prometheus, replica churn) or reset within [t-d-r, t]
     * drops out, so detection is lost for that range but never invented. A task that never finished has no finished
     * series: where the started series exists, finished counts as 0. A `{{.window}}` cannot be added to d, so the
     * window start is the last subquery step τ (one unfinished-runs step) at or before t-r, the same edge for started
     * and finished; the reset check reaches one step further back to cover τ-d.
     *
     * @param non-empty-string $range
     *
     * @return non-empty-string|null
     */
    private function directUnfinishedRuns(Selector $selector, BoundObjective $objective, string $range): ?string
    {
        $deadline = $objective->definition->runDeadline();
        $runs = $this->runSeries($selector, $objective);
        if (!$deadline instanceof Deadline || $runs === null) {
            return null;
        }

        ['started' => $started, 'finished' => $finished] = $runs;
        $d = PromQlRenderer::milliseconds($deadline->milliseconds);
        $finishedAt = static fn (string $offset): string => \sprintf('(sum without (outcome) (%2$s%3$s) or (%1$s%3$s * 0))', $started, $finished, $offset);

        if (preg_match(self::TEMPLATE, $range) === 1) {
            $step = PromQlRenderer::milliseconds(self::SUBQUERY_STEP_MILLISECONDS);
            $startedAtStart = \sprintf('last_over_time((%s offset %s)[%s:%s] offset %s)', $started, $d, $step, $step, $range);
            $finishedAtStart = \sprintf('last_over_time(%s[%s:%s] offset %s)', $finishedAt(' offset ' . $d), $step, $step, $range);
            $reset = \sprintf(
                'resets(%1$s[%3$s]) > 0 or resets(%1$s[%4$s] offset %3$s) > 0 or resets(%2$s[%3$s]) > 0 or resets(%2$s[%4$s] offset %3$s) > 0',
                $started,
                $finished,
                $range,
                PromQlRenderer::milliseconds($deadline->milliseconds + self::SUBQUERY_STEP_MILLISECONDS),
            );
        } else {
            $dr = PromQlRenderer::milliseconds(PromQlRenderer::seconds($range) * 1000 + $deadline->milliseconds);
            $startedAtStart = \sprintf('%s offset %s', $started, $dr);
            $finishedAtStart = $finishedAt(' offset ' . $dr);
            $reset = \sprintf('resets(%1$s[%3$s]) > 0 or resets(%2$s[%3$s]) > 0', $started, $finished, $dr);
        }

        return \sprintf(
            '(sum(clamp_min((%s offset %s - %s) - (%s - %s), 0) unless ignoring (outcome) (%s)) or vector(0))',
            $started,
            $d,
            $startedAtStart,
            $finishedAt(''),
            $finishedAtStart,
            $reset,
        );
    }

    /**
     * @return SeriesShape
     */
    private function series(Selector $selector, BoundObjective $objective): array
    {
        $base = $this->base($selector);

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
     * @return non-empty-list<Matcher>
     */
    private function base(Selector $selector): array
    {
        $base = [Matcher::equals('application', $selector->application)];
        if ($selector->components !== []) {
            $base[] = Matcher::regex('component', PromQlRenderer::alternation($selector->components));
        }

        return $base;
    }

    /**
     * Started and finished run counters of the SLO's tasks, summed over `outcome`; null off scheduled tasks.
     *
     * @return array{started: non-empty-string, finished: non-empty-string}|null
     */
    private function runSeries(Selector $selector, BoundObjective $objective): ?array
    {
        if (!$this->isScheduledTask($objective)) {
            return null;
        }

        $tasks = array_map(static fn (PointId $point): string => $point->id, $objective->definition->points());
        sort($tasks, SORT_STRING);
        $matchers = $this->matchers([
            ...$this->base($selector),
            \count($tasks) === 1 ? Matcher::equals('task', $tasks[0]) : Matcher::regex('task', PromQlRenderer::alternation($tasks)),
        ]);

        return [
            'started' => self::NAMESPACE . SloMetric::SCHEDULED_TASK_STARTED->value . $matchers,
            'finished' => self::NAMESPACE . SloMetric::SCHEDULED_TASK_FINISHED->value . $matchers,
        ];
    }

    private function isScheduledTask(BoundObjective $objective): bool
    {
        return $objective->definition->pointType() === PointType::ScheduledTask;
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

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * PromQL over the exported series: Strategy A reads metrics-bundle series, Strategy B the dedicated counter of the
 * current revision. Every ratio divides by `(total > 0)`, so no events yield no value instead of 100 %.
 *
 * @phpstan-type SeriesShape array{total: non-empty-string, bad: non-empty-string|null, bucket: non-empty-string|null}
 */
final readonly class PromQlBuilder
{
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
    public function errorRatio(Selector $selector, BoundObjective $objective, string $range): string
    {
        $series = $this->series($selector, $objective);

        return \sprintf('%s / (%s > 0)', $this->bad($series, 'rate', $range), $this->sum('rate', $series['total'], $range));
    }

    public function sliRatio(Selector $selector, BoundObjective $objective): string
    {
        $series = $this->series($selector, $objective);
        $window = $objective->definition->window()->toPromQl();

        return \sprintf('1 - (%s / (%s > 0))', $this->bad($series, 'increase', $window), $this->sum('increase', $series['total'], $window));
    }

    /**
     * Derived from the slo:sli:ratio recorded earlier in the same group: no SLI, no budget.
     */
    public function budgetRemaining(BoundObjective $objective): string
    {
        return \sprintf(
            '1 - (1 - %s) / %s',
            $this->recorded('slo:sli:ratio', $objective),
            BurnRatePolicy::number($objective->objective->target->errorBudgetRatio()),
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
     * @param non-empty-string $record
     *
     * @return non-empty-string
     */
    public function recorded(string $record, BoundObjective $objective): string
    {
        return $record . $this->matchers([
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
            $base[] = Matcher::regex('component', implode('|', array_map(Matcher::escapeRegex(...), $selector->components)));
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
        $counted = $series->kind === SeriesBinding::KIND_HISTOGRAM ? $metric . '_count' : $metric;
        $total = $counted . $this->matchers([...$base, ...array_values($series->matchers)]);

        if ($series->le !== null) {
            return [
                'total' => $total,
                'bad' => null,
                'bucket' => $metric . '_bucket' . $this->matchers([...$base, ...array_values($series->matchers), Matcher::equals('le', $series->le)]),
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
        return '{' . implode(',', array_map(static fn (Matcher $matcher): string => $matcher->toPromQl(), $matchers)) . '}';
    }
}

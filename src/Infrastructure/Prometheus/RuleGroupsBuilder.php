<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

use Closure;
use Msstc4Symfony\SloBundle\Application\Binding\Strategy;
use Msstc4Symfony\SloBundle\Application\Generation\AlertNames;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRateSeverity;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRateWindow;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\RuleFileBuilderInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Deadline;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Override;

/**
 * Recording rules and multi-window burn-rate alerts. Per SLO: `slo-<name>` every minute (short rates, target, alerts) and
 * `slo-<name>-window` every 5 minutes (3d rates, SLI, budget, coverage: scans over the whole window).
 *
 * @phpstan-type RuleShape array{record?: non-empty-string, alert?: non-empty-string, expr: string, labels: array<non-empty-string, string>, annotations?: array{summary: non-empty-string}}
 *
 * @internal
 */
final readonly class RuleGroupsBuilder implements RuleFileBuilderInterface
{
    private const FAST = 'fast';

    private const SLOW = 'slow';

    /**
     * Group key => name suffix.
     */
    private const GROUPS = [self::FAST => '', self::SLOW => '-window'];

    private const SLOW_INTERVAL = '5m';

    public function __construct(
        private PromQlBuilder $promQl,
        private BurnRatePolicy $burnRates,
        private DeterministicYaml $yaml,
    ) {
    }

    #[Override]
    public function recordingRules(Selector $selector, array $objectives): string
    {
        $perSlo = [];

        return $this->document($objectives, function (BoundObjective $objective) use ($selector, &$perSlo): array {
            $labels = $this->recordingLabels($objective, $selector->application);
            $record = $this->record(...);
            $fast = [];
            $slow = [];
            // The SLO's first objective records its unfinished runs, first in each group: every objective reads them.
            $name = $objective->definition->name()->value;
            if (!isset($perSlo[$name])) {
                $perSlo[$name] = true;
                ['fast' => $fast, 'slow' => $slow] = $this->unfinishedRunRecords($selector, $objective);
            }
            foreach (PromQlBuilder::FAST_RATE_WINDOWS as $window) {
                $fast[] = $record($labels, 'slo:events:rate' . $window, $this->promQl->eventsRate($selector, $objective, $window));
                $fast[] = $record($labels, 'slo:error_ratio:rate' . $window, $this->promQl->errorRatio($selector, $objective, $window));
            }
            $fast[] = $record($labels, 'slo:objective:target', \sprintf('vector(%s)', PromQlRenderer::number($objective->objective->target->percent / 100)));

            foreach (PromQlBuilder::SLOW_RATE_WINDOWS as $window) {
                $slow[] = $record($labels, 'slo:events:rate' . $window, $this->promQl->eventsRate($selector, $objective, $window));
                $slow[] = $record($labels, 'slo:error_ratio:rate' . $window, $this->promQl->errorRatio($selector, $objective, $window));
            }
            // Rules of a group run in order: the budget reads the ratio recorded just above.
            $slow[] = $record($labels, 'slo:sli:ratio', $this->promQl->sliRatio($selector, $objective));
            $slow[] = $record($labels, 'slo:error_budget:remaining', $this->promQl->budgetRemaining($objective, $selector->application));
            $slow[] = $record($labels, 'slo:window:coverage_ratio', $this->promQl->coverageRatio($selector, $objective));

            return [self::FAST => $fast, self::SLOW => $slow];
        });
    }

    #[Override]
    public function alertRules(Selector $selector, array $objectives, ?int $noEventsAlertHours): string
    {
        return $this->document($objectives, function (BoundObjective $objective) use ($selector, $noEventsAlertHours): array {
            $target = $objective->objective->target;
            $windows = $this->burnRates->windows($objective->definition->window(), $target);
            $rules = [];

            $page = array_map(fn (BurnRateWindow $pair): string => $this->burning($objective, $pair, $selector->application), array_values(array_filter($windows, static fn (BurnRateWindow $pair): bool => $pair->severity === BurnRateSeverity::Page)));
            if ($page !== []) {
                $rules[] = $this->alert(AlertNames::BURN, implode(' or ', $page), $objective, $selector->application, BurnRateSeverity::Page, 'burns its error budget fast');
            }
            foreach ($windows as $pair) {
                if ($pair->severity === BurnRateSeverity::Ticket) {
                    // Fast-burn pages need at most 6h of history; the 3d pair waits for a fully covered window.
                    $expr = \sprintf('%s and on() (%s >= 1)', $this->burning($objective, $pair, $selector->application), $this->promQl->latest('slo:window:coverage_ratio', $objective, $selector->application));
                    $rules[] = $this->alert(AlertNames::BURN, $expr, $objective, $selector->application, BurnRateSeverity::Ticket, 'burns its error budget');
                }
            }
            if ($noEventsAlertHours !== null) {
                $rules[] = $this->alert(AlertNames::NO_EVENTS, $this->promQl->noEvents($selector, $objective, $noEventsAlertHours), $objective, $selector->application, BurnRateSeverity::Ticket, \sprintf('recorded no events in %dh', $noEventsAlertHours));
            }

            return [self::FAST => $rules];
        });
    }

    /**
     * @param list<BoundObjective> $objectives sorted by SLO name
     * @param Closure(BoundObjective): array<self::FAST|self::SLOW, list<RuleShape>> $rules
     */
    private function document(array $objectives, Closure $rules): string
    {
        $bySlo = [];
        foreach ($objectives as $objective) {
            $name = $objective->definition->name()->value;
            foreach ($rules($objective) as $key => $groupRules) {
                $bySlo[$name][$key] = [...$bySlo[$name][$key] ?? [], ...$groupRules];
            }
        }

        $groups = [];
        foreach ($bySlo as $name => $byKey) {
            foreach (self::GROUPS as $key => $suffix) {
                if (($byKey[$key] ?? []) !== []) {
                    $interval = $key === self::FAST ? $this->fastInterval() : self::SLOW_INTERVAL;
                    $groups[] = ['name' => 'slo-' . $name . $suffix, 'interval' => $interval, 'rules' => $byKey[$key]];
                }
            }
        }

        return $this->yaml->dump(['groups' => $groups]);
    }

    /**
     * Per SLO: the step record and its increase over every range the objectives read (rate windows and the SLO window).
     *
     * @return array{fast: list<RuleShape>, slow: list<RuleShape>}
     */
    private function unfinishedRunRecords(Selector $selector, BoundObjective $objective): array
    {
        $step = $this->promQl->stepUnfinishedRuns($selector, $objective, $this->fastInterval());
        if ($step === null) {
            return ['fast' => [], 'slow' => []];
        }
        $labels = $this->sloLabels($objective, $selector->application);
        $slowRanges = array_diff(array_unique([...PromQlBuilder::SLOW_RATE_WINDOWS, PromQlRenderer::window($objective->definition->window())]), PromQlBuilder::FAST_RATE_WINDOWS);
        $records = ['fast' => [$this->record($labels, PromQlBuilder::UNFINISHED_RUNS_RECORD, $step)], 'slow' => []];
        foreach (['fast' => PromQlBuilder::FAST_RATE_WINDOWS, 'slow' => $slowRanges] as $group => $ranges) {
            foreach ($ranges as $range) {
                $records[$group][] = $this->record($labels, PromQlBuilder::UNFINISHED_RUNS_INCREASE_RECORD . $range, $this->promQl->unfinishedRunsIncrease($objective, $selector->application, $range));
            }
        }

        return $records;
    }

    /**
     * The unfinished-runs record is evaluated once per step, so the group interval is the step the runtime cut-off uses.
     *
     * @return non-empty-string
     */
    private function fastInterval(): string
    {
        return PromQlRenderer::milliseconds(Deadline::UNFINISHED_RUN_STEP_MILLISECONDS);
    }

    /**
     * @param array<non-empty-string, string> $labels
     * @param non-empty-string $name
     *
     * @return RuleShape
     */
    private function record(array $labels, string $name, string $expr): array
    {
        return ['record' => $name, 'expr' => $expr, 'labels' => $labels];
    }

    /**
     * @param non-empty-string $application
     *
     * @return array<non-empty-string, string>
     */
    private function recordingLabels(BoundObjective $objective, string $application): array
    {
        $labels = [...$this->sloLabels($objective, $application), 'objective' => $objective->objective->kind->value];
        if ($objective->binding->strategy === Strategy::Dedicated) {
            $labels['revision'] = $objective->revision->value;
        }

        return $labels;
    }

    /**
     * Labels of the records kept once per SLO.
     *
     * @param non-empty-string $application
     *
     * @return array<non-empty-string, string>
     */
    private function sloLabels(BoundObjective $objective, string $application): array
    {
        return [
            'application' => $application,
            'slo' => $objective->definition->name()->value,
            'window' => $objective->definition->window()->toString(),
        ];
    }

    /**
     * @param non-empty-string $application
     */
    private function burning(BoundObjective $objective, BurnRateWindow $pair, string $application): string
    {
        $threshold = PromQlRenderer::number($pair->threshold($objective->objective->target));

        return \sprintf(
            '(%s > %s and %s > %s)',
            $this->promQl->latest('slo:error_ratio:rate' . PromQlRenderer::duration($pair->longWindowSeconds), $objective, $application),
            $threshold,
            $this->promQl->latest('slo:error_ratio:rate' . PromQlRenderer::duration($pair->shortWindowSeconds), $objective, $application),
            $threshold,
        );
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $application
     *
     * @return RuleShape
     */
    private function alert(string $name, string $expr, BoundObjective $objective, string $application, BurnRateSeverity $severity, string $what): array
    {
        $slo = $objective->definition->name()->value;
        $kind = $objective->objective->kind->value;

        return [
            'alert' => $name,
            'expr' => $expr,
            'labels' => ['application' => $application, 'severity' => $severity->value, 'slo' => $slo, 'objective' => $kind],
            'annotations' => ['summary' => \sprintf(
                'SLO %s %s %s (target %s%%, window %s)',
                $slo,
                $kind,
                $what,
                PromQlRenderer::number($objective->objective->target->percent),
                $objective->definition->window()->toString(),
            )],
        ];
    }
}

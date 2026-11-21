<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

use Closure;
use Msstc4Symfony\SloBundle\Application\Binding\Strategy;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRateWindow;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\RuleFileBuilderInterface;
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
    private const FAST_RATE_WINDOWS = ['5m', '30m', '1h', '6h'];

    /**
     * Long-range records are expensive; they run in the 5m window group.
     */
    private const SLOW_RATE_WINDOWS = ['3d'];

    private const FAST = 'fast';

    private const SLOW = 'slow';

    /**
     * Group key => [name suffix, evaluation interval].
     */
    private const GROUPS = [self::FAST => ['', '1m'], self::SLOW => ['-window', '5m']];

    public function __construct(
        private PromQlBuilder $promQl,
        private BurnRatePolicy $burnRates,
        private DeterministicYaml $yaml,
    ) {
    }

    #[Override]
    public function recordingRules(Selector $selector, array $objectives): string
    {
        return $this->document($objectives, function (BoundObjective $objective) use ($selector): array {
            $labels = $this->recordingLabels($objective, $selector->application);
            $record = $this->record(...);
            $fast = [];
            foreach (self::FAST_RATE_WINDOWS as $window) {
                $fast[] = $record($labels, 'slo:events:rate' . $window, $this->promQl->eventsRate($selector, $objective, $window));
                $fast[] = $record($labels, 'slo:error_ratio:rate' . $window, $this->promQl->errorRatio($selector, $objective, $window));
            }
            $fast[] = $record($labels, 'slo:objective:target', \sprintf('vector(%s)', PromQlRenderer::number($objective->objective->target->percent / 100)));

            $slow = [];
            foreach (self::SLOW_RATE_WINDOWS as $window) {
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

            $page = array_map(fn (BurnRateWindow $pair): string => $this->burning($objective, $pair, $selector->application), array_values(array_filter($windows, static fn (BurnRateWindow $pair): bool => $pair->severity === 'page')));
            if ($page !== []) {
                $rules[] = $this->alert('SloErrorBudgetBurn', implode(' or ', $page), $objective, $selector->application, 'page', 'burns its error budget fast');
            }
            foreach ($windows as $pair) {
                if ($pair->severity === 'ticket') {
                    // Fast-burn pages need at most 6h of history; the 3d pair waits for a fully covered window.
                    $expr = \sprintf('%s and on() (%s >= 1)', $this->burning($objective, $pair, $selector->application), $this->promQl->latest('slo:window:coverage_ratio', $objective, $selector->application));
                    $rules[] = $this->alert('SloErrorBudgetBurn', $expr, $objective, $selector->application, 'ticket', 'burns its error budget');
                }
            }
            if ($noEventsAlertHours !== null) {
                $rules[] = $this->alert('SloNoEvents', $this->promQl->noEvents($selector, $objective, $noEventsAlertHours), $objective, $selector->application, 'ticket', \sprintf('recorded no events in %dh', $noEventsAlertHours));
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
            foreach (self::GROUPS as $key => [$suffix, $interval]) {
                if (($byKey[$key] ?? []) !== []) {
                    $groups[] = ['name' => 'slo-' . $name . $suffix, 'interval' => $interval, 'rules' => $byKey[$key]];
                }
            }
        }

        return $this->yaml->dump(['groups' => $groups]);
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
        $labels = [
            'application' => $application,
            'slo' => $objective->definition->name()->value,
            'objective' => $objective->objective->kind->value,
            'window' => $objective->definition->window()->toString(),
        ];
        if ($objective->binding->strategy === Strategy::Dedicated) {
            $labels['revision'] = $objective->revision->value;
        }

        return $labels;
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
     * @param 'page'|'ticket' $severity
     *
     * @return RuleShape
     */
    private function alert(string $name, string $expr, BoundObjective $objective, string $application, string $severity, string $what): array
    {
        $slo = $objective->definition->name()->value;
        $kind = $objective->objective->kind->value;

        return [
            'alert' => $name,
            'expr' => $expr,
            'labels' => ['application' => $application, 'severity' => $severity, 'slo' => $slo, 'objective' => $kind],
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

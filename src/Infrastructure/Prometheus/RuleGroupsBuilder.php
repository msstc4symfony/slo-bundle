<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

use Closure;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRateWindow;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\RuleFileBuilderInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\Strategy;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Override;

/**
 * Recording rules and multi-window burn-rate alerts, one rule group per SLO (`slo-<name>`, evaluated every minute).
 *
 * @phpstan-type RuleShape array{record?: non-empty-string, alert?: non-empty-string, expr: string, labels: array<non-empty-string, string>, annotations?: array{summary: non-empty-string}}
 */
final readonly class RuleGroupsBuilder implements RuleFileBuilderInterface
{
    private const RATE_WINDOWS = ['5m', '30m', '1h', '6h', '3d'];

    private const INTERVAL = '1m';

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
            $labels = $this->recordingLabels($objective);
            $rules = [];
            foreach (self::RATE_WINDOWS as $window) {
                $rules[] = ['record' => 'slo:events:rate' . $window, 'expr' => $this->promQl->eventsRate($selector, $objective, $window), 'labels' => $labels];
                $rules[] = ['record' => 'slo:error_ratio:rate' . $window, 'expr' => $this->promQl->errorRatio($selector, $objective, $window), 'labels' => $labels];
            }
            $rules[] = ['record' => 'slo:sli:ratio', 'expr' => $this->promQl->sliRatio($selector, $objective), 'labels' => $labels];
            $rules[] = ['record' => 'slo:objective:target', 'expr' => \sprintf('vector(%s)', BurnRatePolicy::number($objective->objective->target->percent / 100)), 'labels' => $labels];
            $rules[] = ['record' => 'slo:error_budget:remaining', 'expr' => $this->promQl->budgetRemaining($selector, $objective), 'labels' => $labels];
            $rules[] = ['record' => 'slo:window:coverage_ratio', 'expr' => $this->promQl->coverageRatio($selector, $objective), 'labels' => $labels];

            return $rules;
        });
    }

    #[Override]
    public function alertRules(Selector $selector, array $objectives, ?int $noEventsAlertHours): string
    {
        return $this->document($objectives, function (BoundObjective $objective) use ($selector, $noEventsAlertHours): array {
            $target = $objective->objective->target;
            $windows = $this->burnRates->windows($objective->definition->window(), $target);
            $rules = [];

            $page = array_map(fn (BurnRateWindow $pair): string => $this->burning($objective, $pair), array_values(array_filter($windows, static fn (BurnRateWindow $pair): bool => $pair->severity === 'page')));
            if ($page !== []) {
                $rules[] = $this->alert('SloErrorBudgetBurn', implode(' or ', $page), $objective, 'page', 'burns its error budget fast');
            }
            foreach ($windows as $pair) {
                if ($pair->severity === 'ticket') {
                    // Fast-burn pages need at most 6h of history; the 3d pair waits for a fully covered window.
                    $expr = \sprintf('%s and on() (%s >= 1)', $this->burning($objective, $pair), $this->promQl->recorded('slo:window:coverage_ratio', $objective));
                    $rules[] = $this->alert('SloErrorBudgetBurn', $expr, $objective, 'ticket', 'burns its error budget');
                }
            }
            if ($noEventsAlertHours !== null) {
                $rules[] = $this->alert('SloNoEvents', $this->promQl->noEvents($selector, $objective, $noEventsAlertHours), $objective, 'ticket', \sprintf('recorded no events in %dh', $noEventsAlertHours));
            }

            return $rules;
        });
    }

    /**
     * @param list<BoundObjective> $objectives sorted by SLO name
     * @param Closure(BoundObjective): list<RuleShape> $rules
     */
    private function document(array $objectives, Closure $rules): string
    {
        $bySlo = [];
        foreach ($objectives as $objective) {
            $name = $objective->definition->name()->value;
            $bySlo[$name] = [...$bySlo[$name] ?? [], ...$rules($objective)];
        }

        $groups = [];
        foreach ($bySlo as $name => $groupRules) {
            if ($groupRules !== []) {
                $groups[] = ['name' => 'slo-' . $name, 'interval' => self::INTERVAL, 'rules' => $groupRules];
            }
        }

        return $this->yaml->dump(['groups' => $groups]);
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function recordingLabels(BoundObjective $objective): array
    {
        $labels = [
            'slo' => $objective->definition->name()->value,
            'objective' => $objective->objective->kind->value,
            'window' => $objective->definition->window()->toString(),
        ];
        if ($objective->binding->strategy === Strategy::Dedicated) {
            $labels['revision'] = $objective->revision->value;
        }

        return $labels;
    }

    private function burning(BoundObjective $objective, BurnRateWindow $pair): string
    {
        $threshold = BurnRatePolicy::number($pair->threshold($objective->objective->target));

        return \sprintf(
            '(%s > %s and %s > %s)',
            $this->promQl->recorded('slo:error_ratio:rate' . $pair->longWindow, $objective),
            $threshold,
            $this->promQl->recorded('slo:error_ratio:rate' . $pair->shortWindow, $objective),
            $threshold,
        );
    }

    /**
     * @param non-empty-string $name
     * @param 'page'|'ticket' $severity
     *
     * @return RuleShape
     */
    private function alert(string $name, string $expr, BoundObjective $objective, string $severity, string $what): array
    {
        $slo = $objective->definition->name()->value;
        $kind = $objective->objective->kind->value;

        return [
            'alert' => $name,
            'expr' => $expr,
            'labels' => ['severity' => $severity, 'slo' => $slo, 'objective' => $kind],
            'annotations' => ['summary' => \sprintf(
                'SLO %s %s %s (target %s%%, window %s)',
                $slo,
                $kind,
                $what,
                BurnRatePolicy::number($objective->objective->target->percent),
                $objective->definition->window()->toString(),
            )],
        ];
    }
}

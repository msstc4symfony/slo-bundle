<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;

/**
 * One row per SLO objective over the recorded slo:* series: target, SLI, remaining budget, window state, the expected
 * end of warming up (dashboards are in UTC) and the burn rate.
 *
 * @internal
 */
final readonly class SloRows
{
    private const APPLICATION = '$application';

    private const WARMING_UP_BELOW = 0.999999;

    public function __construct(
        private PanelFactory $panels,
        private PromQlBuilder $promQl,
    ) {
    }

    /**
     * @param list<BoundObjective> $objectives
     *
     * @return list<Panel>
     */
    public function panels(array $objectives): array
    {
        $panels = [];
        foreach ($objectives as $bound) {
            array_push($panels, ...$this->row($bound));
        }

        return $panels;
    }

    /**
     * @return list<Panel>
     */
    private function row(BoundObjective $bound): array
    {
        $target = round($bound->objective->target->percent / 100, 10);

        return [
            $this->panels->row(\sprintf('%s / %s', $bound->definition->name()->value, $bound->objective->kind->value)),
            $this->panels->stat('Target', new Query($this->promQl->recorded('slo:objective:target', $bound, self::APPLICATION)), 'percentunit', width: 3),
            $this->panels->stat('SLI', new Query($this->promQl->latest('slo:sli:ratio', $bound, self::APPLICATION)), 'percentunit', [
                ['color' => 'red', 'value' => null],
                ['color' => 'green', 'value' => $target],
            ], width: 3),
            $this->panels->gauge('Error budget remaining', new Query($this->promQl->latest('slo:error_budget:remaining', $bound, self::APPLICATION)), 'percentunit', 0.0, 1.0, [
                ['color' => 'red', 'value' => null],
                ['color' => 'orange', 'value' => 0.1],
                ['color' => 'green', 'value' => 0.5],
            ], width: 4),
            $this->panels->stat('Window state', new Query($this->promQl->latest('slo:window:coverage_ratio', $bound, self::APPLICATION)), 'percentunit', [], [
                ['options' => ['from' => 0, 'result' => ['color' => 'orange', 'index' => 0, 'text' => 'warming up'], 'to' => self::WARMING_UP_BELOW], 'type' => 'range'],
                ['options' => ['from' => 1, 'result' => ['color' => 'green', 'index' => 1, 'text' => 'full window'], 'to' => 1000], 'type' => 'range'],
            ], width: 3),
            $this->panels->stat('Warming up until', new Query($this->warmingUpUntil($bound)), 'dateTimeAsIso', width: 4),
            $this->panels->timeseries('Burn rate', [
                new Query($this->burnRate($bound, '1h'), '1h'),
                new Query($this->burnRate($bound, '6h'), '6h'),
            ], 'none', width: 7, height: 6),
        ];
    }

    /**
     * Epoch milliseconds when the current revision will have covered the whole window; no series (NoData) without
     * coverage data or once the window is full.
     *
     * @return non-empty-string
     */
    private function warmingUpUntil(BoundObjective $bound): string
    {
        return \sprintf(
            '(time() + (1 - (%s < %s)) * %d) * 1000',
            $this->promQl->latest('slo:window:coverage_ratio', $bound, self::APPLICATION),
            self::WARMING_UP_BELOW,
            $bound->definition->window()->seconds(),
        );
    }

    /**
     * @param non-empty-string $window
     *
     * @return non-empty-string
     */
    private function burnRate(BoundObjective $bound, string $window): string
    {
        return \sprintf('%s / (1 - %s)', $this->promQl->recorded('slo:error_ratio:rate' . $window, $bound, self::APPLICATION), $this->promQl->recorded('slo:objective:target', $bound, self::APPLICATION));
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\SloMetric;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Override;

/**
 * @internal
 */
final readonly class ScheduledTasks implements DashboardInterface
{
    public function __construct(
        private PanelFactory $panels,
        private SloRows $sloRows,
    ) {
    }

    #[Override]
    public function title(): string
    {
        return 'Scheduled Tasks';
    }

    #[Override]
    public function slug(): string
    {
        return 'scheduled-tasks';
    }

    #[Override]
    public function panels(DashboardContext $context): array
    {
        $objectives = array_values(array_filter(
            $context->objectives,
            static fn (BoundObjective $bound): bool => $bound->definition->pointType() === PointType::ScheduledTask,
        ));
        if ($objectives === []) {
            return [$this->panels->markdown('Scheduled tasks', 'No scheduled task has an SLO.', height: 4)];
        }

        return [
            $this->panels->timeseries('Runs started by task', [new Query(\sprintf('sum by (task) (%s)', BaseSeries::rate(SloMetric::SCHEDULED_TASK_STARTED->value)), '{{task}}')], 'ops'),
            $this->panels->timeseries('Runs finished by task and outcome', [new Query(\sprintf('sum by (task, outcome) (%s)', BaseSeries::rate(SloMetric::SCHEDULED_TASK_FINISHED->value)), '{{task}} {{outcome}}')], 'ops'),
            $this->panels->timeseries('p95 run duration by task', [new Query(BaseSeries::quantile(0.95, SloMetric::SCHEDULED_TASK_DURATION->value, 'le, task'), '{{task}}')], 's'),
            $this->panels->stat('Runs counted unfinished over the time range', new Query($this->unfinishedRuns(), '{{slo}}', instant: true), 'none', [
                ['color' => 'green', 'value' => null],
                ['color' => 'red', 'value' => 1.0],
            ], width: 12, height: 8),
            ...$this->sloRows->panels($objectives),
        ];
    }

    /**
     * The step record the SLO rules count, once per SLO: whole runs, unlike started minus finished counters, which are
     * fractional over a range and multiplied when replicas export one shared storage.
     *
     * @return non-empty-string
     */
    private function unfinishedRuns(): string
    {
        return \sprintf('sum by (slo) (sum_over_time(%s{application="$application",objective=""}[$__range]))', PromQlBuilder::UNFINISHED_RUNS_RECORD);
    }
}

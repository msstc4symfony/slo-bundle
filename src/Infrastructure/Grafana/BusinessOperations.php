<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Override;

final readonly class BusinessOperations implements DashboardInterface
{
    private const HISTOGRAM = 'logic_action_duration_histogram_seconds';

    public function __construct(
        private PanelFactory $panels,
        private SloRows $sloRows,
    ) {
    }

    #[Override]
    public function title(): string
    {
        return 'Business Operations';
    }

    #[Override]
    public function slug(): string
    {
        return 'business-operations';
    }

    #[Override]
    public function panels(DashboardContext $context): array
    {
        $panels = [];
        if ($context->logicHistogram) {
            $panels[] = $this->panels->timeseries('Actions by outcome', [new Query(\sprintf('sum by (action, outcome) (%s)', BaseSeries::rate(self::HISTOGRAM . '_count')), '{{action}} {{outcome}}')], 'ops');
            $panels[] = $this->panels->timeseries('p95 action duration', [new Query(BaseSeries::quantile(0.95, self::HISTOGRAM, 'le, action'), '{{action}}')], 's');
        }
        array_push($panels, ...$this->sloRows->panels(array_values(array_filter(
            $context->objectives,
            static fn (BoundObjective $bound): bool => \in_array($bound->definition->pointType(), [PointType::LogicAction, PointType::Operation], true),
        ))));

        return $panels !== [] ? $panels : [$this->panels->markdown('Business operations', 'No logic action histogram is defined and no logic action or operation has an SLO.', height: 4)];
    }
}

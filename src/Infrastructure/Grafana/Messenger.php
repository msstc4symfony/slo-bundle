<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Override;

final readonly class Messenger implements DashboardInterface
{
    public function __construct(
        private PanelFactory $panels,
        private SloRows $sloRows,
    ) {
    }

    #[Override]
    public function title(): string
    {
        return 'Messenger';
    }

    #[Override]
    public function slug(): string
    {
        return 'messenger';
    }

    #[Override]
    public function panels(DashboardContext $context): array
    {
        return [
            $this->panels->timeseries('Handled by message', [new Query(\sprintf('sum by (message) (%s)', BaseSeries::rate('messenger_message_handled')), '{{message}}')], 'ops'),
            $this->panels->timeseries('Handled by outcome', [new Query(\sprintf('sum by (status) (%s)', BaseSeries::rate('messenger_message_handled')), '{{status}}')], 'ops'),
            $this->panels->timeseries('Sent by transport', [new Query(\sprintf('sum by (transport) (%s)', BaseSeries::rate('messenger_message_sent')), '{{transport}}')], 'ops'),
            $this->panels->timeseries('p95 handling duration by message', [new Query(BaseSeries::quantile(0.95, 'messenger_message_handling_duration_histogram_seconds', 'le, message'), '{{message}}')], 's'),
            $this->panels->timeseries('Queue depth', [new Query(\sprintf('sum by (transport) (%s)', BaseSeries::of('messenger_queue_depth')), '{{transport}}')], 'none'),
            ...$this->sloRows->panels(array_values(array_filter(
                $context->objectives,
                static fn (BoundObjective $bound): bool => $bound->definition->pointType() === PointType::MessengerMessage,
            ))),
        ];
    }
}

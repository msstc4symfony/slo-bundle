<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Override;

/**
 * @internal
 */
final readonly class HttpRoutes implements DashboardInterface
{
    public function __construct(
        private PanelFactory $panels,
        private SloRows $sloRows,
    ) {
    }

    #[Override]
    public function title(): string
    {
        return 'HTTP Routes';
    }

    #[Override]
    public function slug(): string
    {
        return 'http-routes';
    }

    #[Override]
    public function panels(DashboardContext $context): array
    {
        $responses = BaseSeries::rate('http_response');
        $errors = BaseSeries::rate('http_response', 'status=~"5.."');

        return [
            $this->panels->timeseries('Requests by route', [new Query(\sprintf('sum by (route) (%s)', BaseSeries::rate('http_request')), '{{route}}')], 'reqps'),
            $this->panels->timeseries('Server error ratio by route', [new Query(
                \sprintf('(sum by (route) (%s) or (sum by (route) (%s) * 0)) / (sum by (route) (%s) > 0)', $errors, $responses, $responses),
                '{{route}}',
            )], 'percentunit'),
            $this->panels->timeseries('p95 latency by route', [new Query(BaseSeries::quantile(0.95, 'request_duration_histogram_seconds', 'le, route'), '{{route}}')], 's'),
            $this->panels->timeseries('Responses by status', [new Query(\sprintf('sum by (status) (%s)', $responses), '{{status}}')], 'reqps'),
            ...$this->sloRows->panels(array_values(array_filter(
                $context->objectives,
                static fn (BoundObjective $bound): bool => $bound->definition->pointType() === PointType::HttpRoute,
            ))),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Override;

/**
 * @internal
 */
final readonly class ApplicationOverview implements DashboardInterface
{
    public function __construct(private PanelFactory $panels)
    {
    }

    #[Override]
    public function title(): string
    {
        return 'Application Overview';
    }

    #[Override]
    public function slug(): string
    {
        return 'application-overview';
    }

    #[Override]
    public function panels(DashboardContext $context): array
    {
        $requests = BaseSeries::rate('http_request');
        $responses = BaseSeries::rate('http_response');
        $errors = BaseSeries::rate('http_response', 'status=~"5.."');

        return [
            $this->panels->stat('Request rate', new Query(\sprintf('sum(%s)', $requests)), 'reqps'),
            $this->panels->stat('Server error ratio', new Query(\sprintf('(sum(%s) or vector(0)) / (sum(%s) > 0)', $errors, $responses)), 'percentunit', [
                ['color' => 'green', 'value' => null],
                ['color' => 'orange', 'value' => 0.01],
                ['color' => 'red', 'value' => 0.05],
            ]),
            $this->panels->stat('p95 latency', new Query(BaseSeries::quantile(0.95, 'request_duration_histogram_seconds')), 's'),
            $this->panels->stat('Worst SLI', new Query('min(slo:sli:ratio{application="$application",objective=~".+"})'), 'percentunit'),
            $this->panels->stat('Lowest error budget', new Query('min(slo:error_budget:remaining{application="$application",objective=~".+"})'), 'percentunit'),
            $this->panels->stat('Handled messages', new Query(\sprintf('sum(%s)', BaseSeries::rate('messenger_message_handled'))), 'ops'),
            $this->panels->timeseries('Responses by status', [new Query(\sprintf('sum by (status) (%s)', $responses), '{{status}}')], 'reqps'),
            $this->panels->timeseries('Latency quantiles', [
                new Query(BaseSeries::quantile(0.5, 'request_duration_histogram_seconds'), 'p50'),
                new Query(BaseSeries::quantile(0.95, 'request_duration_histogram_seconds'), 'p95'),
                new Query(BaseSeries::quantile(0.99, 'request_duration_histogram_seconds'), 'p99'),
            ], 's'),
            $this->panels->timeseries('Exceptions by class', [new Query(\sprintf('sum by (class) (%s)', BaseSeries::rate('exception')), '{{class}}')], 'ops'),
            $this->panels->timeseries('Messages by outcome', [new Query(\sprintf('sum by (status) (%s)', BaseSeries::rate('messenger_message_handled')), '{{status}}')], 'ops'),
        ];
    }
}

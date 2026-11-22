<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Bridge;

use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Override;

/**
 * Stands in for the logic-action histogram that metrics-bridge-logic appends to the metric enums.
 */
enum LogicHistogramMetric: string implements MetricDefinitionInterface
{
    case ACTION_DURATION = 'logic_action_duration_histogram_seconds';

    #[Override]
    public function getType(): MetricType
    {
        return MetricType::HISTOGRAM;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Logic action duration';
    }

    #[Override]
    public function getLabels(): array
    {
        return [new Label('action', LabelType::STRING, 'Action')];
    }

    #[Override]
    public function getBatches(): array
    {
        return [0.1, 0.25];
    }
}

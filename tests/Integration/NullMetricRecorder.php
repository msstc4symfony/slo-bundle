<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration;

use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Override;

final class NullMetricRecorder implements MetricRecorderInterface
{
    #[Override]
    public function increment(MetricDefinitionInterface $metric, array $labels = []): void
    {
    }

    #[Override]
    public function set(MetricDefinitionInterface $metric, float|int $value, array $labels = []): void
    {
    }

    #[Override]
    public function observe(MetricDefinitionInterface $metric, float $value, array $labels = []): void
    {
    }
}

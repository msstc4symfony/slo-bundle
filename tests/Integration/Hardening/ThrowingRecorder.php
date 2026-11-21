<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Hardening;

use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageException;
use Override;

/**
 * Stands for an unreachable metrics storage (Redis outage) as seen by the SLO writer.
 */
final class ThrowingRecorder implements MetricRecorderInterface
{
    /** @var non-negative-int */
    public int $attempts = 0;

    #[Override]
    public function increment(MetricDefinitionInterface $metric, array $labels = []): void
    {
        $this->attempts++;

        throw new MetricStorageException('Storage is unreachable.');
    }

    #[Override]
    public function set(MetricDefinitionInterface $metric, float|int $value, array $labels = []): void
    {
        $this->attempts++;

        throw new MetricStorageException('Storage is unreachable.');
    }

    #[Override]
    public function observe(MetricDefinitionInterface $metric, float $value, array $labels = []): void
    {
        $this->attempts++;

        throw new MetricStorageException('Storage is unreachable.');
    }
}

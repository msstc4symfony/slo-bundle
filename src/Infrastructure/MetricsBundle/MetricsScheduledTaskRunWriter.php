<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunWriterInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;

/**
 * @internal
 */
final readonly class MetricsScheduledTaskRunWriter implements ScheduledTaskRunWriterInterface
{
    public function __construct(private MetricRecorderInterface $recorder)
    {
    }

    #[Override]
    public function started(PointId $task): void
    {
        $this->recorder->increment(SloMetric::SCHEDULED_TASK_STARTED, [$task->id]);
    }

    #[Override]
    public function finished(PointId $task, ScheduledTaskRunOutcome $outcome, float $durationSeconds): void
    {
        $this->recorder->increment(SloMetric::SCHEDULED_TASK_FINISHED, [$task->id, $outcome->value]);
        $this->recorder->observe(SloMetric::SCHEDULED_TASK_DURATION, $durationSeconds, [$task->id, $outcome->value]);
    }
}

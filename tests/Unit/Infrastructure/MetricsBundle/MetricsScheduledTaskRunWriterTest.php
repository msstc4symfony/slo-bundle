<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsScheduledTaskRunWriter;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\SloMetric;
use PHPUnit\Framework\TestCase;

final class MetricsScheduledTaskRunWriterTest extends TestCase
{
    public function testStartedIncrementsTheStartCounterWithThePointIdAsTask(): void
    {
        $recorder = new RecordingMetricRecorder();

        (new MetricsScheduledTaskRunWriter($recorder))->started(PointId::of(PointType::ScheduledTask, 'app.cleanup::__invoke'));

        self::assertSame([['increment', SloMetric::SCHEDULED_TASK_STARTED, null, ['app.cleanup::__invoke']]], $recorder->calls);
    }

    public function testFinishedIncrementsTheOutcomeCounterAndObservesTheDuration(): void
    {
        $recorder = new RecordingMetricRecorder();

        (new MetricsScheduledTaskRunWriter($recorder))->finished(PointId::of(PointType::ScheduledTask, 'app.cleanup::__invoke'), ScheduledTaskRunOutcome::Retried, 2.5);

        self::assertSame([
            ['increment', SloMetric::SCHEDULED_TASK_FINISHED, null, ['app.cleanup::__invoke', 'retried']],
            ['observe', SloMetric::SCHEDULED_TASK_DURATION, 2.5, ['app.cleanup::__invoke', 'retried']],
        ], $recorder->calls);
    }
}

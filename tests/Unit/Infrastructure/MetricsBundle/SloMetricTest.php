<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsEventWriter;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\SloMetric;
use PHPUnit\Framework\TestCase;

final class SloMetricTest extends TestCase
{
    public function testDefinition(): void
    {
        self::assertSame('slo_events_total', SloMetric::EVENTS->value);
        self::assertSame(MetricType::COUNTER, SloMetric::EVENTS->getType());
        self::assertSame('SLO events by result', SloMetric::EVENTS->getDescription());
        self::assertSame([], SloMetric::EVENTS->getBatches());

        $labels = SloMetric::EVENTS->getLabels();
        self::assertSame(['slo', 'objective', 'revision', 'result'], array_map(static fn (Label $l): string => $l->name, $labels));
        self::assertSame([LabelType::STRING, LabelType::ENUM, LabelType::STRING, LabelType::ENUM], array_map(static fn (Label $l): LabelType => $l->type, $labels));
        self::assertSame(['availability', 'latency', 'combined'], $labels[1]->enums);
        self::assertSame(['good', 'bad'], $labels[3]->enums);
    }

    public function testScheduledTaskRunCounters(): void
    {
        self::assertSame('slo_scheduled_task_started_total', SloMetric::SCHEDULED_TASK_STARTED->value);
        self::assertSame(MetricType::COUNTER, SloMetric::SCHEDULED_TASK_STARTED->getType());
        self::assertSame([], SloMetric::SCHEDULED_TASK_STARTED->getBatches());
        self::assertSame([['task', LabelType::STRING, []]], $this->labelsOf(SloMetric::SCHEDULED_TASK_STARTED));

        self::assertSame('slo_scheduled_task_finished_total', SloMetric::SCHEDULED_TASK_FINISHED->value);
        self::assertSame(MetricType::COUNTER, SloMetric::SCHEDULED_TASK_FINISHED->getType());
        self::assertSame([], SloMetric::SCHEDULED_TASK_FINISHED->getBatches());
        self::assertSame(
            [['task', LabelType::STRING, []], ['outcome', LabelType::ENUM, ['success', 'error', 'expected_failure', 'retried']]],
            $this->labelsOf(SloMetric::SCHEDULED_TASK_FINISHED),
        );
    }

    public function testScheduledTaskDurationHistogram(): void
    {
        $buckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 180.0, 300.0, 600.0, 1800.0, 3600.0];

        self::assertSame('slo_scheduled_task_duration_seconds', SloMetric::SCHEDULED_TASK_DURATION->value);
        self::assertSame(MetricType::HISTOGRAM, SloMetric::SCHEDULED_TASK_DURATION->getType());
        self::assertSame($buckets, SloMetric::SCHEDULED_TASK_DURATION->getBatches());
        self::assertSame($buckets, SloMetric::DURATION_BUCKETS);
        self::assertSame(
            [['task', LabelType::STRING, []], ['outcome', LabelType::ENUM, ['success', 'error', 'expected_failure', 'retried']]],
            $this->labelsOf(SloMetric::SCHEDULED_TASK_DURATION),
        );
        self::assertNotSame('', SloMetric::SCHEDULED_TASK_DURATION->getDescription());
        self::assertSame(['success', 'error', 'expected_failure', 'retried'], array_map(static fn (ScheduledTaskRunOutcome $o): string => $o->value, ScheduledTaskRunOutcome::cases()));
    }

    public function testWriterIncrementsOnceWithLabelsInOrder(): void
    {
        $recorder = $this->createMock(MetricRecorderInterface::class);
        $recorder->expects(self::once())->method('increment')->with(SloMetric::EVENTS, ['payment.create', 'latency', 'abcdef01', 'bad']);

        (new MetricsEventWriter($recorder))->write(SloName::fromString('payment.create'), ObjectiveKind::Latency, Revision::fromHex('abcdef01'), EventResult::Bad);
    }

    /**
     * @return list<array{string, LabelType, list<string>|list<int>|list<float>}>
     */
    private function labelsOf(SloMetric $metric): array
    {
        return array_map(static fn (Label $l): array => [$l->name, $l->type, $l->enums], $metric->getLabels());
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Override;

/**
 * @internal
 */
enum SloMetric: string implements MetricDefinitionInterface
{
    case EVENTS = 'slo_events_total';
    case SCHEDULED_TASK_STARTED = 'slo_scheduled_task_started_total';
    case SCHEDULED_TASK_FINISHED = 'slo_scheduled_task_finished_total';
    case SCHEDULED_TASK_DURATION = 'slo_scheduled_task_duration_seconds';

    /**
     * The metrics-bundle Messenger buckets plus 300, 600, 1800 and 3600 s: tasks run longer than messages.
     */
    public const DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 180.0, 300.0, 600.0, 1800.0, 3600.0];

    #[Override]
    public function getType(): MetricType
    {
        return $this === self::SCHEDULED_TASK_DURATION ? MetricType::HISTOGRAM : MetricType::COUNTER;
    }

    #[Override]
    public function getDescription(): string
    {
        return match ($this) {
            self::EVENTS => 'SLO events by result',
            self::SCHEDULED_TASK_STARTED => 'Scheduled task runs started by a worker',
            self::SCHEDULED_TASK_FINISHED => 'Scheduled task runs finished by outcome',
            self::SCHEDULED_TASK_DURATION => 'Scheduled task run duration in seconds by outcome',
        };
    }

    #[Override]
    public function getLabels(): array
    {
        $task = new Label('task', LabelType::STRING, 'Scheduled task point id');

        return match ($this) {
            self::EVENTS => [
                new Label('slo', LabelType::STRING, 'SLO name'),
                new Label('objective', LabelType::ENUM, 'Objective kind', array_map(static fn (ObjectiveKind $kind): string => $kind->value, ObjectiveKind::cases())),
                new Label('revision', LabelType::STRING, 'Classification revision, 8 hex digits'),
                new Label('result', LabelType::ENUM, 'Event result', array_map(static fn (EventResult $result): string => $result->value, EventResult::cases())),
            ],
            self::SCHEDULED_TASK_STARTED => [$task],
            self::SCHEDULED_TASK_FINISHED, self::SCHEDULED_TASK_DURATION => [
                $task,
                new Label('outcome', LabelType::ENUM, 'Run outcome', array_map(static fn (ScheduledTaskRunOutcome $outcome): string => $outcome->value, ScheduledTaskRunOutcome::cases())),
            ],
        };
    }

    #[Override]
    public function getBatches(): array
    {
        return $this === self::SCHEDULED_TASK_DURATION ? self::DURATION_BUCKETS : [];
    }
}

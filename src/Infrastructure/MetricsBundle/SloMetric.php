<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Override;

enum SloMetric: string implements MetricDefinitionInterface
{
    case EVENTS = 'slo_events_total';

    #[Override]
    public function getType(): MetricType
    {
        return MetricType::COUNTER;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'SLO events by result';
    }

    #[Override]
    public function getLabels(): array
    {
        return [
            new Label('slo', LabelType::STRING, 'SLO name'),
            new Label('objective', LabelType::ENUM, 'Objective kind', array_map(static fn (ObjectiveKind $kind): string => $kind->value, ObjectiveKind::cases())),
            new Label('revision', LabelType::STRING, 'Classification revision, 8 hex digits'),
            new Label('result', LabelType::ENUM, 'Event result', array_map(static fn (EventResult $result): string => $result->value, EventResult::cases())),
        ];
    }

    #[Override]
    public function getBatches(): array
    {
        return [];
    }
}

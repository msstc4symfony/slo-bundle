<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
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

    public function testWriterIncrementsOnceWithLabelsInOrder(): void
    {
        $recorder = $this->createMock(MetricRecorderInterface::class);
        $recorder->expects(self::once())->method('increment')->with(SloMetric::EVENTS, ['payment.create', 'latency', 'abcdef01', 'bad']);

        (new MetricsEventWriter($recorder))->write(SloName::fromString('payment.create'), ObjectiveKind::Latency, Revision::fromHex('abcdef01'), EventResult::Bad);
    }
}

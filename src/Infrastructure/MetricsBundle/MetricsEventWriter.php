<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;

/**
 * @internal
 */
final readonly class MetricsEventWriter implements EventWriterInterface
{
    public function __construct(private MetricRecorderInterface $recorder)
    {
    }

    #[Override]
    public function write(SloName $slo, ObjectiveKind $kind, Revision $revision, EventResult $result): void
    {
        $this->recorder->increment(SloMetric::EVENTS, [$slo->value, $kind->value, $revision->value, $result->value]);
    }
}

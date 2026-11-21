<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Binding;

use LogicException;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Override;

/**
 * Answers the facts a strategy decision needs and fails on every SeriesBinding build.
 */
final readonly class DecisionOnlySeriesCatalog implements SeriesCatalogInterface
{
    private MetricsBundleSeriesCatalog $inner;

    public function __construct()
    {
        $this->inner = new MetricsBundleSeriesCatalog();
    }

    #[Override]
    public function histogramFor(PointType $type): ?string
    {
        return $this->inner->histogramFor($type);
    }

    #[Override]
    public function bucketFor(array $buckets, LatencyThreshold $threshold): ?string
    {
        return $this->inner->bucketFor($buckets, $threshold);
    }

    #[Override]
    public function messageLabel(string $messageClass): string
    {
        return $this->inner->messageLabel($messageClass);
    }

    #[Override]
    public function httpAvailability(array $routes, StatusSet $bad, StatusSet $ineligible): SeriesBinding
    {
        throw new LogicException('series built');
    }

    #[Override]
    public function httpLatency(array $routes, string $bucket): SeriesBinding
    {
        throw new LogicException('series built');
    }

    #[Override]
    public function messengerAvailability(array $messageClasses, array $failureTransports): SeriesBinding
    {
        throw new LogicException('series built');
    }

    #[Override]
    public function messengerLatency(array $messageClasses, array $failureTransports, string $bucket): SeriesBinding
    {
        throw new LogicException('series built');
    }

    #[Override]
    public function logicAvailability(array $actionLabels): SeriesBinding
    {
        throw new LogicException('series built');
    }

    #[Override]
    public function logicLatency(array $actionLabels, string $bucket): SeriesBinding
    {
        throw new LogicException('series built');
    }
}

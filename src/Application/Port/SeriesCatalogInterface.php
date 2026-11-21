<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;

/**
 * The series metrics-bundle already exports (Strategy A): metric and label names and how an objective reads them.
 * Whether an objective can use them at all is decided by MetricBindingResolver.
 *
 * @internal
 */
interface SeriesCatalogInterface
{
    /**
     * Duration histogram of the point type, null when metrics-bundle measures none.
     *
     * @return non-empty-string|null
     */
    public function histogramFor(PointType $type): ?string;

    /**
     * The bucket bound of the histogram that equals the threshold, as the generated queries address it.
     *
     * @param list<float> $buckets
     *
     * @return non-empty-string|null
     */
    public function bucketFor(array $buckets, LatencyThreshold $threshold): ?string;

    /**
     * The value metrics-bundle writes into the `message` label for a message class.
     *
     * @param non-empty-string $messageClass
     *
     * @return non-empty-string
     */
    public function messageLabel(string $messageClass): string;

    /**
     * @param non-empty-list<non-empty-string> $routes sorted route names
     */
    public function httpAvailability(array $routes, StatusSet $bad, StatusSet $ineligible): SeriesBinding;

    /**
     * Every response of the routes is eligible: the histogram has no status label.
     *
     * @param non-empty-list<non-empty-string> $routes sorted route names
     * @param non-empty-string $bucket
     */
    public function httpLatency(array $routes, string $bucket): SeriesBinding;

    /**
     * @param non-empty-list<non-empty-string> $messageClasses
     * @param list<non-empty-string> $failureTransports
     */
    public function messengerAvailability(array $messageClasses, array $failureTransports): SeriesBinding;

    /**
     * @param non-empty-list<non-empty-string> $messageClasses
     * @param list<non-empty-string> $failureTransports
     * @param non-empty-string $bucket
     */
    public function messengerLatency(array $messageClasses, array $failureTransports, string $bucket): SeriesBinding;

    /**
     * Expected failures count as good on these series.
     *
     * @param non-empty-list<non-empty-string> $actionLabels
     */
    public function logicAvailability(array $actionLabels): SeriesBinding;

    /**
     * @param non-empty-list<non-empty-string> $actionLabels
     * @param non-empty-string $bucket
     */
    public function logicLatency(array $actionLabels, bool $includeErrors, string $bucket): SeriesBinding;
}

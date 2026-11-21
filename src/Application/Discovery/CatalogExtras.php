<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

/**
 * Compile-time facts besides the SLO definitions, consumed by validation, coverage and strategy resolution.
 */
final readonly class CatalogExtras
{
    /**
     * @param array<class-string, list<non-empty-string>> $controllers every discovered public action method
     * @param list<HandlerInfo> $handlers
     * @param list<TaggedPoint> $taggedPoints
     * @param list<non-empty-string> $operations
     * @param list<non-empty-string> $failureTransports
     * @param array<non-empty-string, list<float>> $metricBuckets histogram name => buckets, only for declared histograms
     * @param list<string> $httpIgnoredRoutes
     * @param list<non-empty-string> $warnings
     */
    public function __construct(
        public array $controllers = [],
        public array $handlers = [],
        public array $taggedPoints = [],
        public array $operations = [],
        public array $failureTransports = [],
        public array $metricBuckets = [],
        public array $httpIgnoredRoutes = [],
        public array $warnings = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

/**
 * @internal
 */
final readonly class PointCoverage
{
    /**
     * @param non-empty-string $point route name, handler "Class::method", logic action id or operation name
     * @param non-empty-string|null $reason set for every status except monitored
     * @param list<non-empty-string> $slos names of the SLOs covering the point, sorted
     * @param bool $measuredByUniversalMetric the point is already measured by a metrics-bundle series, with or without an SLO
     */
    public function __construct(
        public string $point,
        public CoverageKind $kind,
        public CoverageStatus $status,
        public ?string $reason,
        public array $slos,
        public bool $measuredByUniversalMetric,
    ) {
    }

    /**
     * Report order: by kind, then by point.
     */
    public static function compare(self $left, self $right): int
    {
        return [$left->kind->position(), $left->point] <=> [$right->kind->position(), $right->point];
    }

    /**
     * @param non-empty-string $point
     * @param list<non-empty-string> $slos
     */
    public static function judged(string $point, CoverageKind $kind, CoverageVerdict $verdict, array $slos, bool $measuredByUniversalMetric): self
    {
        return new self($point, $kind, $verdict->status, $verdict->reason, $slos, $measuredByUniversalMetric);
    }
}

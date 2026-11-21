<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

/**
 * @internal
 */
final readonly class Output
{
    /**
     * @param list<PointCoverage> $points sorted by kind, then point
     * @param array<PointCoverage::KIND_*, float> $sloPercent monitored / (total - ignored - unsupported), 100 when that is 0
     * @param array<PointCoverage::KIND_*, float> $measuredPercent points measured by a universal metric / total, 100 when total is 0
     * @param non-negative-int $invalid
     * @param array<PointCoverage::KIND_*, array{non-negative-int, non-negative-int}> $sloFraction monitored and eligible counts behind $sloPercent, which is rounded for display
     */
    public function __construct(
        public array $points,
        public array $sloPercent,
        public array $measuredPercent,
        public int $invalid,
        public array $sloFraction,
    ) {
    }

    /**
     * @param array<PointCoverage::KIND_*, float> $required
     *
     * @return list<PointCoverage::KIND_*>
     */
    public function belowThreshold(array $required): array
    {
        $below = [];
        foreach (PointCoverage::KINDS as $kind) {
            if (!isset($required[$kind], $this->sloFraction[$kind])) {
                continue;
            }
            [$monitored, $eligible] = $this->sloFraction[$kind];
            if ($eligible > 0 && $monitored * 100 < $required[$kind] * $eligible) {
                $below[] = $kind;
            }
        }

        return $below;
    }
}

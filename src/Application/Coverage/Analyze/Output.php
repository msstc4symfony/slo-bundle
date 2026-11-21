<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

final readonly class Output
{
    /**
     * @param list<PointCoverage> $points sorted by kind, then point
     * @param array<PointCoverage::KIND_*, float> $sloPercent monitored / (total - ignored - unsupported), 100 when that is 0
     * @param array<PointCoverage::KIND_*, float> $measuredPercent points measured by a universal metric / total, 100 when total is 0
     * @param non-negative-int $invalid
     */
    public function __construct(
        public array $points,
        public array $sloPercent,
        public array $measuredPercent,
        public int $invalid,
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
            if (isset($required[$kind], $this->sloPercent[$kind]) && $this->sloPercent[$kind] < $required[$kind]) {
                $below[] = $kind;
            }
        }

        return $below;
    }
}

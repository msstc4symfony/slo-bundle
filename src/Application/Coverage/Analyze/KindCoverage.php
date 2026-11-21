<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

use InvalidArgumentException;

/**
 * Coverage of one point kind.
 *
 * @internal
 */
final readonly class KindCoverage
{
    public float $sloPercent;

    /**
     * @param non-negative-int $monitored points with an SLO
     * @param non-negative-int $eligible points that should have one: total minus ignored and unsupported
     * @param float $measuredPercent points measured by a universal metric / total, 100 when total is 0
     */
    public function __construct(
        public CoverageKind $kind,
        public int $monitored,
        public int $eligible,
        public float $measuredPercent,
    ) {
        if ($monitored > $eligible) {
            throw new InvalidArgumentException(\sprintf('%d monitored %s points exceed the %d eligible ones.', $monitored, $kind->value, $eligible));
        }
        $this->sloPercent = $eligible === 0 ? 100.0 : round($monitored / $eligible * 100, 2);
    }

    /**
     * Compared on the exact fraction: $sloPercent is rounded for display.
     */
    public function isBelow(float $requiredPercent): bool
    {
        return $this->eligible > 0 && $this->monitored * 100 < $requiredPercent * $this->eligible;
    }
}

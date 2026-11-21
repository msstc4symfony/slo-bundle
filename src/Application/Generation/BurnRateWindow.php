<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;

/**
 * One multi-window burn-rate condition: both windows must burn faster than factor x the error budget.
 *
 * @internal
 */
final readonly class BurnRateWindow
{
    /**
     * @param positive-int $longWindowSeconds
     * @param positive-int $shortWindowSeconds
     */
    public function __construct(
        public BurnRateSeverity $severity,
        public int $longWindowSeconds,
        public int $shortWindowSeconds,
        public float $factor,
    ) {
    }

    /**
     * Error ratio above which both windows must stay for the alert to fire.
     */
    public function threshold(Target $target): float
    {
        return round($this->factor * $target->errorBudgetRatio(), 10);
    }
}

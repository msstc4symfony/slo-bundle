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
     * @param 'page'|'ticket' $severity
     * @param non-empty-string $longWindow PromQL duration
     * @param non-empty-string $shortWindow PromQL duration
     */
    public function __construct(
        public string $severity,
        public string $longWindow,
        public string $shortWindow,
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

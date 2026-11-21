<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Target
{
    private function __construct(public float $percent)
    {
    }

    public static function fromPercent(float $percent): self
    {
        if (!is_finite($percent) || $percent <= 0.0 || $percent >= 100.0) {
            throw new InvalidArgumentException(\sprintf('Invalid SLO target "%s": expected 0 < target < 100.', var_export($percent, true)));
        }

        $scaled = $percent * 10000;
        if (abs($scaled - round($scaled)) > 1e-6) {
            throw new InvalidArgumentException(\sprintf('Invalid SLO target "%s": at most 4 decimals are allowed.', var_export($percent, true)));
        }

        return new self($percent);
    }

    public function errorBudgetRatio(): float
    {
        return round(1 - $this->percent / 100, 6);
    }
}

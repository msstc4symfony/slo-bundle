<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;

/**
 * Google SRE workbook window pairs and budget shares, scaled to the SLO window (ruling 9).
 */
final readonly class BurnRatePolicy
{
    /**
     * severity, long window, its seconds, short window, share of the error budget burnt over the long window.
     */
    private const PAIRS = [
        ['page', '1h', 3600, '5m', 0.02],
        ['page', '6h', 21600, '30m', 0.05],
        ['ticket', '3d', 259200, '6h', 0.1],
    ];

    /**
     * @return list<BurnRateWindow> the pairs that fit into the window and stay below the whole error budget
     */
    public function windows(Window $window, Target $target): array
    {
        return array_values(array_filter(
            $this->fitting($window),
            static fn (BurnRateWindow $pair): bool => !self::exceeds($pair, $target),
        ));
    }

    /**
     * @return list<BurnRateWindow> pairs omitted because factor x error budget >= 1 (they could never fire)
     */
    public function exceedingBudget(Window $window, Target $target): array
    {
        return array_values(array_filter(
            $this->fitting($window),
            static fn (BurnRateWindow $pair): bool => self::exceeds($pair, $target),
        ));
    }

    /**
     * @return list<non-empty-string>
     */
    public function warnings(string $slo, string $objective, Window $window, Target $target): array
    {
        return array_map(
            static fn (BurnRateWindow $pair): string => \sprintf(
                'SLO %s %s: %s burn-rate alert %s/%s omitted, factor %s x error budget %s >= 1',
                $slo,
                $objective,
                $pair->severity,
                $pair->longWindow,
                $pair->shortWindow,
                self::number($pair->factor),
                self::number($target->errorBudgetRatio()),
            ),
            $this->exceedingBudget($window, $target),
        );
    }

    /**
     * Shortest decimal rendering without exponent or trailing zeros, as used in PromQL and messages.
     *
     * @return non-empty-string
     */
    public static function number(float $value): string
    {
        $formatted = rtrim(rtrim(\sprintf('%.10F', round($value, 10)), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @return list<BurnRateWindow>
     */
    private function fitting(Window $window): array
    {
        $pairs = [];
        foreach (self::PAIRS as [$severity, $long, $longSeconds, $short, $share]) {
            if ($longSeconds <= $window->seconds()) {
                $pairs[] = new BurnRateWindow($severity, $long, $short, round($share * $window->seconds() / $longSeconds, 6));
            }
        }

        return $pairs;
    }

    private static function exceeds(BurnRateWindow $pair, Target $target): bool
    {
        return round($pair->factor * $target->errorBudgetRatio(), 9) >= 1.0;
    }
}

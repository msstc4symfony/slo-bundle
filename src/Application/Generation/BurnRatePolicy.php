<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;

/**
 * Google SRE workbook window pairs and budget shares, scaled to the SLO window (ruling 9).
 *
 * @internal
 */
final readonly class BurnRatePolicy
{
    /**
     * severity, long window seconds, short window seconds, share of the error budget burnt over the long window.
     */
    private const PAIRS = [
        ['page', 3600, 300, 0.02],
        ['page', 21600, 1800, 0.05],
        ['ticket', 259200, 21600, 0.1],
    ];

    private const MESSAGE_UNITS = ['d' => 86400, 'h' => 3600, 'm' => 60];

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
                self::duration($pair->longWindowSeconds),
                self::duration($pair->shortWindowSeconds),
                self::decimal($pair->factor),
                self::decimal($target->errorBudgetRatio()),
            ),
            $this->exceedingBudget($window, $target),
        );
    }

    /**
     * @return non-empty-string
     */
    private static function decimal(float $value): string
    {
        $formatted = rtrim(rtrim(\sprintf('%.10F', round($value, 10)), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @param positive-int $seconds
     *
     * @return non-empty-string
     */
    private static function duration(int $seconds): string
    {
        foreach (self::MESSAGE_UNITS as $unit => $unitSeconds) {
            if ($seconds % $unitSeconds === 0) {
                return intdiv($seconds, $unitSeconds) . $unit;
            }
        }

        return $seconds . 's';
    }

    /**
     * @return list<BurnRateWindow>
     */
    private function fitting(Window $window): array
    {
        $pairs = [];
        foreach (self::PAIRS as [$severity, $longSeconds, $shortSeconds, $share]) {
            if ($longSeconds <= $window->seconds()) {
                $pairs[] = new BurnRateWindow($severity, $longSeconds, $shortSeconds, round($share * $window->seconds() / $longSeconds, 6));
            }
        }

        return $pairs;
    }

    private static function exceeds(BurnRateWindow $pair, Target $target): bool
    {
        return round($pair->factor * $target->errorBudgetRatio(), 9) >= 1.0;
    }
}

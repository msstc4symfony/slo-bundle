<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation;

use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRateWindow;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlRenderer;
use PHPUnit\Framework\TestCase;

final class BurnRatePolicyTest extends TestCase
{
    public function testThirtyDaysKeepsTheWorkbookFactors(): void
    {
        self::assertSame(
            ['page 1h/5m 14.4', 'page 6h/30m 6', 'ticket 3d/6h 1'],
            $this->describe((new BurnRatePolicy())->windows(Window::fromString('30d'), Target::fromPercent(99.9))),
        );
    }

    public function testFactorsScaleWithTheWindow(): void
    {
        self::assertSame(
            ['page 1h/5m 3.36', 'page 6h/30m 1.4', 'ticket 3d/6h 0.233333'],
            $this->describe((new BurnRatePolicy())->windows(Window::fromString('7d'), Target::fromPercent(99))),
        );
        self::assertSame(
            ['page 1h/5m 14.4', 'page 6h/30m 6', 'ticket 3d/6h 1'],
            $this->describe((new BurnRatePolicy())->windows(Window::fromString('1mo'), Target::fromPercent(99))),
        );
    }

    public function testPairsWithALongWindowAboveTheSloWindowAreOmitted(): void
    {
        $policy = new BurnRatePolicy();

        self::assertSame(['page 1h/5m 0.48', 'page 6h/30m 0.2'], $this->describe($policy->windows(Window::fromString('1d'), Target::fromPercent(99))));
        self::assertSame(['page 1h/5m 1.44', 'page 6h/30m 0.6', 'ticket 3d/6h 0.1'], $this->describe($policy->windows(Window::fromString('3d'), Target::fromPercent(99))));
        self::assertSame(['page 1h/5m 0.02'], $this->describe($policy->windows(Window::fromString('1h'), Target::fromPercent(99))));
        self::assertSame([], $policy->exceedingBudget(Window::fromString('1d'), Target::fromPercent(99)));
    }

    public function testPairsWhoseThresholdReachesTheWholeBudgetAreOmittedAndReported(): void
    {
        $policy = new BurnRatePolicy();
        $window = Window::fromString('30d');

        self::assertSame(['page 6h/30m 6', 'ticket 3d/6h 1'], $this->describe($policy->windows($window, Target::fromPercent(90))));
        self::assertSame(['page 1h/5m 14.4'], $this->describe($policy->exceedingBudget($window, Target::fromPercent(90))));
        self::assertSame(['page 1h/5m 14.4'], $this->describe($policy->exceedingBudget($window, Target::fromPercent(93.0555))));
        self::assertSame([], $policy->exceedingBudget($window, Target::fromPercent(93.0556)));
        self::assertSame(['page 1h/5m 14.4', 'page 6h/30m 6'], $this->describe($policy->exceedingBudget($window, Target::fromPercent(0.0001))));
    }

    public function testThresholdIsFactorTimesTheErrorBudget(): void
    {
        $windows = (new BurnRatePolicy())->windows(Window::fromString('30d'), Target::fromPercent(99.9));

        self::assertSame([0.0144, 0.006, 0.001], array_map(static fn (BurnRateWindow $w): float => $w->threshold(Target::fromPercent(99.9)), $windows));
    }

    public function testWarningsNameTheSloObjectiveAndPair(): void
    {
        self::assertSame(
            ['SLO checkout.pay availability: page burn-rate alert 1h/5m omitted, factor 14.4 x error budget 0.1 >= 1'],
            (new BurnRatePolicy())->warnings('checkout.pay', 'availability', Window::fromString('30d'), Target::fromPercent(90)),
        );
    }

    /**
     * @param list<BurnRateWindow> $windows
     *
     * @return list<string>
     */
    private function describe(array $windows): array
    {
        return array_map(static fn (BurnRateWindow $w): string => \sprintf('%s %s/%s %s', $w->severity, PromQlRenderer::duration($w->longWindowSeconds), PromQlRenderer::duration($w->shortWindowSeconds), PromQlRenderer::number($w->factor)), $windows);
    }
}

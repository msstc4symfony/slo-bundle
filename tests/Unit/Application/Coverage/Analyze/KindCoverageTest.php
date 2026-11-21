<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Coverage\Analyze;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\KindCoverage;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class KindCoverageTest extends TestCase
{
    /**
     * @param non-negative-int $monitored
     * @param non-negative-int $eligible
     */
    #[TestWith([7, 10000, 0.07, false], 'exactly 0.07 %, which a float product puts below')]
    #[TestWith([6, 10000, 0.07, true])]
    #[TestWith([5799, 10000, 57.99, false])]
    #[TestWith([1, 3, 33.33, false])]
    #[TestWith([1, 3, 33.34, true])]
    #[TestWith([2, 3, 66.67, true])]
    #[TestWith([0, 0, 100.0, false], 'nothing eligible')]
    #[TestWith([3, 4, 75.0, false])]
    #[TestWith([3, 4, 75.01, true])]
    public function testIsBelowComparesInBasisPoints(int $monitored, int $eligible, float $required, bool $below): void
    {
        self::assertSame($below, (new KindCoverage(CoverageKind::HttpRoute, $monitored, $eligible, 100.0))->isBelow($required));
    }
}

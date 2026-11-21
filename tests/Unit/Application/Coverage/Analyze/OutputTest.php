<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Coverage\Analyze;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\PointCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    /**
     * @return iterable<string, array{non-negative-int, non-negative-int, float, bool}>
     */
    public static function thresholdProvider(): iterable
    {
        yield 'two of three is below the rounded 66.67' => [2, 3, 66.67, true];
        yield 'two of three reaches 66.66' => [2, 3, 66.66, false];
        yield 'exactly the required share' => [1, 2, 50.0, false];
        yield 'just below 100' => [999, 1000, 100.0, true];
        yield 'rounds to 100 but is below' => [99_999, 100_000, 100.0, true];
        yield 'all covered' => [3, 3, 100.0, false];
        yield 'nothing to cover' => [0, 0, 100.0, false];
    }

    /**
     * @param non-negative-int $monitored
     * @param non-negative-int $eligible
     */
    #[DataProvider('thresholdProvider')]
    public function testThresholdIsComparedOnTheExactFraction(int $monitored, int $eligible, float $required, bool $below): void
    {
        $kind = PointCoverage::KIND_HTTP_ROUTE;
        $percent = $eligible === 0 ? 100.0 : round($monitored / $eligible * 100, 2);
        $output = new Output([], [$kind => $percent], [$kind => 100.0], 0, [$kind => [$monitored, $eligible]]);

        self::assertSame($below ? [$kind] : [], $output->belowThreshold([$kind => $required]));
    }
}

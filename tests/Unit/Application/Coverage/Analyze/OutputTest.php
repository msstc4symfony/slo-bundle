<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Coverage\Analyze;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\KindCoverage;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    /**
     * @return iterable<string, array{non-negative-int, non-negative-int, float, bool, float}>
     */
    public static function thresholdProvider(): iterable
    {
        yield 'two of three is below the rounded 66.67' => [2, 3, 66.67, true, 66.67];
        yield 'two of three reaches 66.66' => [2, 3, 66.66, false, 66.67];
        yield 'exactly the required share' => [1, 2, 50.0, false, 50.0];
        yield 'just below 100' => [999, 1000, 100.0, true, 99.9];
        yield 'rounds to 100 but is below' => [99_999, 100_000, 100.0, true, 100.0];
        yield 'all covered' => [3, 3, 100.0, false, 100.0];
        yield 'nothing to cover' => [0, 0, 100.0, false, 100.0];
    }

    /**
     * @param non-negative-int $monitored
     * @param non-negative-int $eligible
     */
    #[DataProvider('thresholdProvider')]
    public function testThresholdIsComparedOnTheExactFraction(int $monitored, int $eligible, float $required, bool $below, float $shownPercent): void
    {
        $kinds = array_map(static fn (CoverageKind $kind): KindCoverage => new KindCoverage($kind, 0, 0, 100.0), CoverageKind::cases());
        $kinds[0] = new KindCoverage(CoverageKind::HttpRoute, $monitored, $eligible, 100.0);
        $output = new Output([], $kinds, 0);

        self::assertSame($below ? [CoverageKind::HttpRoute] : [], $output->belowThreshold([CoverageKind::HttpRoute->value => $required]));
        self::assertSame($shownPercent, $output->of(CoverageKind::HttpRoute)->sloPercent);
    }

    public function testEveryKindMustBeReported(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Coverage of the "operation" kind is missing.'));

        new Output([], [new KindCoverage(CoverageKind::HttpRoute, 0, 0, 100.0), new KindCoverage(CoverageKind::MessengerHandler, 0, 0, 100.0), new KindCoverage(CoverageKind::LogicAction, 0, 0, 100.0)], 0);
    }

    public function testMonitoredCannotExceedEligible(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KindCoverage(CoverageKind::HttpRoute, 2, 1, 100.0);
    }
}

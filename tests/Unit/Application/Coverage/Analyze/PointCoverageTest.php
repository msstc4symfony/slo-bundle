<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Coverage\Analyze;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageStatus;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\PointCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PointCoverageTest extends TestCase
{
    /**
     * @return iterable<string, array{list<non-empty-string>}>
     */
    public static function orderProvider(): iterable
    {
        $names = ['2', '10', '1a', 'b'];
        foreach (self::permutations($names) as $permutation) {
            yield implode(',', $permutation) => [$permutation];
        }
    }

    /**
     * Numeric route names compared as numbers next to other strings make the order depend on the input.
     *
     * @param list<non-empty-string> $routes
     */
    #[DataProvider('orderProvider')]
    public function testOrderIsByKindThenPointAsStringsWhateverTheInputOrder(array $routes): void
    {
        $points = [new PointCoverage('alpha', CoverageKind::Operation, CoverageStatus::Monitored, null, [], false)];
        foreach ($routes as $route) {
            $points[] = new PointCoverage($route, CoverageKind::HttpRoute, CoverageStatus::Monitored, null, [], false);
        }

        usort($points, PointCoverage::compare(...));

        self::assertSame(['10', '1a', '2', 'b', 'alpha'], array_map(static fn (PointCoverage $point): string => $point->point, $points));
    }

    /**
     * @param list<non-empty-string> $items
     *
     * @return list<list<non-empty-string>>
     */
    private static function permutations(array $items): array
    {
        if (\count($items) <= 1) {
            return [$items];
        }
        $all = [];
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach (self::permutations(array_values($rest)) as $permutation) {
                $all[] = [$item, ...$permutation];
            }
        }

        return $all;
    }
}

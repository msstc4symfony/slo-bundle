<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusSetTest extends TestCase
{
    public function testRegexAndContains(): void
    {
        $set = StatusSet::fromList(['5xx', 429]);

        self::assertSame('429|5..', PromQlRenderer::statusPattern($set));
        self::assertTrue($set->contains(503));
        self::assertTrue($set->contains(429));
        self::assertFalse($set->contains(404));
        self::assertFalse($set->isEmpty());
    }

    /**
     * @return iterable<string, array{list<int|string>, list<string>, string|null}>
     */
    public static function provideCanonical(): iterable
    {
        yield 'sorted' => [['5xx', 429], ['429', '5xx'], '429|5..'];
        yield 'deduplicated' => [[500, '500', 500], ['500'], '500'];
        yield 'range as alternation' => [['500-503'], ['500-503'], '500|501|502|503'];
        yield 'uppercase class' => [['5XX'], ['5xx'], '5..'];
        yield 'single range' => [['404-404'], ['404'], '404'];
        yield 'empty' => [[], [], null];
        yield 'code inside class' => [['5xx', '500'], ['5xx'], '5..'];
        yield 'code inside range' => [['500-504', 502], ['500-504'], '500|501|502|503|504'];
        yield 'range inside class' => [['5xx', '500-503'], ['5xx'], '5..'];
        yield 'range inside wider range' => [['500-510', '502-505'], ['500-510'], '500|501|502|503|504|505|506|507|508|509|510'];
        yield 'overlapping ranges kept' => [['500-503', '502-505'], ['500-503', '502-505'], '500|501|502|503|502|503|504|505'];
        yield 'other class kept' => [['5xx', 404], ['404', '5xx'], '404|5..'];
        yield 'class equal to range' => [['5xx', '500-599'], ['5xx'], '5..'];
        yield 'range equal to class' => [['500-599', '5xx'], ['5xx'], '5..'];
        yield 'equal bounds with a contained code' => [['500-599', 503, '5xx'], ['5xx'], '5..'];
        yield 'equal bounds next to another class' => [['400-499', '4xx', 503], ['4xx', '503'], '4..|503'];
    }

    /**
     * @param list<int|string> $items
     * @param list<string> $canonical
     */
    #[DataProvider('provideCanonical')]
    public function testCanonical(array $items, array $canonical, ?string $regex): void
    {
        $set = StatusSet::fromList($items);

        self::assertSame($canonical, $set->canonical());
        self::assertSame($regex, PromQlRenderer::statusPattern($set));
        self::assertSame($items === [], $set->isEmpty());
    }

    public function testCodeBoundsAreInclusive(): void
    {
        $set = StatusSet::fromList([100, 599, '100-102', '597-599']);

        self::assertTrue($set->contains(100));
        self::assertTrue($set->contains(599));
        self::assertFalse($set->contains(99));
        self::assertFalse($set->contains(600));
    }

    public function testRangeContains(): void
    {
        $set = StatusSet::fromList(['500-503']);

        self::assertTrue($set->contains(502));
        self::assertFalse($set->contains(504));
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function provideInvalid(): iterable
    {
        yield '6xx' => ['6xx'];
        yield '0xx' => ['0xx'];
        yield 'reversed range' => ['504-500'];
        yield 'below 100' => [99];
        yield 'above 599' => [600];
        yield 'garbage' => ['abc'];
        yield 'empty string' => [''];
        yield 'range out of bounds' => ['500-700'];
        yield 'range below 100' => ['099-200'];
        yield 'range above 599' => ['500-600'];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(int|string $item): void
    {
        $this->expectException(InvalidArgumentException::class);
        StatusSet::fromList([$item]);
    }

    public function testIntersects(): void
    {
        self::assertTrue(StatusSet::fromList(['5xx'])->intersects(StatusSet::fromList(['500-501'])));
        self::assertFalse(StatusSet::fromList(['5xx'])->intersects(StatusSet::fromList([429])));
        self::assertFalse(StatusSet::fromList(['5xx'])->intersects(StatusSet::fromList([])));
    }

    /**
     * Every pair of these sets, both ways round: touching, nested, disjoint and adjacent bounds, the code range ends.
     *
     * @return iterable<string, array{list<int|string>, list<int|string>}>
     */
    public static function provideSetPairs(): iterable
    {
        $sets = [[], [100], [599], [429], ['4xx'], ['5xx'], ['1xx', '5xx'], ['500-503'], ['503-510'], ['504-599'], ['498-502'], [499], [500], ['400-499'], [404, 429, '502-504'], ['100-599'], ['200-299', 418]];
        foreach ($sets as $left) {
            foreach ($sets as $right) {
                yield json_encode($left) . ' and ' . json_encode($right) => [$left, $right];
            }
        }
    }

    /**
     * @param list<int|string> $left
     * @param list<int|string> $right
     */
    #[DataProvider('provideSetPairs')]
    public function testIntersectsEqualsACodeByCodeComparison(array $left, array $right): void
    {
        $leftSet = StatusSet::fromList($left);
        $rightSet = StatusSet::fromList($right);
        $bruteForce = false;
        for ($code = 100; $code <= 599; $code++) {
            if ($leftSet->contains($code) && $rightSet->contains($code)) {
                $bruteForce = true;

                break;
            }
        }

        self::assertSame($bruteForce, $leftSet->intersects($rightSet));
    }

    public function testRedundantDeclarationsAreEqual(): void
    {
        self::assertSame(StatusSet::fromList(['5xx'])->canonical(), StatusSet::fromList([500, '5xx', '501-503'])->canonical());
    }
}

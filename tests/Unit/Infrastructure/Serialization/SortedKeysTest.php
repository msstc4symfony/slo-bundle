<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Serialization;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Infrastructure\Serialization\SortedKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SortedKeysTest extends TestCase
{
    public function testSortsMapsRecursivelyAndKeepsListOrder(): void
    {
        self::assertSame(
            ['10' => true, '9' => false, 'a' => ['x' => 1, 'y' => [3, 1, 2]], 'b' => [['c' => 1, 'd' => 2], ['e' => 1]]],
            SortedKeys::of(['b' => [['d' => 2, 'c' => 1], ['e' => 1]], '9' => false, 'a' => ['y' => [3, 1, 2], 'x' => 1], '10' => true]),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, non-negative-int}>
     */
    public static function provideListKeyedMaps(): iterable
    {
        yield 'nested' => [['outer' => [1 => 'b', 0 => 'a']], 1];
        yield 'top level' => [[1 => 'b', 0 => 'a'], 1];
        yield 'eleven keys, which string sorting would not turn into a list' => [array_reverse(range(0, 10), true), 10];
    }

    /**
     * @param array<array-key, mixed> $map
     * @param non-negative-int $last
     */
    #[DataProvider('provideListKeyedMaps')]
    public function testRejectsAMapKeyedLikeAListOutOfOrderWhateverItsSize(array $map, int $last): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keys 0..' . $last);

        SortedKeys::of($map);
    }

    public function testSparseIntegerKeysStayAMap(): void
    {
        self::assertSame([0 => 'b', 2 => 'a'], SortedKeys::of([2 => 'a', 0 => 'b']));
        self::assertSame('{"0":"b","2":"a"}', json_encode(SortedKeys::of([2 => 'a', 0 => 'b'])));
    }
}

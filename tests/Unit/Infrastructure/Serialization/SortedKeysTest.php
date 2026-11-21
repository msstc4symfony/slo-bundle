<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Serialization;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Infrastructure\Serialization\SortedKeys;
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

    public function testRejectsAMapThatSortingWouldTurnIntoAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keys 0..1');

        SortedKeys::of(['outer' => [1 => 'b', 0 => 'a']]);
    }
}

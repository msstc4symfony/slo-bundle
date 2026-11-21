<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Clock;

use Msstc4Symfony\SloBundle\Infrastructure\Clock\HrtimeClock;
use PHPUnit\Framework\TestCase;

final class HrtimeClockTest extends TestCase
{
    public function testReadsTheMonotonicClockInNanoseconds(): void
    {
        $clock = new HrtimeClock();

        $before = hrtime(true);
        $first = $clock->now();
        $second = $clock->now();
        $after = hrtime(true);

        self::assertGreaterThanOrEqual($before, $first);
        self::assertGreaterThanOrEqual($first, $second);
        self::assertLessThanOrEqual($after, $second);
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Clock;

use Msstc4Symfony\SloBundle\Infrastructure\Clock\HrtimeClock;
use PHPUnit\Framework\TestCase;

final class HrtimeClockTest extends TestCase
{
    public function testIsMonotonic(): void
    {
        $clock = new HrtimeClock();
        $first = $clock->now();

        self::assertGreaterThanOrEqual($first, $clock->now());
    }
}
